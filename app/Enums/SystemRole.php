<?php

namespace App\Enums;

use App\Enums\Permission as P;

/**
 * Built-in roles seeded for every installation.
 *
 * Tenant roles are shared templates (company_id = NULL). Companies may get
 * custom roles in a later phase (roles.company_id = their id).
 */
enum SystemRole: string
{
    case PlatformSuperAdmin = 'platform_super_admin';
    case CompanyOwner = 'company_owner';
    case BranchManager = 'branch_manager';
    case Supervisor = 'supervisor';
    case Cashier = 'cashier';
    case StoreKeeper = 'store_keeper';
    case Accountant = 'accountant';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::PlatformSuperAdmin => 'Platform Super Admin',
            self::CompanyOwner => 'Company Owner',
            self::BranchManager => 'Branch Manager',
            self::Supervisor => 'Supervisor',
            self::Cashier => 'Cashier',
            self::StoreKeeper => 'Store Keeper',
            self::Accountant => 'Accountant',
            self::Auditor => 'Auditor',
        };
    }

    public function scope(): RoleScope
    {
        return $this === self::PlatformSuperAdmin ? RoleScope::Platform : RoleScope::Tenant;
    }

    /**
     * Whether members with this role see every branch of the company by
     * default. Branch-level roles only see branches explicitly assigned.
     */
    public function allBranches(): bool
    {
        return match ($this) {
            self::CompanyOwner, self::Accountant, self::Auditor => true,
            default => false,
        };
    }

    /** @return list<P> */
    public function permissions(): array
    {
        return match ($this) {
            self::PlatformSuperAdmin => P::platform(),

            self::CompanyOwner => P::tenant(),

            self::BranchManager => [
                P::CompanyView, P::BranchesView, P::StaffView, P::StaffManage, P::RolesView,
                P::PosAccess, P::PosDevicesView, P::PosDevicesManage, P::PaymentMethodsManage,
                P::ProductsView, P::ProductsCreate, P::ProductsUpdate, P::ProductsDeactivate, P::ProductsPricesManage,
                P::CategoriesManage, P::BrandsManage, P::UnitsManage,
                P::InventoryView, P::InventoryAdjust, P::InventoryAdjustApprove,
                P::InventoryTransfer, P::InventoryTransferReceive, P::InventoryLocationsManage,
                P::InventoryOpeningManage, P::InventoryOpeningPost,
                P::SuppliersView, P::SuppliersManage, P::CustomersView, P::CustomersManage,
                P::SalesView, P::SalesCreate, P::SalesVoid, P::SalesRefund, P::SalesDiscount,
                P::PurchasesView, P::PurchasesCreate, P::PurchasesApprove, P::PurchasesReceive, P::PurchasesQuickReceive,
                P::ExpensesView, P::ExpensesManage,
                P::ReportsView, P::ReportsCashierSales,
            ],

            self::Supervisor => [
                P::BranchesView, P::StaffView,
                P::PosAccess, P::PosDevicesView,
                P::ProductsView, P::InventoryView,
                P::CustomersView, P::CustomersManage,
                P::SalesView, P::SalesCreate, P::SalesVoid, P::SalesRefund, P::SalesDiscount,
                P::ReportsView, P::ReportsCashierSales,
            ],

            self::Cashier => [
                P::PosAccess, P::ProductsView, P::CustomersView, P::CustomersManage,
                P::SalesCreate,
            ],

            self::StoreKeeper => [
                P::BranchesView,
                P::ProductsView, P::ProductsCreate, P::ProductsUpdate,
                P::InventoryView, P::InventoryAdjust, P::InventoryTransfer, P::InventoryTransferReceive,
                P::InventoryOpeningManage,
                P::SuppliersView, P::PurchasesView, P::PurchasesCreate, P::PurchasesReceive,
            ],

            self::Accountant => [
                P::CompanyView, P::BranchesView, P::SubscriptionView,
                P::ProductsView, P::ProductsPricesManage, P::InventoryView,
                P::SuppliersView, P::SuppliersManage, P::CustomersView,
                P::SalesView, P::PurchasesView, P::PurchasesApprove,
                P::ExpensesView, P::ExpensesManage,
                P::ReportsView, P::ReportsFinancial, P::ReportsCashierSales,
            ],

            self::Auditor => [
                P::CompanyView, P::BranchesView, P::StaffView, P::RolesView, P::PosDevicesView,
                P::ProductsView, P::InventoryView, P::SuppliersView, P::CustomersView,
                P::SalesView, P::PurchasesView, P::ExpensesView,
                P::ReportsView, P::ReportsFinancial, P::ReportsCashierSales, P::AuditLogsView,
            ],
        };
    }

    /** @return list<self> */
    public static function tenantRoles(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->scope() === RoleScope::Tenant));
    }
}
