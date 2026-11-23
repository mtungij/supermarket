<?php

use App\Http\Controllers\Branch\DashboardController as BranchDashboardController;
use App\Http\Controllers\Catalogue\ProductImageController;
use App\Http\Controllers\Catalogue\ProductLabelController;
use App\Http\Controllers\Company\BranchController;
use App\Http\Controllers\Company\DashboardController as CompanyDashboardController;
use App\Http\Controllers\Company\StaffController;
use App\Http\Controllers\Company\SubscriptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\OpeningStockTemplateController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Platform\CompanyController as PlatformCompanyController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\PlanController as PlatformPlanController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderPrintController;
use App\Http\Controllers\Sales\ReceiptController;
use App\Http\Controllers\TenantSwitchController;
use App\Livewire\Catalogue;
use App\Livewire\Company\BranchManager;
use App\Livewire\Company\PosDeviceManager;
use App\Livewire\Customers;
use App\Livewire\Inventory;
use App\Livewire\Pos;
use App\Livewire\Purchasing;
use App\Livewire\Sales;
use App\Livewire\Settings;
use App\Models\Branch;
use App\Models\PosDevice;
use Illuminate\Support\Facades\Route;

/*
| Authentication routes (login, logout, register = company onboarding,
| password reset, optional email verification) are registered by Fortify.
*/

// The login page is the entry point: guests -> /login, signed-in users ->
// their role-based area (DashboardController). The old landing page stays
// public at /welcome.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');
Route::view('/welcome', 'welcome')->name('welcome');

Route::post('/locale', LocaleController::class)->name('locale.update');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::view('/no-company', 'no-company')->name('no-company');

    // ---- Platform Super Admin area (no tenant context) -------------------
    Route::middleware('platform')->prefix('platform')->name('platform.')->group(function () {
        Route::get('/', PlatformDashboardController::class)->name('dashboard');
        Route::get('/companies', [PlatformCompanyController::class, 'index'])->name('companies.index');
        Route::get('/plans', [PlatformPlanController::class, 'index'])->name('plans.index');
    });

    // ---- Tenant area: company resolved from active memberships ----------
    Route::middleware(['verified.optional', 'tenant'])->group(function () {
        Route::post('/tenant/company', [TenantSwitchController::class, 'company'])->name('tenant.company.switch');
        Route::post('/tenant/branch', [TenantSwitchController::class, 'branch'])->name('tenant.branch.switch');

        Route::prefix('company')->name('company.')->group(function () {
            Route::get('/', CompanyDashboardController::class)->name('dashboard')->middleware('can:company.view');
            Route::get('/branches', BranchManager::class)->name('branches.index')->middleware('can:viewAny,'.Branch::class);
            Route::get('/branches/{branch}', [BranchController::class, 'show'])->name('branches.show')->middleware('can:view,branch');
            Route::get('/staff', [StaffController::class, 'index'])->name('staff.index')->middleware('can:staff.view');
            Route::get('/pos-devices', PosDeviceManager::class)->name('pos-devices.index')->middleware('can:viewAny,'.PosDevice::class);
            Route::get('/subscription', SubscriptionController::class)->name('subscription')->middleware('can:subscription.view');
        });

        Route::get('/branch', BranchDashboardController::class)->name('branch.dashboard');

        // ---- Phase 3: POS & sales (no cashier shifts) --------------------
        Route::get('/pos', Pos\Terminal::class)->name('pos')->middleware('can:pos.access');
        Route::redirect('/my-sales', '/sales')->name('sales.mine');
        Route::get('/sales', Sales\SaleIndex::class)->name('sales.index');
        Route::get('/sales/reports', Sales\SalesReports::class)->name('sales.reports')->middleware('can:reports.view');
        Route::get('/sales/{sale}', Sales\SaleShow::class)->name('sales.show');
        Route::get('/sales/{sale}/receipt', ReceiptController::class)->name('sales.receipt');
        Route::get('/customers', Customers\CustomerIndex::class)->name('customers.index')->middleware('can:customers.view');
        Route::get('/settings/payment-methods', Settings\PaymentMethodIndex::class)->name('settings.payment-methods')->middleware('can:payment_methods.manage');

        // ---- Phase 2: catalogue ----------------------------------------
        // Every route also authorises inside the component/controller; the
        // `can:` middleware is the first, coarse gate.
        Route::prefix('catalogue')->name('catalogue.')->middleware('can:products.view')->group(function () {
            Route::get('/products', Catalogue\ProductIndex::class)->name('products.index');
            Route::get('/products/create', Catalogue\ProductForm::class)->name('products.create')->middleware('can:products.create');
            Route::get('/products/{product}', Catalogue\ProductShow::class)->name('products.show');
            Route::get('/products/{product}/edit', Catalogue\ProductForm::class)->name('products.edit')->middleware('can:products.update');
            Route::get('/products/{product}/image', ProductImageController::class)->name('products.image');
            Route::get('/products/{product}/labels', ProductLabelController::class)->name('products.labels');
            Route::get('/categories', Catalogue\CategoryIndex::class)->name('categories.index');
            Route::get('/brands', Catalogue\BrandIndex::class)->name('brands.index');
            Route::get('/units', Catalogue\UnitIndex::class)->name('units.index');
            Route::get('/barcode-lookup', Catalogue\BarcodeLookup::class)->name('barcodes.lookup');
        });

        // ---- Phase 2: purchasing ---------------------------------------
        Route::prefix('purchasing')->name('purchasing.')->group(function () {
            Route::get('/suppliers', Purchasing\SupplierIndex::class)->name('suppliers.index')->middleware('can:suppliers.view');
            Route::get('/suppliers/{supplier}', Purchasing\SupplierShow::class)->name('suppliers.show')->middleware('can:suppliers.view');
            Route::middleware('can:purchases.view')->group(function () {
                Route::get('/orders', Purchasing\PurchaseOrderIndex::class)->name('orders.index');
                Route::get('/orders/create', Purchasing\PurchaseOrderForm::class)->name('orders.create')->middleware('can:purchases.create');
                Route::get('/orders/{order}', Purchasing\PurchaseOrderShow::class)->name('orders.show');
                Route::get('/orders/{order}/edit', Purchasing\PurchaseOrderForm::class)->name('orders.edit')->middleware('can:purchases.create');
                Route::get('/orders/{order}/receive', Purchasing\GoodsReceiptForm::class)->name('orders.receive')->middleware('can:purchases.receive');
                Route::get('/orders/{order}/print', PurchaseOrderPrintController::class)->name('orders.print');
                Route::get('/receipts', Purchasing\GoodsReceiptIndex::class)->name('receipts.index');
                Route::get('/receipts/{receipt}', [GoodsReceiptController::class, 'show'])->name('receipts.show');
            });
        });

        // ---- Phase 2: inventory (current branch from the branch selector) -
        Route::prefix('inventory')->name('inventory.')->middleware('can:inventory.view')->group(function () {
            Route::get('/stock', Inventory\StockIndex::class)->name('stock');
            Route::get('/movements', Inventory\MovementIndex::class)->name('movements');
            Route::get('/adjustments', Inventory\AdjustmentIndex::class)->name('adjustments');
            Route::get('/transfers', Inventory\TransferIndex::class)->name('transfers');
            Route::get('/locations', Inventory\LocationIndex::class)->name('locations');
            // Opening stock: initial inventory owned before using the system (no PO/GRN/supplier).
            Route::get('/opening-stock', Inventory\OpeningStockIndex::class)->name('opening-stock.index');
            Route::get('/opening-stock/new', Inventory\OpeningStockForm::class)->name('opening-stock.create')->middleware('can:inventory.opening.manage');
            Route::get('/opening-stock/import', Inventory\OpeningStockImport::class)->name('opening-stock.import')->middleware('can:inventory.opening.manage');
            Route::get('/opening-stock/template.csv', OpeningStockTemplateController::class)->name('opening-stock.template');
            Route::get('/opening-stock/{openingStock}', Inventory\OpeningStockForm::class)->name('opening-stock.show');
        });
    });
});
