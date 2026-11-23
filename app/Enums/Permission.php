<?php

namespace App\Enums;

/**
 * Every permission known to the platform.
 *
 * Platform permissions (prefix "platform.") can only be granted to
 * platform-scoped roles. All other permissions are tenant permissions and
 * are evaluated against the user's membership in the *current* company.
 */
enum Permission: string
{
    // Platform administration
    case PlatformAccess = 'platform.access';
    case PlatformCompaniesView = 'platform.companies.view';
    case PlatformCompaniesManage = 'platform.companies.manage';
    case PlatformPlansManage = 'platform.plans.manage';

    // Company
    case CompanyView = 'company.view';
    case CompanyManage = 'company.manage';
    case SettingsManage = 'settings.manage';
    case SubscriptionView = 'subscription.view';
    case SubscriptionManage = 'subscription.manage';

    // Branches & staff
    case BranchesView = 'branches.view';
    case BranchesManage = 'branches.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case RolesView = 'roles.view';

    // POS
    case PosAccess = 'pos.access';
    case PosDevicesView = 'pos_devices.view';
    case PosDevicesManage = 'pos_devices.manage';
    case PaymentMethodsManage = 'payment_methods.manage';

    // Catalogue
    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDeactivate = 'products.deactivate';
    case ProductsPricesManage = 'products.prices.manage';
    case CategoriesManage = 'categories.manage';
    case BrandsManage = 'brands.manage';
    case UnitsManage = 'units.manage';

    // Inventory
    case InventoryView = 'inventory.view';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryAdjustApprove = 'inventory.adjust.approve';
    case InventoryTransfer = 'inventory.transfer';
    case InventoryTransferReceive = 'inventory.transfer.receive';
    case InventoryLocationsManage = 'inventory.locations.manage';
    /** Prepare / edit opening-stock drafts (initial stock before using the system). */
    case InventoryOpeningManage = 'inventory.opening.manage';
    /** Post opening stock (irreversible; creates opening_stock movements). */
    case InventoryOpeningPost = 'inventory.opening.post';
    /** Post opening-stock lines with an explicitly confirmed ZERO cost. */
    case InventoryOpeningZeroCost = 'inventory.opening.zero_cost';

    // Trading partners
    case SuppliersView = 'suppliers.view';
    case SuppliersManage = 'suppliers.manage';
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';

    // Sales
    case SalesView = 'sales.view';
    case SalesCreate = 'sales.create';
    case SalesVoid = 'sales.void';
    case SalesRefund = 'sales.refund';
    case SalesDiscount = 'sales.discount';

    // Purchases & expenses
    case PurchasesView = 'purchases.view';
    case PurchasesCreate = 'purchases.create';
    case PurchasesApprove = 'purchases.approve';
    case PurchasesReceive = 'purchases.receive';
    /** Receive a draft/submitted order directly; approval is recorded automatically. */
    case PurchasesQuickReceive = 'purchases.quick_receive';
    case ExpensesView = 'expenses.view';
    case ExpensesManage = 'expenses.manage';

    // Reporting & audit
    case ReportsView = 'reports.view';
    case ReportsFinancial = 'reports.financial';
    case ReportsCashierSales = 'reports.cashier_sales';
    case AuditLogsView = 'audit_logs.view';

    public function scope(): RoleScope
    {
        return str_starts_with($this->value, 'platform.') ? RoleScope::Platform : RoleScope::Tenant;
    }

    public function group(): string
    {
        return $this->scope() === RoleScope::Platform
            ? 'platform'
            : explode('.', $this->value)[0];
    }

    public function label(): string
    {
        return ucfirst(str_replace(['.', '_'], ' ', $this->value));
    }

    /** @return list<self> */
    public static function tenant(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p->scope() === RoleScope::Tenant));
    }

    /** @return list<self> */
    public static function platform(): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => $p->scope() === RoleScope::Platform));
    }
}
