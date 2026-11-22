<?php

namespace App\Services\Inventory;

use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\InventoryCost;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockTransferLine;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read models for inventory screens. All queries are explicitly limited
 * to one company AND one branch.
 */
class InventoryQueries
{
    /** Stock on hand per product in a branch (all locations), with WAC and value. */
    public function branchStock(Branch $branch, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return Product::forCompany($branch->company_id)
            ->with(['baseUnit:id,name,code', 'category:id,name'])
            ->leftJoin('inventory_costs as ic', fn ($join) => $join->on('ic.product_id', '=', 'products.id')->where('ic.branch_id', $branch->id))
            ->select('products.*', DB::raw('COALESCE(ic.quantity, 0) as on_hand'), DB::raw('COALESCE(ic.average_cost, 0) as average_cost'))
            ->when($filters['search'] ?? null, fn (Builder $q, $s) => $q->where(fn ($w) => $w->where('products.name', 'like', "%{$s}%")->orWhere('products.sku', 'like', "%{$s}%")))
            ->when($filters['category'] ?? null, fn (Builder $q, $c) => $q->where('products.category_id', $c))
            ->when(($filters['stock'] ?? null) === 'low', fn (Builder $q) => $q->whereRaw('COALESCE(ic.quantity, 0) <= products.reorder_level')->where('products.reorder_level', '>', 0))
            ->when(($filters['stock'] ?? null) === 'out', fn (Builder $q) => $q->whereRaw('COALESCE(ic.quantity, 0) <= 0'))
            ->when(($filters['stock'] ?? null) === 'in', fn (Builder $q) => $q->whereRaw('COALESCE(ic.quantity, 0) > 0'))
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Products at or below their reorder level (critical when at/below minimum). */
    public function lowStock(Branch $branch): Collection
    {
        return Product::forCompany($branch->company_id)
            ->with('baseUnit:id,name')
            ->leftJoin('inventory_costs as ic', fn ($join) => $join->on('ic.product_id', '=', 'products.id')->where('ic.branch_id', $branch->id))
            ->select('products.*', DB::raw('COALESCE(ic.quantity, 0) as on_hand'))
            ->where('products.is_active', true)
            ->where(fn ($q) => $q->where('products.reorder_level', '>', 0)->orWhere('products.min_stock_level', '>', 0))
            ->whereRaw('COALESCE(ic.quantity, 0) <= GREATEST(products.reorder_level, products.min_stock_level)')
            ->orderByRaw('COALESCE(ic.quantity, 0) - products.min_stock_level')
            ->limit(200)
            ->get()
            ->each(fn ($p) => $p->setAttribute('critical', Decimal::cmp((string) $p->on_hand, (string) $p->min_stock_level) <= 0));
    }

    /** Batches with stock that are expired or expire within $days. */
    public function expiring(Branch $branch, ?int $days = null): Collection
    {
        $days ??= (int) config('inventory.expiry_alert_days');

        return StockBalance::forCompany($branch->company_id)
            ->where('stock_balances.branch_id', $branch->id)
            ->where('stock_balances.quantity', '>', 0)
            ->join('stock_batches as b', 'b.id', '=', 'stock_balances.batch_id')
            ->whereNotNull('b.expires_on')
            ->where('b.expires_on', '<=', today()->addDays($days)->toDateString())
            ->select('stock_balances.*', 'b.expires_on', 'b.batch_number')
            ->with(['product:id,name,sku,base_unit_id', 'product.baseUnit:id,name', 'location:id,name'])
            ->orderBy('b.expires_on')
            ->limit(200)
            ->get();
    }

    /**
     * Valuation at weighted-average cost.
     *
     * @return array{on_hand_value: string, in_transit_out_value: string, products_in_stock: int}
     */
    public function valuation(Branch $branch): array
    {
        $rows = InventoryCost::forCompany($branch->company_id)->where('branch_id', $branch->id)
            ->where('quantity', '>', 0)->get(['quantity', 'average_cost']);

        $value = $rows->reduce(fn (string $carry, $row) => Decimal::add($carry, Decimal::mul((string) $row->quantity, (string) $row->average_cost)), '0');

        $transit = StockTransferLine::forCompany($branch->company_id)
            ->join('stock_transfers as t', 't.id', '=', 'stock_transfer_lines.stock_transfer_id')
            ->where('t.source_branch_id', $branch->id)
            ->where('t.status', StockTransferStatus::InTransit->value)
            ->get(['stock_transfer_lines.quantity_base', 'stock_transfer_lines.unit_cost_base'])
            ->reduce(fn (string $carry, $l) => Decimal::add($carry, Decimal::mul((string) $l->quantity_base, (string) ($l->unit_cost_base ?? '0'))), '0');

        return [
            'on_hand_value' => Decimal::round($value, Decimal::MONEY),
            'in_transit_out_value' => Decimal::round($transit, Decimal::MONEY),
            'products_in_stock' => $rows->count(),
        ];
    }

    /** Balances per location/batch for one product in a branch. */
    public function productBalances(Product $product, Branch $branch): Collection
    {
        return StockBalance::forCompany($product->company_id)
            ->where('branch_id', $branch->id)->where('product_id', $product->id)->where('quantity', '!=', 0)
            ->with(['location:id,name', 'batch:id,batch_number,expires_on'])
            ->orderBy('location_id')->get();
    }
}
