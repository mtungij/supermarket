<?php

namespace App\Providers;

use App\Authorization\PermissionGates;
use App\Http\Middleware\EnsureEmailIsVerifiedWhenEnabled;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleItemAllocation;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use App\View\Composers\AppLayoutComposer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh tenant context for every request / queued job.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantResolver::class);
    }

    public function boot(): void
    {
        $this->registerSchemaMacros();

        PermissionGates::register();

        // Stable, short names stored in polymorphic source columns
        // (stock_movements.source_type, document_events.documentable_type).
        Relation::morphMap([
            'purchase_order' => PurchaseOrder::class,
            'goods_receipt' => GoodsReceipt::class,
            'goods_receipt_line' => GoodsReceiptLine::class,
            'stock_adjustment' => StockAdjustment::class,
            'stock_adjustment_line' => StockAdjustmentLine::class,
            'stock_transfer' => StockTransfer::class,
            'stock_transfer_line' => StockTransferLine::class,
            'sale' => Sale::class,
            'sale_item_allocation' => SaleItemAllocation::class,
            'sale_return' => SaleReturn::class,
            'sale_return_item' => SaleReturnItem::class,
        ]);

        // Livewire update requests re-run these middleware, so components
        // always execute inside a freshly re-validated tenant context.
        Livewire::addPersistentMiddleware([
            ResolveTenantContext::class,
            EnsureEmailIsVerifiedWhenEnabled::class,
        ]);

        // Never let a tenant context leak into the next request (tests,
        // Octane-style long-running workers).
        $this->app->terminating(fn () => $this->app->make(TenantContext::class)->clear());

        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8)->letters()->numbers());

        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        View::composer([
            'components.layouts.platform',
            'components.layouts.company',
            'components.layouts.branch',
        ], AppLayoutComposer::class);

        // Module pages choose the company or branch layout per membership.
        View::composer('components.layouts.tenant', fn ($view) => $view->with(
            'companyWide', (bool) app(TenantContext::class)->membership()?->all_branches,
        ));
    }

    /**
     * Blueprint::tenantForeign('product_id', 'products') adds the composite
     * FK (product_id, company_id) -> products(id, company_id), so a row can
     * never reference another company's record. The referenced table must
     * have UNIQUE(id, company_id).
     */
    private function registerSchemaMacros(): void
    {
        Blueprint::macro('tenantForeign', function (string $column, string $on, string $scopeColumn = 'company_id', string $onDelete = 'restrict') {
            /** @var Blueprint $this */
            $name = strtolower($this->getTable().'_'.$column.'_'.$scopeColumn.'_foreign');
            if (strlen($name) > 64) { // MySQL identifier limit
                $name = substr($this->getTable(), 0, 40).'_'.substr(md5($name), 0, 12).'_fk';
            }

            $foreign = $this->foreign([$column, $scopeColumn], $name)->references(['id', $scopeColumn])->on($on);

            return match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                default => $foreign->restrictOnDelete(),
            };
        });
    }
}
