<?php

namespace App\Services\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidDocumentStateException;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptCost;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockLocation;
use App\Models\SupplierTransaction;
use App\Models\User;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Inventory\BatchResolver;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockPosting;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Goods Received Notes: the ONLY way purchased stock enters inventory.
 *
 * post() runs in ONE transaction and, in this order:
 *   1. locks the GRN; refuses unless it is still a draft  (no double posting)
 *   2. checks permission (purchases.receive) and branch access
 *   3. locks the purchase order; it must be approved / partially received
 *   4. locks each PO line; re-validates units, conversion and remaining qty
 *   5. re-allocates landed costs over the received base quantities
 *   6. resolves batches/expiry and posts stock via StockLedger at the
 *      LANDED cost per base unit (immutable movements + balances + WAC)
 *   7. updates PO received quantities and status
 *   8. records the supplier liability (goods + tax only, never landed costs)
 *      and the product's latest SUPPLIER purchase cost
 *   9. marks the GRN posted
 * Any failure rolls back everything.
 *
 * Money per line:
 *   goods_value  = round2(quantity x unit_cost)          supplier price, net of PO discount, excl. tax
 *   tax_amount   = round2(goods_value x PO tax rate)
 *   line_total   = goods_value + tax_amount               owed to the supplier
 *   landed_cost_amount  = share of additional costs      (ReceiptCalculator)
 *   landed_unit_cost_base = (goods_value + landed) / quantity_base   inventory cost
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly UnitConversionService $conversions,
        private readonly BatchResolver $batches,
        private readonly StockLedger $ledger,
        private readonly PurchaseOrderService $orders,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
        private readonly ReceiptCalculator $calculator,
    ) {}

    /**
     * @param  array{location_id: int, received_date: string, supplier_reference?: ?string, delivery_note_number?: ?string,
     *     supplier_invoice_number?: ?string, notes?: ?string, submission_key?: ?string}  $header
     * @param  list<array{purchase_order_line_id: int, product_unit_id?: int, quantity: string, unit_cost?: ?string, location_id?: ?int,
     *     batch_number?: ?string, manufactured_on?: ?string, expires_on?: ?string, notes?: ?string}>  $lines  Empty/zero quantities are skipped.
     * @param  list<array{cost_type: string, description?: ?string, amount: string}>  $costs  Additional (landed) costs, excl. VAT.
     */
    public function createDraft(PurchaseOrder $order, array $header, array $lines, User $actor, array $costs = []): GoodsReceipt
    {
        $this->authorizer->permission($actor, 'purchases.receive');
        $company = Company::query()->findOrFail($order->company_id);
        $this->subscription->ensureWritable($company, 'lines');

        return DB::transaction(function () use ($company, $order, $header, $lines, $actor, $costs) {
            $order = $this->orders->lock($order);
            $this->authorizer->branch($actor, $order->branch_id);

            if (! $order->status->canReceive()) {
                throw InvalidDocumentStateException::make('status', __('Goods can only be received against approved purchase orders.'));
            }

            $location = $this->location($company, $order, $header['location_id'] ?? null, 'location_id');

            if (($header['received_date'] ?? '') === '' || $header['received_date'] > today()->toDateString()) {
                throw BusinessRuleException::make('received_date', __('Enter a received date that is not in the future.'));
            }

            $delivery = $this->reference($header['delivery_note_number'] ?? null);
            $invoice = $this->reference($header['supplier_invoice_number'] ?? null);

            $receipt = new GoodsReceipt;
            $receipt->forceFill([
                'company_id' => $company->id,
                'branch_id' => $order->branch_id,
                'location_id' => $location->id,
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'reference' => $this->numbers->next($company, DocumentNumberGenerator::GOODS_RECEIPT),
                'submission_key' => $header['submission_key'] ?? null,
                'status' => GoodsReceiptStatus::Draft,
                'received_date' => $header['received_date'],
                // Legacy single reference kept for lists/search: invoice, else delivery note.
                'supplier_reference' => $invoice ?? $delivery ?? $this->reference($header['supplier_reference'] ?? null),
                'delivery_note_number' => $delivery,
                'supplier_invoice_number' => $invoice,
                'notes' => $header['notes'] ?? null,
                'created_by' => $actor->id,
            ])->save();

            $count = 0;
            foreach (array_values($lines) as $i => $line) {
                if (! filled($line['quantity'] ?? null) || (Decimal::isValid((string) $line['quantity']) && Decimal::isZero((string) $line['quantity']))) {
                    continue;
                }

                $this->addLine($receipt, $order, $company, $line, "lines.{$i}");
                $count++;
            }

            if ($count === 0) {
                throw BusinessRuleException::make('lines', __('Enter the quantity received for at least one product.'));
            }

            foreach (array_values($costs) as $i => $cost) {
                $this->addCost($receipt, $cost, "costs.{$i}");
            }

            $this->allocateAndTotal($receipt);
            $receipt->recordEvent('created', null, GoodsReceiptStatus::Draft->value, $actor);

            return $receipt;
        });
    }

    /**
     * Create and post in one atomic step (the receiving screen action).
     *
     * - Idempotent per $header['submission_key']: a repeated submission
     *   (double click, network retry) returns the GRN already created.
     * - Quick Receive: users holding purchases.quick_receive may receive a
     *   draft or submitted order directly; the order is approved inside the
     *   same transaction and the approval is recorded in the audit trail.
     */
    public function receive(PurchaseOrder $order, array $header, array $lines, User $actor, array $costs = []): GoodsReceipt
    {
        $key = $header['submission_key'] ?? null;

        if ($key !== null && ($existing = $this->bySubmissionKey($order->company_id, $key)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($order, $header, $lines, $actor, $costs) {
                $current = $this->orders->lock($order);

                // Without purchases.quick_receive, createDraft() refuses unapproved orders as before.
                if (in_array($current->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Submitted], true)
                    && $this->authorizer->can($actor, 'purchases.quick_receive')) {
                    $this->orders->quickApprove($current, $actor);
                }

                return $this->post($this->createDraft($current, $header, $lines, $actor, $costs), $actor);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same submission key committed first.
            if ($key !== null && ($existing = $this->bySubmissionKey($order->company_id, $key)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    public function post(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        $this->authorizer->permission($actor, 'purchases.receive');

        return DB::transaction(function () use ($receipt, $actor) {
            // 1. Lock the GRN and re-read its status: a second post sees "posted".
            $receipt = GoodsReceipt::forCompany($receipt->company_id)->lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw InvalidDocumentStateException::make('status', __('Goods receipt :ref has already been :status.', [
                    'ref' => $receipt->reference, 'status' => __($receipt->status->label()),
                ]));
            }

            // 2. Permission + branch.
            $this->authorizer->branch($actor, $receipt->branch_id);
            $company = Company::query()->findOrFail($receipt->company_id);
            $this->subscription->ensureWritable($company, 'status');

            // 3. Purchase order eligibility.
            $order = $this->orders->lock(PurchaseOrder::forCompany($company)->findOrFail($receipt->purchase_order_id));

            if (! $order->status->canReceive()) {
                throw InvalidDocumentStateException::make('status', __('Purchase order :ref is :status and cannot receive goods.', [
                    'ref' => $order->reference, 'status' => __($order->status->label()),
                ]));
            }

            // Post in product order (deadlock avoidance across concurrent postings).
            $lines = GoodsReceiptLine::forCompany($company)->where('goods_receipt_id', $receipt->id)
                ->orderBy('product_id')->orderBy('id')->get();

            // 4. Lock and re-validate every line against current state.
            $prepared = [];
            foreach ($lines as $i => $line) {
                $orderLine = PurchaseOrderLine::forCompany($company)->where('purchase_order_id', $order->id)
                    ->lockForUpdate()->findOrFail($line->purchase_order_line_id);
                $product = Product::forCompany($company)->findOrFail($line->product_id);
                $unit = ProductUnit::forCompany($company)->where('product_id', $product->id)->findOrFail($line->product_unit_id);
                $location = $this->location($company, $order, $line->location_id ?? $receipt->location_id, "lines.{$i}.location_id");

                $quantityBase = $this->conversions->toBase($product, $unit, (string) $line->quantity, "lines.{$i}.quantity");

                // Several lines may receive against the same PO line: count what earlier lines in THIS receipt take.
                $alreadyThisReceipt = $prepared[$orderLine->id]['base'] ?? '0';
                $remaining = Decimal::sub($orderLine->remainingBase(), $alreadyThisReceipt);

                if (Decimal::cmp($quantityBase, $remaining) > 0) {
                    throw BusinessRuleException::make("lines.{$i}.quantity", __('Receiving :qty :base of :product exceeds the :remaining still outstanding on the order.', [
                        'qty' => Decimal::trim($quantityBase), 'base' => $product->baseUnit->name,
                        'product' => $product->name, 'remaining' => Decimal::trim(Decimal::max($remaining, '0')),
                    ]));
                }

                $prepared[$orderLine->id]['base'] = Decimal::add($alreadyThisReceipt, $quantityBase, Decimal::QTY);
                $line->forceFill([
                    'conversion_factor' => $unit->factor,   // snapshots at posting time
                    'quantity_base' => $quantityBase,
                    'unit_cost_base' => Decimal::round(Decimal::div((string) $line->unit_cost, $unit->factor), Decimal::COST),
                ]);
                $line->setRelation('postingContext', collect(compact('orderLine', 'product', 'unit', 'location')));
            }

            // 5. Landed costs over the re-validated base quantities.
            $this->allocateAndTotal($receipt, $lines);

            foreach ($lines as $i => $line) {
                ['orderLine' => $orderLine, 'product' => $product, 'unit' => $unit, 'location' => $location] = $line->getRelation('postingContext')->all();
                $line->unsetRelation('postingContext');

                // 6. Batch + stock posting at landed cost per base unit.
                $batch = $this->batches->resolve(
                    $product, $line->batch_number, $line->expires_on?->toDateString(), $line->manufactured_on?->toDateString(), "lines.{$i}.batch_number",
                );
                $line->forceFill(['batch_id' => $batch?->id])->save();

                $this->ledger->post(new StockPosting(
                    type: StockMovementType::GoodsReceipt,
                    location: $location,
                    product: $product,
                    quantityBase: (string) $line->quantity_base,
                    source: $line,
                    reference: $receipt->reference,
                    batch: $batch,
                    unitCost: (string) $line->landed_unit_cost_base,
                    productUnit: $unit,
                    enteredQuantity: (string) $line->quantity,
                    conversionFactor: $unit->factor,
                    user: $actor,
                    occurredAt: now(),
                ));

                // 7. Received quantity on the PO line (re-read: earlier lines may share it).
                PurchaseOrderLine::forCompany($company)->whereKey($orderLine->id)->update([
                    'received_quantity_base' => DB::raw('received_quantity_base + '.Decimal::normalize((string) $line->quantity_base, Decimal::QTY)),
                    'updated_at' => now(),
                ]);

                // 8a. Latest SUPPLIER purchase cost (landed costs are kept separate).
                Product::forCompany($company)->whereKey($product->id)->update(['purchase_cost' => $line->unit_cost_base]);
            }

            $fullyReceived = ! PurchaseOrderLine::forCompany($company)->where('purchase_order_id', $order->id)
                ->whereColumn('received_quantity_base', '<', 'quantity_base')->exists();

            $this->orders->transition($order, $fullyReceived ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived, $actor, notes: $receipt->reference);

            // 8b. Supplier liability: goods + tax only (never landed costs), idempotent by source.
            $liability = new SupplierTransaction;
            $liability->forceFill([
                'company_id' => $company->id,
                'supplier_id' => $receipt->supplier_id,
                'type' => 'goods_receipt',
                'amount' => $receipt->total,
                'source_type' => $receipt->getMorphClass(),
                'source_id' => $receipt->id,
                'reference' => $receipt->reference,
                'occurred_at' => now(),
                'created_by' => $actor->id,
            ])->save();

            // 9. Done.
            $receipt->forceFill(['status' => GoodsReceiptStatus::Posted, 'posted_by' => $actor->id, 'posted_at' => now()])->save();
            $receipt->recordEvent('posted', 'draft', 'posted', $actor);

            return $receipt;
        });
    }

    public function cancelDraft(GoodsReceipt $receipt, User $actor): void
    {
        $this->authorizer->permission($actor, 'purchases.receive');

        DB::transaction(function () use ($receipt, $actor) {
            $receipt = GoodsReceipt::forCompany($receipt->company_id)->lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw InvalidDocumentStateException::make('status', __('Only draft receipts can be cancelled.'));
            }

            $receipt->forceFill(['status' => GoodsReceiptStatus::Cancelled])->save();
            $receipt->recordEvent('cancelled', 'draft', 'cancelled', $actor);
        });
    }

    /** Next GRN number for display only (assigned for real when the receipt is created). */
    public function nextReference(Company $company): string
    {
        $next = DocumentSequence::forCompany($company)->where('key', DocumentNumberGenerator::GOODS_RECEIPT)->value('next_number') ?? 1;

        return sprintf('GRN-%06d', $next);
    }

    private function bySubmissionKey(int $companyId, string $key): ?GoodsReceipt
    {
        return GoodsReceipt::forCompany($companyId)->where('submission_key', $key)->first();
    }

    /** A receiving location must be an active location of the ORDER's branch (same company). */
    private function location(Company $company, PurchaseOrder $order, mixed $locationId, string $field): StockLocation
    {
        return StockLocation::forCompany($company)->where('branch_id', $order->branch_id)->where('is_active', true)->find($locationId ?? 0)
            ?? throw BusinessRuleException::make($field, __('Select a stock location in the order\'s branch.'));
    }

    private function reference(?string $value): ?string
    {
        $value = trim((string) $value);

        if (mb_strlen($value) > 64) {
            throw BusinessRuleException::make('supplier_invoice_number', __('References may be at most 64 characters.'));
        }

        return $value === '' ? null : $value;
    }

    private function addCost(GoodsReceipt $receipt, array $cost, string $field): void
    {
        $type = (string) ($cost['cost_type'] ?? '');
        $amount = str_replace(',', '', trim((string) ($cost['amount'] ?? '')));

        if ($amount === '' || (Decimal::isValid($amount) && Decimal::isZero($amount))) {
            return; // empty rows are ignored
        }

        if (! array_key_exists($type, config('inventory.landed_cost_types'))) {
            throw BusinessRuleException::make("{$field}.cost_type", __('Select a valid cost type.'));
        }

        if (! Decimal::isValid($amount) || Decimal::isNegative($amount) || Decimal::scaleOf($amount) > Decimal::MONEY) {
            throw BusinessRuleException::make("{$field}.amount", __('Enter a valid non-negative amount.'));
        }

        $record = new GoodsReceiptCost;
        $record->forceFill([
            'company_id' => $receipt->company_id,
            'goods_receipt_id' => $receipt->id,
            'cost_type' => $type,
            'description' => filled($cost['description'] ?? null) ? mb_substr(trim($cost['description']), 0, 150) : null,
            'amount' => Decimal::normalize($amount, Decimal::MONEY),
        ])->save();
    }

    /**
     * Allocate the receipt's additional costs over its lines (by base
     * quantity) and refresh line/receipt totals. Persists the lines.
     */
    private function allocateAndTotal(GoodsReceipt $receipt, ?Collection $lines = null): void
    {
        $lines ??= GoodsReceiptLine::forCompany($receipt->company_id)->where('goods_receipt_id', $receipt->id)->orderBy('id')->get();
        $costTotal = (string) (GoodsReceiptCost::forCompany($receipt->company_id)->where('goods_receipt_id', $receipt->id)->sum('amount') ?: '0');

        $shares = $this->calculator->allocate($costTotal, $lines->mapWithKeys(fn ($l) => [$l->id => (string) $l->quantity_base])->all());

        $goods = '0';
        $total = '0';
        foreach ($lines as $line) {
            $rate = (string) PurchaseOrderLine::forCompany($receipt->company_id)->whereKey($line->purchase_order_line_id)->value('tax_rate');
            $value = Decimal::round(Decimal::mul((string) $line->quantity, (string) $line->unit_cost), Decimal::MONEY);
            $tax = Decimal::round(Decimal::div(Decimal::mul($value, $rate ?: '0'), '100'), Decimal::MONEY);
            $landed = $shares[$line->id] ?? '0.00';

            $line->forceFill([
                'goods_value' => $value,
                'tax_amount' => $tax,
                'line_total' => Decimal::add($value, $tax, Decimal::MONEY),
                'landed_cost_amount' => $landed,
                'landed_unit_cost_base' => $this->calculator->landedUnitCost($value, $landed, (string) $line->quantity_base),
            ]);

            $relations = $line->getRelations();
            $line->setRelations([]);
            $line->save();
            $line->setRelations($relations);

            $goods = Decimal::add($goods, $value, Decimal::MONEY);
            $total = Decimal::add($total, $line->line_total, Decimal::MONEY);
        }

        $receipt->forceFill([
            'goods_value' => $goods,
            'landed_cost_total' => Decimal::round($costTotal, Decimal::MONEY),
            'total' => $total,
        ])->save();
    }

    private function addLine(GoodsReceipt $receipt, PurchaseOrder $order, Company $company, array $line, string $field): GoodsReceiptLine
    {
        $orderLine = PurchaseOrderLine::forCompany($order->company_id)->where('purchase_order_id', $order->id)
            ->find($line['purchase_order_line_id'] ?? 0)
            ?? throw BusinessRuleException::make("{$field}.purchase_order_line_id", __('This line is not part of the purchase order.'));

        $product = Product::forCompany($order->company_id)->findOrFail($orderLine->product_id);

        $unit = ProductUnit::forCompany($order->company_id)->where('product_id', $product->id)->where('is_active', true)
            ->find($line['product_unit_id'] ?? $orderLine->product_unit_id)
            ?? throw BusinessRuleException::make("{$field}.product_unit_id", __('Select a valid unit for :product.', ['product' => $product->name]));

        $location = filled($line['location_id'] ?? null)
            ? $this->location($company, $order, $line['location_id'], "{$field}.location_id")
            : null;

        $quantity = (string) $line['quantity'];
        $quantityBase = $this->conversions->toBase($product, $unit, $quantity, "{$field}.quantity");

        if (Decimal::cmp($quantityBase, $orderLine->remainingBase()) > 0) {
            throw BusinessRuleException::make("{$field}.quantity", __('Only :remaining :base of :product are still outstanding.', [
                'remaining' => Decimal::trim($orderLine->remainingBase()), 'base' => $product->baseUnit->name, 'product' => $product->name,
            ]));
        }

        // Default cost: the order's net (post-discount, pre-tax) cost converted to this unit.
        $rawCost = str_replace(',', '', trim((string) ($line['unit_cost'] ?? '')));
        $unitCost = $rawCost === ''
            ? Decimal::round(Decimal::mul($orderLine->netUnitCostBase(), $unit->factor), Decimal::COST)
            : $rawCost;

        if (! Decimal::isValid($unitCost) || Decimal::isNegative($unitCost) || Decimal::scaleOf($unitCost) > Decimal::COST) {
            throw BusinessRuleException::make("{$field}.unit_cost", __('Enter a valid unit cost.'));
        }

        // Validate batch/expiry input early (resolution happens at posting).
        if ($product->track_batches && ! filled($line['batch_number'] ?? null)) {
            throw BusinessRuleException::make("{$field}.batch_number", __(':product requires a batch number.', ['product' => $product->name]));
        }

        if ($product->track_expiry && ! filled($line['expires_on'] ?? null)) {
            throw BusinessRuleException::make("{$field}.expires_on", __(':product requires an expiry date.', ['product' => $product->name]));
        }

        $record = new GoodsReceiptLine;
        $record->forceFill([
            'company_id' => $order->company_id,
            'goods_receipt_id' => $receipt->id,
            'purchase_order_id' => $order->id,
            'purchase_order_line_id' => $orderLine->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'location_id' => $location?->id,
            'conversion_factor' => $unit->factor,
            'quantity' => Decimal::normalize($quantity, Decimal::QTY),
            'quantity_base' => $quantityBase,
            'unit_cost' => Decimal::normalize($unitCost, Decimal::COST),
            'unit_cost_base' => Decimal::round(Decimal::div($unitCost, $unit->factor), Decimal::COST),
            'line_total' => '0',   // set by allocateAndTotal()
            'batch_number' => filled($line['batch_number'] ?? null) ? strtoupper(trim($line['batch_number'])) : null,
            'manufactured_on' => ($line['manufactured_on'] ?? null) ?: null,
            'expires_on' => ($line['expires_on'] ?? null) ?: null,
            'notes' => filled($line['notes'] ?? null) ? mb_substr(trim($line['notes']), 0, 500) : null,
        ])->save();

        return $record;
    }
}
