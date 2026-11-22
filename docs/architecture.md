# Architecture

## Overview

A single Laravel 13 application serves three areas:

1. **Platform**: the SaaS operator (Platform Super Admin).
2. **Company**: company-wide management (owner, accountant, auditor, and
   anyone else with the relevant permissions).
3. **Branch**: branch staff (branch manager, supervisor, cashier, store keeper).

Tenancy uses a **shared database with a shared schema**. Every tenant-owned
row carries a `company_id`.

> **Cashier Shift Management is excluded from the product.** No component,
> table, permission or screen may introduce shifts. Sales attribution uses
> company, branch, cashier (user), POS device and timestamp.

## Request flow (tenant area)

```
web group: StartSession → … → EnsureUserIsActive → SetLocale
route:     auth → verified.optional → tenant (ResolveTenantContext) → SubstituteBindings → can:…
```

- `EnsureUserIsActive` logs out deactivated users immediately.
- `ResolveTenantContext` uses `TenantResolver` to choose the company and
  branch from the user's **active memberships in active companies** and
  stores them in the request-scoped `TenantContext`.
- The middleware priority list makes the tenant resolve **before route model
  binding**, so `{branch}` and similar bindings go through the tenant-scoped
  query.
- Livewire update requests re-run `ResolveTenantContext` as persistent
  middleware. Components also use the `RequiresTenant` trait as a second
  safeguard.

## Code layout

| Path | Responsibility |
|---|---|
| `app/Support/Tenancy/` | `TenantContext` (request-scoped state), `TenantResolver` (membership → context), `CompanyScope` (fail-closed global scope), `TenantViolationException` |
| `app/Models/Concerns/BelongsToCompany.php` | Trait for tenant-owned models: scope, `company_id` assignment and immutability |
| `app/Enums/` | `Permission` and `SystemRole` (single source of truth for RBAC), status enums, `PlanLimit` |
| `app/Authorization/PermissionGates.php` | Registers a Gate ability per permission |
| `app/Policies/` | Model policies (permission **and** company **and** branch access) |
| `app/Services/` | Domain services: `CompanyOnboardingService`, `BranchService`, `StaffService`, `PosDeviceService`, `SubscriptionLimiter` |
| `app/Http/Middleware/` | Active-user, locale, tenant, platform and optional email verification middleware |
| `app/Livewire/` | Interactive screens (branch manager, POS device manager) |
| `app/Support/Navigation/` | Sidebar definitions, filtered by permission server-side |
| `app/View/Composers/` | Layout data (navigation, company/branch selectors) |
| `resources/views/components/layouts/` | `base`, `guest`, and the three area layouts `platform`, `company`, `branch` |

Business logic lives in services, policies and the resolver. Blade templates
only render prepared data.

### Phase 2 additions

| Path | Responsibility |
|---|---|
| `app/Services/Catalogue/` | Products, categories, brands, units, **unit conversions**, barcodes, effective-dated **pricing**, product images, default provisioning |
| `app/Services/Purchasing/` | Suppliers, purchase-order state machine, **goods receipts** |
| `app/Services/Inventory/` | **`StockLedger`** (the only code that changes stock), batches, adjustments, transfers, locations, read queries |
| `app/Services/Support/` | Document numbering, subscription read-only guard, in-service `Authorizer` |
| `app/Support/Decimal.php` | bcmath decimal arithmetic and rounding (no floats) |
| `app/Support/LockedRow.php` | Deadlock-safe lock-or-create |
| `app/Support/Barcode/` | GTIN check digits, EAN-13 / Code 128 SVG |
| `app/Livewire/{Catalogue,Purchasing,Inventory}/` | Module screens (layout `components.layouts.tenant` chooses the company or branch layout) |

Conventions:

- **Authorisation happens twice.** Routes carry `can:` middleware, and the
  components and services check again: `Authorizer::permission()` and
  `Authorizer::branch()` run *inside* the sensitive services (approve PO,
  post GRN, approve adjustment, dispatch and receive transfers, set prices).
  Calling a Livewire action directly therefore still fails without the
  permission.
- **Relations between same-company records** use `ownedHasMany` /
  `ownedBelongsTo`. These drop the fail-closed tenant scope, which is safe
  because composite FKs guarantee both sides share a company, so relations
  work in console, queue and test code. Top-level queries stay scoped.
  Services that receive a `Company` use `Model::forCompany($company)`.
- **Livewire components** never hold model instances as public state. They
  keep `#[Locked]` UUIDs and reload through tenant-scoped queries on every
  request.
- **Transaction boundaries.** One business operation is one DB transaction.
  Posting operations use `attempts: 3` to retry deadlocks. Row locks are
  taken in a fixed order (document → order → order line → cost row → balance
  row, lines sorted by product id).

## Authentication

Fortify provides login, logout, password reset and optional email
verification (`SAAS_EMAIL_VERIFICATION`). Its registration endpoint is the
**company onboarding** flow (`App\Actions\Fortify\CreateNewUser` →
`CompanyOnboardingService`).

- Passwords are hashed with bcrypt via the `hashed` cast. Strength rules come
  from `Password::defaults()`, which is stricter in production and adds a
  breached-password check there.
- Login is throttled to 5 attempts per minute per email and IP. Registration
  is throttled to 5 attempts per hour per IP.
- The session is regenerated on login and invalidated on logout. Session
  payloads are encrypted, and cookies are `HttpOnly` with `SameSite=Lax`.
  Set `SESSION_SECURE_COOKIE=true` behind HTTPS.
- Two-factor authentication and passkeys were removed from the Fortify
  scaffold for Phase 1 and are candidates for a later hardening phase.

## Roles & permissions

- Permissions are defined in `App\Enums\Permission`. Roles and their default
  permission sets are defined in `App\Enums\SystemRole`. The seeder
  (`RolesAndPermissionsSeeder`) syncs both into the database and is safe to
  re-run.
- **Platform scope:** the Platform Super Admin role is attached to
  `users.platform_role_id`. It can never be attached to a company membership.
- **Tenant scope:** each `company_user` membership has exactly one role.
  Built-in tenant roles have `company_id = NULL`. Company-specific custom
  roles can be added later with `roles.company_id` set.
- Every permission check runs on the server: `$user->can('sales.create')`, the
  `can:` route middleware, `@can`, and policies. There is **no `Gate::before`
  super-user bypass**: a platform admin has no tenant permissions.
- Branch access: memberships with `all_branches = true` (owner, accountant,
  auditor by default) see every branch. Other members see only the branches
  listed in `branch_company_user`.

| Role | Scope | Branches | Highlights |
|---|---|---|---|
| Platform Super Admin | platform | n/a | Platform area only |
| Company Owner | tenant | all | Every tenant permission |
| Branch Manager | tenant | assigned | Branch operations, POS devices, staff view/manage |
| Supervisor | tenant | assigned | POS, voids/refunds/discounts, cashier reports |
| Cashier | tenant | assigned | POS access, create sales, customers |
| Store Keeper | tenant | assigned | Products, inventory, receiving |
| Accountant | tenant | all | Purchases, expenses, financial reports |
| Auditor | tenant | all | Read-only everything + audit logs |

## Subscriptions

Phase 2 adds **read-only mode**. Without a usable subscription, catalogue,
purchasing and inventory data stay viewable, but creating products and
creating or posting documents is refused (`SubscriptionGuard`).


`SubscriptionPlan` defines the limits (`max_branches`, `max_staff`,
`max_pos_devices`, where `NULL` means unlimited), the duration and the trial
length. `SubscriptionLimiter` checks limits inside a transaction while holding
a lock on the company row, so two concurrent requests cannot both pass a
check. A company without a usable subscription keeps read access to its data
but cannot add branches, staff or devices. No payment gateway is integrated in
Phase 1.

## Time, currency and locale

- Timestamps are **stored in UTC** (`config/app.php`). Each company has a
  `timezone`, which defaults to `Africa/Dar_es_Salaam`, used for display and
  business dates. This avoids ambiguity when offline devices sync later.
- Each company has a `currency`, which defaults to `TZS`. Its display
  precision is set by `settings.currency_decimal_places`, which defaults to 0.
- Locale is chosen in this order: session, then the user's `locale`, then
  `APP_LOCALE`. UI strings are in `lang/sw.json`. Framework messages are in
  `lang/sw/*.php`. Any missing key falls back to English.

## Infrastructure

The app is stateless and configured through env variables, so it is ready for
containers. `compose.yaml` provides MySQL 8.4, Redis and Mailpit for local
development. Cache and queues default to the `database` driver. Switch them to
`redis` with `CACHE_STORE`/`QUEUE_CONNECTION` when a Redis server is
available. The `phpredis` extension is already supported.
