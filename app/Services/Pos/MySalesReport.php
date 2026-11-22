<?php

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cashier "My Sales" figures for a period. A SALES-REPORTING feature, not
 * shift management: periods are calendar ranges in the company timezone.
 *
 * $sales must already be scoped by SalesQueries::visibleTo() (cashier ->
 * own sales; managers -> permitted branches). Everything is computed in
 * SQL from posted records:
 *  - only completed sales exist (a failed checkout rolls back entirely),
 *  - collections use the APPLIED payment amount, so cash change is
 *    excluded and split payments are counted once per payment row,
 *  - refunds are the returns AGAINST the sales of the period (whenever the
 *    return was processed), so the summary cards and the per-product net
 *    figures on the same page always agree; net = gross - refunds,
 *  - historical amounts come from the immutable sale snapshots.
 */
class MySalesReport
{
    public const PERIODS = ['today', 'yesterday', 'this_week', 'this_month', 'custom', 'all'];

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC bounds */
    public function range(string $period, string $timezone, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now($timezone);

        [$start, $end] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfDay()],
            'custom' => $this->custom($timezone, $from, $to),
            'all' => [CarbonImmutable::create(2000, 1, 1, 0, 0, 0, $timezone), $now->endOfDay()],
            default => throw new InvalidArgumentException('Unknown period.'),
        };

        return [$start->utc(), $end->utc()];
    }

    private function custom(string $timezone, ?string $from, ?string $to): array
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) $from, $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($to ?: $from), $timezone);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid custom range.');
        }

        if (! $start || ! $end || ($start = $start->startOfDay()) > ($end = $end->endOfDay())) {
            throw new InvalidArgumentException('Invalid custom range.');
        }

        return [$start, $end];
    }

    /** @param Builder $sales scoped sales query (any period) */
    public function build(Builder $sales, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inPeriod = (clone $sales)->whereBetween('sales.sold_at', [$from, $to]);
        $ids = (clone $inPeriod)->select('sales.id');

        $s = (clone $inPeriod)->selectRaw('count(*) as transactions, coalesce(sum(total),0) as gross, coalesce(sum(discount_total),0) as discounts,
            coalesce(sum(tax_total),0) as tax, coalesce(sum(change_total),0) as change_given')->toBase()->first();

        $items = SaleItem::query()->whereIn('sale_id', $ids);
        $byUnit = (clone $items)->groupBy('unit_name')->selectRaw('unit_name, sum(quantity) as qty')->orderByDesc('qty')->get();

        $payments = SalePayment::query()->whereIn('sale_id', $ids)->groupBy('method_type')
            ->selectRaw('method_type, sum(amount) as amount')->pluck('amount', 'method_type')->map(fn ($v) => (string) $v);
        $collected = fn (PaymentMethodType ...$types) => Decimal::round(
            collect($types)->reduce(fn ($c, $t) => Decimal::add($c, $payments[$t->value] ?? '0'), '0'), Decimal::MONEY);

        // Returns against the sales of this period (consistent with productsSold()).
        $returns = SaleReturn::query()->whereIn('sale_id', $ids);
        $r = (clone $returns)->selectRaw('count(*) as returns, coalesce(sum(refund_total),0) as refunds')->toBase()->first();

        $gross = Decimal::round((string) $s->gross, Decimal::MONEY);
        $refunds = Decimal::round((string) $r->refunds, Decimal::MONEY);

        return [
            'transactions' => (int) $s->transactions,
            'gross_sales' => $gross,
            'items_sold' => Decimal::trim(Decimal::round((string) ($items->sum('quantity_base') ?: '0'), Decimal::QTY)),
            'items_by_unit' => $byUnit->map(fn ($u) => ['unit' => $u->unit_name, 'qty' => Decimal::trim(Decimal::round((string) $u->qty, Decimal::QTY))])->all(),
            'cash' => $collected(PaymentMethodType::Cash),
            'mobile_money' => $collected(PaymentMethodType::MobileMoney),
            'card_bank' => $collected(PaymentMethodType::Card, PaymentMethodType::BankTransfer),
            'other' => $collected(PaymentMethodType::Other),
            'exchange_credit' => $collected(PaymentMethodType::ExchangeCredit),
            'change_given' => Decimal::round((string) $s->change_given, Decimal::MONEY),
            'returns' => (int) $r->returns,
            'refunds' => $refunds,
            'net_sales' => Decimal::sub($gross, $refunds, Decimal::MONEY),
            'discounts' => Decimal::round((string) $s->discounts, Decimal::MONEY),
            'vat' => Decimal::round((string) $s->tax, Decimal::MONEY),
        ];
    }

    /**
     * Products sold in the period, aggregated per product + unit (+ the
     * unit's conversion snapshot) from IMMUTABLE sale-line snapshots, never
     * from current prices. Returned quantities/amounts are those returned
     * against these sale lines (partial or full).
     *
     * @return Collection<int, object>
     */
    public function productsSold(Builder $sales, CarbonImmutable $from, CarbonImmutable $to)
    {
        $ids = (clone $sales)->whereBetween('sales.sold_at', [$from, $to])->select('sales.id');

        $returned = DB::table('sale_return_items')
            ->selectRaw('sale_item_id, sum(quantity_base) as qty_base, sum(refund_amount) as refund')
            ->groupBy('sale_item_id');

        return SaleItem::query()
            ->whereIn('sale_items.sale_id', $ids)
            ->leftJoinSub($returned, 'r', 'r.sale_item_id', '=', 'sale_items.id')
            ->groupBy('sale_items.product_id', 'sale_items.product_unit_id', 'sale_items.unit_name', 'sale_items.conversion_factor')
            ->selectRaw('sale_items.product_id, sale_items.product_unit_id, sale_items.unit_name, sale_items.conversion_factor,
                max(sale_items.product_name) as product_name, max(sale_items.sku) as sku,
                sum(sale_items.quantity) as quantity, sum(sale_items.line_total) as gross,
                sum(sale_items.quantity * sale_items.unit_price) as list_value,
                min(sale_items.unit_price) as min_price, max(sale_items.unit_price) as max_price,
                count(distinct sale_items.sale_id) as transactions,
                coalesce(sum(r.qty_base / sale_items.conversion_factor), 0) as returned_quantity,
                coalesce(sum(r.refund), 0) as refunded')
            ->orderByDesc('gross')
            ->get()
            ->map(function ($row) {
                $qty = (string) $row->quantity;
                $returnedQty = Decimal::round((string) $row->returned_quantity, Decimal::QTY);

                return (object) [
                    'product_name' => $row->product_name,
                    'sku' => $row->sku,
                    'unit' => $row->unit_name,
                    'quantity' => Decimal::trim(Decimal::round($qty, Decimal::QTY)),
                    'single_price' => Decimal::cmp((string) $row->min_price, (string) $row->max_price) === 0,
                    'min_price' => Decimal::round((string) $row->min_price, Decimal::MONEY),
                    'max_price' => Decimal::round((string) $row->max_price, Decimal::MONEY),
                    // Quantity-weighted average of the historical shelf prices.
                    'average_price' => Decimal::isPositive($qty) ? Decimal::round(Decimal::div((string) $row->list_value, $qty), Decimal::MONEY) : '0.00',
                    'gross' => Decimal::round((string) $row->gross, Decimal::MONEY),
                    'transactions' => (int) $row->transactions,
                    'returned_quantity' => Decimal::trim($returnedQty),
                    'net_quantity' => Decimal::trim(Decimal::round(Decimal::sub($qty, $returnedQty), Decimal::QTY)),
                    'refunded' => Decimal::round((string) $row->refunded, Decimal::MONEY),
                    'net' => Decimal::sub((string) $row->gross, (string) $row->refunded, Decimal::MONEY),
                ];
            });
    }
}
