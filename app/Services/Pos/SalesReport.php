<?php

namespace App\Services\Pos;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Support\Decimal;
use Carbon\CarbonInterface;

/**
 * Sales reporting from POSTED sales and returns only (aggregated in SQL).
 * Revenue never includes cash change (payments are recorded as applied
 * amounts); refunds and COGS reversals come from return documents.
 */
class SalesReport
{
    /** @param list<int> $branchIds */
    public function build(int $companyId, array $branchIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $sales = Sale::forCompany($companyId)->whereIn('sales.branch_id', $branchIds)->whereBetween('sales.sold_at', [$from, $to]);
        $returns = SaleReturn::forCompany($companyId)->whereIn('sale_returns.branch_id', $branchIds)->whereBetween('sale_returns.returned_at', [$from, $to]);

        $s = (clone $sales)->selectRaw('count(*) as transactions, coalesce(sum(total),0) as gross, coalesce(sum(net_total),0) as net, coalesce(sum(tax_total),0) as tax,
            coalesce(sum(discount_total),0) as discounts, coalesce(sum(cost_total),0) as cogs, coalesce(sum(change_total),0) as change_given')->first();
        $r = (clone $returns)->selectRaw('count(*) as returns, coalesce(sum(refund_total),0) as refunds, coalesce(sum(net_total),0) as net, coalesce(sum(cost_total),0) as cogs')->first();

        $netRevenue = Decimal::sub((string) $s->net, (string) $r->net, Decimal::MONEY);
        $netCogs = Decimal::round(Decimal::sub((string) $s->cogs, (string) $r->cogs), Decimal::MONEY);

        $items = SaleItem::forCompany($companyId)->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.branch_id', $branchIds)->whereBetween('sales.sold_at', [$from, $to]);

        return [
            'summary' => [
                'transactions' => (int) $s->transactions,
                'gross_sales' => Decimal::round((string) $s->gross, Decimal::MONEY),
                'discounts' => Decimal::round((string) $s->discounts, Decimal::MONEY),
                'tax' => Decimal::round(Decimal::sub((string) $s->tax, Decimal::sub((string) $r->refunds, (string) $r->net)), Decimal::MONEY),
                'returns' => (int) $r->returns,
                'refunds' => Decimal::round((string) $r->refunds, Decimal::MONEY),
                'net_sales' => Decimal::sub((string) $s->gross, (string) $r->refunds, Decimal::MONEY),
                'net_revenue_ex_tax' => $netRevenue,
                'cogs' => $netCogs,
                'gross_profit' => Decimal::sub($netRevenue, $netCogs, Decimal::MONEY),
                'items_sold' => Decimal::trim(Decimal::round((string) ((clone $items)->sum('sale_items.quantity_base') ?: '0'), Decimal::QTY)),
                'change_given' => Decimal::round((string) $s->change_given, Decimal::MONEY),
            ],
            'by_product' => (clone $items)->groupBy('sale_items.product_id', 'sale_items.product_name')
                ->selectRaw('sale_items.product_name as label, sum(sale_items.quantity_base) as qty, sum(sale_items.line_total) as total, sum(sale_items.net_amount) as net, sum(sale_items.cost_amount) as cost')
                ->orderByDesc('total')->limit(20)->get(),
            'by_category' => (clone $items)->join('products', 'products.id', '=', 'sale_items.product_id')->join('categories', 'categories.id', '=', 'products.category_id')
                ->groupBy('categories.id', 'categories.name')->selectRaw('categories.name as label, sum(sale_items.line_total) as total, sum(sale_items.net_amount) as net, sum(sale_items.cost_amount) as cost')
                ->orderByDesc('total')->get(),
            'by_cashier' => (clone $sales)->join('users', 'users.id', '=', 'sales.cashier_id')->groupBy('users.id', 'users.name')
                ->selectRaw('users.name as label, count(*) as transactions, sum(sales.total) as total')->orderByDesc('total')->get(),
            'by_branch' => (clone $sales)->join('branches', 'branches.id', '=', 'sales.branch_id')->groupBy('branches.id', 'branches.name')
                ->selectRaw('branches.name as label, count(*) as transactions, sum(sales.total) as total')->orderByDesc('total')->get(),
            'by_payment' => SalePayment::forCompany($companyId)->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
                ->whereIn('sales.branch_id', $branchIds)->whereBetween('sales.sold_at', [$from, $to])
                ->groupBy('sale_payments.method_name')->selectRaw('sale_payments.method_name as label, count(*) as payments, sum(sale_payments.amount) as total')
                ->orderByDesc('total')->get(),
            'refunds_by_method' => (clone $returns)->groupBy('refund_method_name')->selectRaw('refund_method_name as label, count(*) as returns, sum(refund_total) as total')->get(),
            'returned_items' => SaleReturnItem::forCompany($companyId)->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
                ->whereIn('sale_returns.branch_id', $branchIds)->whereBetween('sale_returns.returned_at', [$from, $to])
                ->groupBy('sale_return_items.disposition')->selectRaw('sale_return_items.disposition as label, sum(sale_return_items.quantity_base) as qty, sum(sale_return_items.refund_amount) as total')->get(),
        ];
    }
}
