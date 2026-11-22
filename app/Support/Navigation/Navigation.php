<?php

namespace App\Support\Navigation;

use App\Enums\Permission as P;
use App\Models\User;

/**
 * Sidebar definitions for the three application areas. Items are
 * filtered server-side by permission; Blade only renders the result.
 * Items with 'soon' => true are placeholders for future phases.
 */
class Navigation
{
    /** @return list<array{section: string, items: list<array<string, mixed>>}> */
    public static function platform(User $user): array
    {
        return self::filter($user, [
            ['section' => 'Platform', 'items' => [
                ['label' => 'Dashboard', 'route' => 'platform.dashboard', 'icon' => 'home', 'can' => P::PlatformAccess],
                ['label' => 'Companies', 'route' => 'platform.companies.index', 'icon' => 'building', 'can' => P::PlatformCompaniesView],
                ['label' => 'Subscription plans', 'route' => 'platform.plans.index', 'icon' => 'credit-card', 'can' => P::PlatformPlansManage],
            ]],
        ]);
    }

    public static function company(User $user): array
    {
        return self::filter($user, [
            ['section' => 'Company', 'items' => [
                ['label' => 'Dashboard', 'route' => 'company.dashboard', 'icon' => 'home', 'can' => P::CompanyView],
                ['label' => 'Branches', 'route' => 'company.branches.index', 'icon' => 'building', 'can' => P::BranchesView],
                ['label' => 'Staff', 'route' => 'company.staff.index', 'icon' => 'users', 'can' => P::StaffView],
                ['label' => 'POS devices', 'route' => 'company.pos-devices.index', 'icon' => 'device', 'can' => P::PosDevicesView],
                ['label' => 'Subscription', 'route' => 'company.subscription', 'icon' => 'credit-card', 'can' => P::SubscriptionView],
            ]],
            ...self::modules(),
        ]);
    }

    public static function branch(User $user): array
    {
        return self::filter($user, [
            ['section' => 'Branch', 'items' => [
                ['label' => 'Dashboard', 'route' => 'branch.dashboard', 'icon' => 'home', 'can' => null],
            ]],
            ...self::modules(),
        ]);
    }

    /** Phase 2 modules are live; the rest remain placeholders ('soon'). */
    private static function modules(): array
    {
        return [
            ['section' => 'Sales', 'items' => [
                ['label' => 'Point of sale', 'route' => 'pos', 'icon' => 'cart', 'can' => P::PosAccess],
                ['label' => 'Sales', 'route' => 'sales.index', 'match' => 'sales.show', 'icon' => 'receipt', 'can' => [P::SalesView, P::SalesCreate]],
                ['label' => 'Sales reports', 'route' => 'sales.reports', 'icon' => 'chart', 'can' => P::ReportsView],
                ['label' => 'Customers', 'route' => 'customers.index', 'icon' => 'users', 'can' => P::CustomersView],
                ['label' => 'Payment methods', 'route' => 'settings.payment-methods', 'icon' => 'wallet', 'can' => P::PaymentMethodsManage],
            ]],
            ['section' => 'Catalogue', 'items' => [
                ['label' => 'Products', 'route' => 'catalogue.products.index', 'match' => 'catalogue.products.*', 'icon' => 'tag', 'can' => P::ProductsView],
                ['label' => 'Categories', 'route' => 'catalogue.categories.index', 'icon' => 'box', 'can' => P::ProductsView],
                ['label' => 'Brands', 'route' => 'catalogue.brands.index', 'icon' => 'store', 'can' => P::ProductsView],
                ['label' => 'Units', 'route' => 'catalogue.units.index', 'icon' => 'chart', 'can' => P::ProductsView],
                ['label' => 'Barcode lookup', 'route' => 'catalogue.barcodes.lookup', 'icon' => 'receipt', 'can' => P::ProductsView],
            ]],
            ['section' => 'Purchasing', 'items' => [
                ['label' => 'Suppliers', 'route' => 'purchasing.suppliers.index', 'match' => 'purchasing.suppliers.*', 'icon' => 'handshake', 'can' => P::SuppliersView],
                ['label' => 'Purchases', 'route' => 'purchasing.orders.index', 'match' => 'purchasing.orders.*', 'icon' => 'truck', 'can' => P::PurchasesView],
                ['label' => 'Goods received', 'route' => 'purchasing.receipts.index', 'match' => 'purchasing.receipts.*', 'icon' => 'box', 'can' => P::PurchasesView],
            ]],
            ['section' => 'Inventory', 'items' => [
                ['label' => 'Stock on hand', 'route' => 'inventory.stock', 'icon' => 'box', 'can' => P::InventoryView],
                ['label' => 'Stock movements', 'route' => 'inventory.movements', 'icon' => 'chart', 'can' => P::InventoryView],
                ['label' => 'Adjustments', 'route' => 'inventory.adjustments', 'icon' => 'shield', 'can' => P::InventoryView],
                ['label' => 'Transfers', 'route' => 'inventory.transfers', 'icon' => 'truck', 'can' => P::InventoryView],
                ['label' => 'Stock locations', 'route' => 'inventory.locations', 'icon' => 'building', 'can' => P::InventoryView],
            ]],
            ['section' => 'Coming soon', 'items' => [
                ['label' => 'Expenses', 'icon' => 'wallet', 'can' => P::ExpensesView],
            ]],
        ];
    }

    private static function filter(User $user, array $sections): array
    {
        $result = [];

        foreach ($sections as $section) {
            $items = [];

            foreach ($section['items'] as $item) {
                $required = $item['can'] ?? null;
                if ($required !== null && ! collect(is_array($required) ? $required : [$required])->contains(fn ($p) => $user->hasPermission($p))) {
                    continue;
                }

                $route = $item['route'] ?? null;
                $items[] = [
                    'label' => __($item['label']),
                    'icon' => $item['icon'],
                    'url' => $route ? route($route) : null,
                    'active' => $route ? request()->routeIs($item['match'] ?? $route.'*') : false,
                    'soon' => $item['soon'] ?? ($route === null),
                ];
            }

            if ($items !== []) {
                $result[] = ['section' => __($section['section']), 'items' => $items];
            }
        }

        return $result;
    }
}
