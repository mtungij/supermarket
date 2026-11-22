<?php

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\PosDevice;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemAllocation;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\Catalogue\UnitConversionService;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockPosting;
use App\Services\Support\Authorizer;
use App\Services\Support\DocumentNumberGenerator;
use App\Services\Support\SubscriptionGuard;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Online POS checkout. ONE transaction; idempotent per client UUID.
 *
 *  1. permission (pos.access + sales.create; discounts need sales.discount),
 *     branch access, active subscription, registered device (if required)
 *  2. every line is re-validated and RE-PRICED server-side (client totals
 *     are never trusted), tax from the product's tax class
 *  3. payments validated: no underpayment, no zero/negative amounts,
 *     non-cash <= amount due, change only from cash
 *  4. per product (sorted by id): lock the branch cost row, allocate the
 *     quantity from the SELLING location's non-expired lots in FEFO order,
 *     refuse if insufficient
 *  5. immutable sale + items (snapshots) + allocations + payments
 *  6. stock movements via StockLedger (type `sale`, at weighted-average cost
 *     = COGS), balances and WAC updated under the same locks
 * Any failure rolls back everything. A repeated request with the same
 * client UUID returns the sale already recorded.
 *
 * No shift is involved: the sale records company, branch, device, cashier
 * and timestamp.
 */
class CheckoutService
{
    public function __construct(
        private readonly PosCatalog $catalog,
        private readonly PosRegisterResolver $registers,
        private readonly CartCalculator $calculator,
        private readonly UnitConversionService $conversions,
        private readonly StockLedger $ledger,
        private readonly DocumentNumberGenerator $numbers,
        private readonly SubscriptionGuard $subscription,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * @param  list<array{product_id: int, product_unit_id: int, quantity: string, discount?: ?string, barcode?: ?string}>  $lines
     * @param  list<array{payment_method_id: int, amount: string, reference?: ?string}>  $payments  amounts as TENDERED
     */
    public function checkout(
        Company $company,
        Branch $branch,
        ?PosDevice $device,
        User $cashier,
        string $clientUuid,
        array $lines,
        array $payments,
        ?int $customerId = null,
        ?string $notes = null,
        ?int $exchangeReturnId = null,
    ): Sale {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientUuid)) {
            throw BusinessRuleException::make('cart', __('Invalid checkout reference.'));
        }

        if (($existing = Sale::forCompany($company)->where('client_uuid', $clientUuid)->first()) !== null) {
            return $existing; // duplicate submission (double click, retry, slow network)
        }

        $this->authorizer->permission($cashier, 'pos.access');
        $this->authorizer->permission($cashier, 'sales.create');
        $this->authorizer->branch($cashier, $branch);
        $this->subscription->ensureWritable($company, 'cart');

        if ($branch->company_id !== $company->id) {
            throw BusinessRuleException::make('cart', __('Invalid branch.'));
        }

        if ($device !== null && ($device->company_id !== $company->id || $device->branch_id !== $branch->id || $device->status->value !== 'active')) {
            throw BusinessRuleException::make('device', __('This register is not active in this branch.'));
        }

        if ($device === null && $this->registers->requiresDevice($company)) {
            throw BusinessRuleException::make('device', __('Select a registered POS device for this branch.'));
        }

        $location = $this->registers->sellingLocation($branch, $device)
            ?? throw BusinessRuleException::make('device', __('No selling location is configured for this register.'));

        if ($lines === []) {
            throw BusinessRuleException::make('cart', __('The cart is empty.'));
        }

        $customer = null;
        if ($customerId !== null) {
            $customer = Customer::forCompany($company)->where('is_active', true)->find($customerId)
                ?? throw BusinessRuleException::make('customer', __('Select a valid customer.'));
        }

        try {
            return DB::transaction(function () use ($company, $branch, $device, $cashier, $clientUuid, $lines, $payments, $customer, $notes, $exchangeReturnId, $location) {
                $inclusive = (bool) data_get($company->settings, 'prices_include_tax', true);

                // 2. Re-validate and re-price every line.
                $priced = [];
                foreach (array_values($lines) as $i => $line) {
                    $product = Product::forCompany($company)->find($line['product_id'] ?? 0)
                        ?? throw BusinessRuleException::make("cart.{$i}", __('Select a valid product.'));
                    $unit = ProductUnit::forCompany($company)->where('product_id', $product->id)->find($line['product_unit_id'] ?? 0)
                        ?? throw BusinessRuleException::make("cart.{$i}", __('Select a valid unit for :product.', ['product' => $product->name]));
                    $item = $this->catalog->item($product, $unit, $branch, $line['barcode'] ?? null);

                    $quantity = (string) ($line['quantity'] ?? '');
                    $quantityBase = $this->conversions->toBase($product, $unit, $quantity, "cart.{$i}.quantity");
                    $discount = str_replace(',', '', trim((string) ($line['discount'] ?? '0'))) ?: '0';

                    if (! Decimal::isValid($discount) || Decimal::scaleOf($discount) > Decimal::MONEY) {
                        throw BusinessRuleException::make("cart.{$i}.discount", __('Enter a valid discount.'));
                    }

                    if (Decimal::isPositive($discount)) {
                        $this->authorizer->permission($cashier, 'sales.discount');
                    }

                    $rate = $product->tax_class->defaultRate();
                    $priced[] = [
                        'item' => $item,
                        'quantity' => Decimal::normalize($quantity, Decimal::QTY),
                        'quantity_base' => $quantityBase,
                        'rate' => $rate,
                        'money' => $this->calculator->line(Decimal::normalize($quantity, Decimal::QTY), $item->price->price, $discount, $rate, $inclusive, "cart.{$i}.discount"),
                    ];
                }

                $totals = $this->calculator->totals(array_column($priced, 'money'));

                // 3. Payments (and exchange credit).
                $exchange = $exchangeReturnId !== null ? $this->lockExchange($company, $exchangeReturnId) : null;
                $paymentRows = $this->payments($company, $payments, $totals['total'], $exchange);

                // 4. Allocate stock under per-product locks (sorted to avoid deadlocks).
                $byProduct = [];
                foreach ($priced as $i => $row) {
                    $byProduct[$row['item']->product->id][] = $i;
                }
                ksort($byProduct);

                $allocations = [];
                foreach ($byProduct as $productId => $indexes) {
                    $this->ledger->lockBranchProduct($company->id, $branch->id, $productId);
                    $average = Decimal::round($this->ledger->averageCost($company->id, $branch->id, $productId), Decimal::COST);
                    $balances = $this->catalog->sellableBalances($location, $productId, lock: true)
                        ->map(fn ($b) => ['batch_id' => $b->batch_id, 'left' => (string) $b->quantity])->all();

                    foreach ($indexes as $i) {
                        $need = $priced[$i]['quantity_base'];
                        foreach ($balances as $k => $balance) {
                            if (! Decimal::isPositive($need)) {
                                break;
                            }
                            $take = Decimal::cmp($balance['left'], $need) >= 0 ? $need : $balance['left'];
                            if (! Decimal::isPositive($take)) {
                                continue;
                            }
                            $allocations[$i][] = ['batch_id' => $balance['batch_id'], 'quantity_base' => Decimal::round($take, Decimal::QTY), 'unit_cost' => $average];
                            $balances[$k]['left'] = Decimal::sub($balance['left'], $take);
                            $need = Decimal::sub($need, $take);
                        }

                        if (Decimal::isPositive($need)) {
                            $product = $priced[$i]['item']->product;
                            $expired = $this->catalog->expiredQuantity($location, $product->id);
                            throw InsufficientStockException::make("cart.{$i}.quantity", __('Not enough :product in :location: :available :unit available.', [
                                'product' => $product->name, 'location' => $location->name,
                                'available' => Decimal::trim($this->catalog->available($location, $product->id)), 'unit' => $product->baseUnit->name,
                            ]).(Decimal::isPositive($expired) ? ' '.__(':qty expired units cannot be sold.', ['qty' => Decimal::trim($expired)]) : ''));
                        }
                    }
                }

                // 5. Immutable records.
                $cost = '0';
                foreach ($allocations as $rows) {
                    foreach ($rows as $a) {
                        $cost = Decimal::add($cost, Decimal::mul($a['quantity_base'], $a['unit_cost']));
                    }
                }

                $tendered = array_reduce($paymentRows, fn ($c, $p) => Decimal::add($c, $p['tendered'], Decimal::MONEY), '0.00');
                $change = array_reduce($paymentRows, fn ($c, $p) => Decimal::add($c, $p['change'], Decimal::MONEY), '0.00');

                $sale = new Sale;
                $sale->forceFill([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'pos_device_id' => $device?->id,
                    'location_id' => $location->id,
                    'customer_id' => $customer?->id,
                    'cashier_id' => $cashier->id,
                    'sale_number' => $this->numbers->next($company, DocumentNumberGenerator::SALE),
                    'client_uuid' => strtolower($clientUuid),
                    'currency' => $company->currency,
                    'prices_include_tax' => $inclusive,
                    'subtotal' => $totals['subtotal'],
                    'discount_total' => $totals['discount'],
                    'tax_total' => $totals['tax'],
                    'total' => $totals['total'],
                    'net_total' => $totals['net'],
                    'paid_total' => $totals['total'],
                    'tendered_total' => $tendered,
                    'change_total' => $change,
                    'cost_total' => Decimal::round($cost, Decimal::COST),
                    'item_count' => Decimal::round(array_reduce($priced, fn ($c, $r) => Decimal::add($c, $r['quantity']), '0'), Decimal::QTY),
                    'exchange_return_id' => $exchange?->id,
                    'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
                    'sold_at' => now(),
                ])->save();

                foreach ($priced as $i => $row) {
                    $item = $row['item'];
                    $lineCost = array_reduce($allocations[$i], fn ($c, $a) => Decimal::add($c, Decimal::mul($a['quantity_base'], $a['unit_cost'])), '0');

                    $saleItem = new SaleItem;
                    $saleItem->forceFill([
                        'company_id' => $company->id,
                        'sale_id' => $sale->id,
                        'product_id' => $item->product->id,
                        'product_unit_id' => $item->unit->id,
                        'product_name' => $item->product->name,
                        'sku' => $item->product->sku,
                        'barcode' => $item->barcode,
                        'unit_name' => $item->unit->unit->name,
                        'conversion_factor' => $item->unit->factor,
                        'quantity' => $row['quantity'],
                        'quantity_base' => $row['quantity_base'],
                        'unit_price' => $item->price->price,
                        'price_source' => $item->price->source,
                        'product_price_id' => $item->price->productPriceId,
                        'discount_amount' => $row['money']['discount'],
                        'tax_class' => $item->product->tax_class->value,
                        'tax_rate' => $row['rate'],
                        'tax_amount' => $row['money']['tax'],
                        'net_amount' => $row['money']['net'],
                        'line_total' => $row['money']['total'],
                        'cost_amount' => Decimal::round($lineCost, Decimal::COST),
                    ])->save();

                    foreach ($allocations[$i] as $a) {
                        $allocation = new SaleItemAllocation;
                        $allocation->forceFill([
                            'company_id' => $company->id,
                            'sale_item_id' => $saleItem->id,
                            'product_id' => $item->product->id,
                            'batch_id' => $a['batch_id'],
                            'quantity_base' => $a['quantity_base'],
                            'unit_cost' => $a['unit_cost'],
                            'cost_amount' => Decimal::round(Decimal::mul($a['quantity_base'], $a['unit_cost']), Decimal::COST),
                        ])->save();

                        // 6. Stock out of the selling location (balance + ledger under the held lock).
                        $this->ledger->post(new StockPosting(
                            type: StockMovementType::Sale,
                            location: $location,
                            product: $item->product,
                            quantityBase: $a['quantity_base'],
                            source: $allocation,
                            reference: $sale->sale_number,
                            batch: $a['batch_id'] ? StockBatch::forCompany($company)->find($a['batch_id']) : null,
                            productUnit: $item->unit,
                            enteredQuantity: $row['quantity'],
                            conversionFactor: $item->unit->factor,
                            user: $cashier,
                        ));
                    }
                }

                foreach ($paymentRows as $p) {
                    $payment = new SalePayment;
                    $payment->forceFill([
                        'company_id' => $company->id,
                        'sale_id' => $sale->id,
                        'payment_method_id' => $p['method']->id,
                        'method_name' => $p['method']->name,
                        'method_type' => $p['method']->type,
                        'amount' => $p['amount'],
                        'tendered' => $p['tendered'],
                        'change_given' => $p['change'],
                        'reference' => $p['reference'],
                        'confirmed_manually' => true,
                    ])->save();
                }

                if ($device !== null) {
                    PosDevice::forCompany($company)->whereKey($device->id)->update(['last_seen_at' => now()]);
                }

                return $sale;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same client UUID committed first.
            return Sale::forCompany($company)->where('client_uuid', strtolower($clientUuid))->first() ?? throw $e;
        }
    }

    private function lockExchange(Company $company, int $returnId): SaleReturn
    {
        $return = SaleReturn::forCompany($company)->lockForUpdate()->find($returnId)
            ?? throw BusinessRuleException::make('payments', __('The exchange return was not found.'));

        if ($return->refund_method_type !== PaymentMethodType::ExchangeCredit->value) {
            throw BusinessRuleException::make('payments', __('This return was refunded and cannot be used for an exchange.'));
        }

        if (Sale::forCompany($company)->where('exchange_return_id', $return->id)->exists()) {
            throw BusinessRuleException::make('payments', __('The exchange credit of :ref has already been used.', ['ref' => $return->return_number]));
        }

        return $return;
    }

    /**
     * Validate tendered payments and derive the amounts APPLIED to the sale.
     * Cash change is never revenue: applied = tendered - change.
     *
     * @return list<array{method: PaymentMethod, tendered: string, amount: string, change: string, reference: ?string}>
     */
    private function payments(Company $company, array $payments, string $due, ?SaleReturn $exchange): array
    {
        if ($payments === []) {
            throw BusinessRuleException::make('payments', __('Add a payment.'));
        }

        $rows = [];
        $nonCash = '0';
        $cash = '0';
        $credit = '0';

        foreach (array_values($payments) as $i => $p) {
            $method = PaymentMethod::forCompany($company)->where('is_active', true)->find($p['payment_method_id'] ?? 0)
                ?? throw BusinessRuleException::make("payments.{$i}.payment_method_id", __('Select a valid payment method.'));
            $amount = str_replace(',', '', trim((string) ($p['amount'] ?? '')));

            if (! Decimal::isValid($amount) || ! Decimal::isPositive($amount) || Decimal::scaleOf($amount) > Decimal::MONEY) {
                throw BusinessRuleException::make("payments.{$i}.amount", __('Payment amounts must be greater than zero.'));
            }

            $reference = trim((string) ($p['reference'] ?? '')) ?: null;
            if ($method->requires_reference && $reference === null) {
                throw BusinessRuleException::make("payments.{$i}.reference", __(':method requires a reference.', ['method' => $method->name]));
            }

            if ($method->type === PaymentMethodType::ExchangeCredit) {
                if ($exchange === null) {
                    throw BusinessRuleException::make("payments.{$i}.payment_method_id", __('Exchange credit can only be used on an exchange sale.'));
                }
                $credit = Decimal::add($credit, $amount);
            }

            $amount = Decimal::normalize($amount, Decimal::MONEY);
            $method->type->givesChange() ? $cash = Decimal::add($cash, $amount) : $nonCash = Decimal::add($nonCash, $amount);
            $rows[] = ['method' => $method, 'tendered' => $amount, 'amount' => $amount, 'change' => '0.00', 'reference' => $reference !== null ? mb_substr($reference, 0, 100) : null];
        }

        if ($exchange !== null && Decimal::cmp($credit, (string) $exchange->refund_total) > 0) {
            throw BusinessRuleException::make('payments', __('Exchange credit cannot exceed :amount.', ['amount' => Decimal::format($exchange->refund_total)]));
        }

        if (Decimal::cmp($nonCash, $due) > 0) {
            throw BusinessRuleException::make('payments', __('Non-cash payments cannot exceed the amount due.'));
        }

        $tendered = Decimal::add($cash, $nonCash);
        if (Decimal::cmp($tendered, $due) < 0) {
            throw BusinessRuleException::make('payments', __('Payment is short by :amount.', ['amount' => Decimal::format(Decimal::sub($due, $tendered))]));
        }

        // Change comes out of cash, last cash line first.
        $change = Decimal::round(Decimal::sub($tendered, $due), Decimal::MONEY);
        for ($i = count($rows) - 1; $i >= 0 && Decimal::isPositive($change); $i--) {
            if ($rows[$i]['method']->type->givesChange()) {
                $give = Decimal::cmp($rows[$i]['tendered'], $change) >= 0 ? $change : $rows[$i]['tendered'];
                $rows[$i]['change'] = Decimal::round($give, Decimal::MONEY);
                $rows[$i]['amount'] = Decimal::sub($rows[$i]['tendered'], $give, Decimal::MONEY);
                $change = Decimal::sub($change, $give);
            }
        }

        return array_values(array_filter($rows, fn ($r) => Decimal::isPositive($r['amount']) || Decimal::isPositive($r['change'])));
    }
}
