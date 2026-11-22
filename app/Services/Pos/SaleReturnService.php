<?php

namespace App\Services\Pos;

use App\Enums\LocationType;
use App\Enums\PaymentMethodType;
use App\Enums\ReturnDisposition;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemAllocation;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\Catalogue\CompanyDefaultsProvisioner;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockPosting;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns (refunds) against an original sale. ONE transaction,
 * idempotent per client UUID; the original sale is locked so concurrent
 * returns cannot exceed what was sold.
 *
 * - Quantities are converted with the SALE LINE's conversion snapshot.
 * - Refund per line is proportional to the original line total (incl.
 *   discount and tax); the final return of a line refunds the exact
 *   remainder, so total refunds never exceed what was charged.
 * - Stock comes back at the ORIGINAL sale cost of the lot (COGS reversed
 *   at that cost): fit-for-resale items into a sellable location, damaged
 *   or expired items into the branch's non-sellable Quarantine location.
 * - Expired lots can never be restocked as sellable.
 * - The original sale is never modified; returns are separate documents.
 */
class SaleReturnService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly PosRegisterResolver $registers,
        private readonly DocumentNumberGenerator $numbers,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
        private readonly CompanyDefaultsProvisioner $provisioner,
    ) {}

    /**
     * @param  list<array{sale_item_id: int, quantity: string, disposition: string}>  $items  quantity in the SALE line's unit
     */
    public function process(Sale $sale, User $actor, string $clientUuid, string $reason, int $refundMethodId, array $items, ?string $refundReference = null): SaleReturn
    {
        $this->authorizer->permission($actor, 'sales.refund');
        $this->authorizer->branch($actor, $sale->branch_id);
        $company = Company::query()->findOrFail($sale->company_id);
        $this->subscription->ensureWritable($company, 'items');

        if (($existing = SaleReturn::forCompany($company)->where('client_uuid', strtolower($clientUuid))->first()) !== null) {
            return $existing;
        }

        if (trim($reason) === '') {
            throw BusinessRuleException::make('reason', __('Enter the reason for the return.'));
        }

        $method = PaymentMethod::forCompany($company)->where('is_active', true)->find($refundMethodId)
            ?? throw BusinessRuleException::make('refund_method', __('Select a valid refund method.'));

        try {
            return DB::transaction(function () use ($company, $sale, $actor, $clientUuid, $reason, $method, $items, $refundReference) {
                // Serialises every return of this sale.
                $sale = Sale::forCompany($company)->lockForUpdate()->findOrFail($sale->id);
                $branch = Branch::withoutTenancy()->where('company_id', $company->id)->findOrFail($sale->branch_id);

                $plan = [];
                $ids = array_filter(array_map(fn ($r) => filled($r['quantity'] ?? null) ? (int) ($r['sale_item_id'] ?? 0) : null, $items));
                if (count($ids) !== count(array_unique($ids))) {
                    throw BusinessRuleException::make('items', __('Each sale line may appear only once per return.'));
                }

                foreach (array_values($items) as $i => $row) {
                    if (! filled($row['quantity'] ?? null) || (Decimal::isValid((string) $row['quantity']) && Decimal::isZero((string) $row['quantity']))) {
                        continue;
                    }

                    $item = SaleItem::forCompany($company)->where('sale_id', $sale->id)->find($row['sale_item_id'] ?? 0)
                        ?? throw BusinessRuleException::make("items.{$i}", __('This item is not part of the sale.'));
                    $disposition = ReturnDisposition::tryFrom((string) ($row['disposition'] ?? ''))
                        ?? throw BusinessRuleException::make("items.{$i}.disposition", __('Select what happens to the returned goods.'));

                    $plan[] = $this->planItem($company, $branch, $sale, $item, (string) $row['quantity'], $disposition, "items.{$i}");
                }

                if ($plan === []) {
                    throw BusinessRuleException::make('items', __('Enter the quantity returned for at least one item.'));
                }

                $return = new SaleReturn;
                $return->forceFill([
                    'company_id' => $company->id,
                    'branch_id' => $sale->branch_id,
                    'sale_id' => $sale->id,
                    'return_number' => $this->numbers->next($company, DocumentNumberGenerator::SALE_RETURN),
                    'client_uuid' => strtolower($clientUuid),
                    'processed_by' => $actor->id,
                    'reason' => mb_substr(trim($reason), 0, 255),
                    'refund_method_id' => $method->id,
                    'refund_method_name' => $method->name,
                    'refund_method_type' => $method->type->value,
                    'refund_reference' => $refundReference ? mb_substr($refundReference, 0, 100) : null,
                    'refund_total' => array_reduce($plan, fn ($c, $p) => Decimal::add($c, $p['refund'], Decimal::MONEY), '0.00'),
                    'tax_total' => array_reduce($plan, fn ($c, $p) => Decimal::add($c, $p['tax'], Decimal::MONEY), '0.00'),
                    'net_total' => array_reduce($plan, fn ($c, $p) => Decimal::add($c, Decimal::sub($p['refund'], $p['tax']), Decimal::MONEY), '0.00'),
                    'cost_total' => Decimal::round(array_reduce($plan, fn ($c, $p) => array_reduce($p['parts'], fn ($cc, $x) => Decimal::add($cc, $x['cost']), $c), '0'), Decimal::COST),
                    'returned_at' => now(),
                ])->save();

                usort($plan, fn ($a, $b) => $a['item']->product_id <=> $b['item']->product_id);
                foreach ($plan as $p) {
                    $product = Product::forCompany($company)->findOrFail($p['item']->product_id);
                    foreach ($p['parts'] as $part) {
                        $returnItem = new SaleReturnItem;
                        $returnItem->forceFill([
                            'company_id' => $company->id,
                            'sale_return_id' => $return->id,
                            'sale_item_id' => $p['item']->id,
                            'sale_item_allocation_id' => $part['allocation']->id,
                            'product_id' => $product->id,
                            'batch_id' => $part['allocation']->batch_id,
                            'location_id' => $p['location']->id,
                            'disposition' => $p['disposition'],
                            'quantity_base' => $part['quantity_base'],
                            'refund_amount' => $part['refund'],
                            'tax_amount' => $part['tax'],
                            'unit_cost' => $part['allocation']->unit_cost,
                            'cost_amount' => $part['cost'],
                        ])->save();

                        $this->ledger->post(new StockPosting(
                            type: StockMovementType::SaleReturn,
                            location: $p['location'],
                            product: $product,
                            quantityBase: $part['quantity_base'],
                            source: $returnItem,
                            reference: $return->return_number,
                            batch: $part['allocation']->batch_id ? StockBatch::forCompany($company)->find($part['allocation']->batch_id) : null,
                            unitCost: (string) $part['allocation']->unit_cost,   // original COGS
                            productUnit: ProductUnit::forCompany($company)->find($p['item']->product_unit_id),
                            conversionFactor: (string) $p['item']->conversion_factor,
                            user: $actor,
                        ));
                    }
                }

                return $return;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            return SaleReturn::forCompany($company)->where('client_uuid', strtolower($clientUuid))->first() ?? throw $e;
        }
    }

    /** Base quantity of a sale line still returnable. */
    public function returnable(SaleItem $item): string
    {
        $returned = (string) (SaleReturnItem::forCompany($item->company_id)->where('sale_item_id', $item->id)->sum('quantity_base') ?: '0');

        return Decimal::max(Decimal::round(Decimal::sub((string) $item->quantity_base, $returned), Decimal::QTY), '0.0000');
    }

    private function planItem(Company $company, Branch $branch, Sale $sale, SaleItem $item, string $quantity, ReturnDisposition $disposition, string $field): array
    {
        if (! Decimal::isValid($quantity) || ! Decimal::isPositive($quantity) || Decimal::scaleOf($quantity) > Decimal::QTY) {
            throw BusinessRuleException::make("{$field}.quantity", __('Enter a valid quantity.'));
        }

        // The sale line's own conversion snapshot, never today's factor.
        $base = Decimal::round(Decimal::mul($quantity, (string) $item->conversion_factor), Decimal::QTY);
        $product = Product::forCompany($company)->findOrFail($item->product_id);
        if ($product->product_type->requiresWholeBaseQuantities() && ! Decimal::isWhole($base)) {
            throw BusinessRuleException::make("{$field}.quantity", __(':product is counted in whole :base.', ['product' => $item->product_name, 'base' => $product->baseUnit->name]));
        }

        $returnable = $this->returnable($item);
        if (Decimal::cmp($base, $returnable) > 0) {
            throw BusinessRuleException::make("{$field}.quantity", __('Only :qty of :product can still be returned.', [
                'qty' => Decimal::trim(Decimal::div($returnable, (string) $item->conversion_factor, Decimal::QTY)), 'product' => $item->product_name,
            ]));
        }

        $location = $this->location($company, $branch, $sale, $disposition, "{$field}.disposition");

        // Money: proportional, exact remainder on the final return of the line.
        $refunded = (string) (SaleReturnItem::forCompany($company)->where('sale_item_id', $item->id)->sum('refund_amount') ?: '0');
        $taxRefunded = (string) (SaleReturnItem::forCompany($company)->where('sale_item_id', $item->id)->sum('tax_amount') ?: '0');
        $final = Decimal::cmp($base, $returnable) === 0;
        $refund = $final ? Decimal::sub((string) $item->line_total, $refunded, Decimal::MONEY)
            : Decimal::round(Decimal::div(Decimal::mul((string) $item->line_total, $base), (string) $item->quantity_base), Decimal::MONEY);
        $tax = $final ? Decimal::sub((string) $item->tax_amount, $taxRefunded, Decimal::MONEY)
            : Decimal::round(Decimal::div(Decimal::mul((string) $item->tax_amount, $base), (string) $item->quantity_base), Decimal::MONEY);

        // Split over the original lot allocations (latest first).
        $parts = [];
        $left = $base;
        $allocations = SaleItemAllocation::forCompany($company)->where('sale_item_id', $item->id)->orderByDesc('id')->get();
        foreach ($allocations as $allocation) {
            if (! Decimal::isPositive($left)) {
                break;
            }
            $already = (string) (SaleReturnItem::forCompany($company)->where('sale_item_allocation_id', $allocation->id)->sum('quantity_base') ?: '0');
            $free = Decimal::sub((string) $allocation->quantity_base, $already);
            $take = Decimal::cmp($free, $left) >= 0 ? $left : $free;
            if (! Decimal::isPositive($take)) {
                continue;
            }

            if ($disposition === ReturnDisposition::Restock && $allocation->batch_id
                && ($batch = StockBatch::forCompany($company)->find($allocation->batch_id))?->isExpired()) {
                throw BusinessRuleException::make("{$field}.disposition", __('Batch :batch has expired and cannot be restocked for sale.', ['batch' => $batch->batch_number]));
            }

            $parts[] = ['allocation' => $allocation, 'quantity_base' => Decimal::round($take, Decimal::QTY), 'cost' => Decimal::round(Decimal::mul($take, (string) $allocation->unit_cost), Decimal::COST)];
            $left = Decimal::sub($left, $take);
        }

        // Distribute the line's refund/tax over the parts (exact sums).
        $refundLeft = $refund;
        $taxLeft = $tax;
        foreach ($parts as $k => $part) {
            $last = $k === array_key_last($parts);
            $parts[$k]['refund'] = $last ? $refundLeft : Decimal::round(Decimal::div(Decimal::mul($refund, $part['quantity_base']), $base), Decimal::MONEY);
            $parts[$k]['tax'] = $last ? $taxLeft : Decimal::round(Decimal::div(Decimal::mul($tax, $part['quantity_base']), $base), Decimal::MONEY);
            $refundLeft = Decimal::sub($refundLeft, $parts[$k]['refund'], Decimal::MONEY);
            $taxLeft = Decimal::sub($taxLeft, $parts[$k]['tax'], Decimal::MONEY);
        }

        return compact('item', 'disposition', 'location', 'refund', 'tax', 'parts');
    }

    private function location(Company $company, Branch $branch, Sale $sale, ReturnDisposition $disposition, string $field): StockLocation
    {
        if (! $disposition->isSellable()) {
            $this->provisioner->provisionBranch($branch); // ensures the Quarantine location exists

            return StockLocation::forCompany($company)->where('branch_id', $branch->id)
                ->where('type', LocationType::Quarantine->value)->where('is_active', true)->firstOrFail();
        }

        $original = StockLocation::forCompany($company)->where('branch_id', $branch->id)->where('is_active', true)
            ->where('is_sellable', true)->find($sale->location_id);

        return $original ?? $this->registers->sellingLocation($branch, null)
            ?? throw BusinessRuleException::make($field, __('No sellable location is available for restocking.'));
    }

    /** Refund methods allowed for returns (exchange credit included). */
    public static function isExchange(SaleReturn $return): bool
    {
        return $return->refund_method_type === PaymentMethodType::ExchangeCredit->value;
    }
}
