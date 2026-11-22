# Database design (Phase 1)

MySQL 8.4, `utf8mb4`. The database is shared by all tenants and uses one
shared schema. Tenant-owned tables carry `company_id`.

> No shift tables exist, and none may be added. Cashier Shift Management is
> excluded from the product. This still holds after Phase 2.

## Entity overview

```
subscription_plans 1─* subscriptions *─1 companies 1─* branches 1─* pos_devices
                                             │
users 1─* company_user (membership) *─1 companies
              │  └─*─ roles ─*─ permissions   (permission_role)
              └─* branch_company_user *─1 branches
users *─1 roles (platform_role_id, platform scope only)
```

## Tables

### `users` (global)
| Column | Notes |
|---|---|
| id, name, email (unique), phone | |
| password | bcrypt hash |
| locale | `en` / `sw` preference |
| is_active | Inactive users cannot log in and are logged out mid-session |
| platform_role_id | FK `roles`. NULL for tenant users. Not mass-assignable |
| last_login_at, last_login_ip | |

### `companies` (tenant root)
uuid (unique, public identifier), name, slug (unique), email, phone, address,
country_code (`TZ`), currency (`TZS`), timezone (`Africa/Dar_es_Salaam`),
locale, status (`active`/`suspended`), settings (JSON), owner_user_id (FK
users), soft deletes.

### `branches` (tenant-owned)
uuid, **company_id**, name, code, phone, email, address, is_head_office,
is_active, soft deletes.
- `UNIQUE(company_id, code)`
- `UNIQUE(id, company_id)`: the target for composite foreign keys (see below)

### `roles`
company_id (NULL = built-in template), scope (`platform`/`tenant`), slug,
name, description, is_system, all_branches. `UNIQUE(company_id, slug)`.

### `permissions`, `permission_role`
`permissions.slug` is unique and mirrors `App\Enums\Permission`.
`permission_role` has a composite primary key.

### `company_user` (membership)
company_id, user_id, role_id, is_active, all_branches, invited_by, joined_at,
deactivated_at.
- `UNIQUE(company_id, user_id)`: one membership per user per company
- `UNIQUE(id, company_id)`: composite FK target
- `INDEX(user_id, is_active)`: used when the tenant is resolved

### `branch_company_user`
company_id, company_user_id, branch_id. `UNIQUE(company_user_id, branch_id)`.
- FK `(company_user_id, company_id)` → `company_user(id, company_id)`
- FK `(branch_id, company_id)` → `branches(id, company_id)`

### `subscription_plans` (platform)
code (unique), name, description, price `DECIMAL(14,2)`, currency,
duration_days, trial_days, max_branches / max_staff / max_pos_devices (NULL =
unlimited), is_active, is_public, sort_order.

### `subscriptions` (tenant-owned)
company_id, subscription_plan_id, status
(`trialing`/`active`/`past_due`/`expired`/`cancelled`), starts_at,
trial_ends_at, ends_at, cancelled_at. `INDEX(company_id, status, ends_at)`.

### `pos_devices` (tenant-owned)
uuid, company_id, branch_id, name, device_identifier, status
(`active`/`inactive`/`revoked`), receipt_paper_width (58/80), last_seen_at,
last_synced_at, registered_by, soft deletes.
- FK `(branch_id, company_id)` → `branches(id, company_id)`
- `UNIQUE(company_id, device_identifier)`

## Phase 2 tables

All of these are company-scoped and use composite FKs. Branch-level rows also
use `(location_id, branch_id) → stock_locations(id, branch_id)`, and batches
use `(batch_id, product_id) → stock_batches(id, product_id)`. Long FK names
are shortened by the `tenantForeign()` Blueprint macro (MySQL's 64-character
limit).

| Table | Key columns / constraints |
|---|---|
| `units` | code (unique per company), dimension `count\|mass\|volume\|length`, ratio `DECIMAL(20,9)` (NULL for count), is_system |
| `categories` | parent_id (self composite FK), name, **name_key** unique per company |
| `brands` | name, **name_key** unique per company |
| `products` | category, brand, **base_unit**, sku unique per company, product_type, tax_class, purchase_cost `(18,4)`, selling_price `(15,2)`, min/reorder `(18,4)`, track_batches/expiry |
| `product_units` | product × unit unique, **factor `(18,6)`**, is_base, can_purchase/sell |
| `product_barcodes` | **barcode unique per company**, product_unit (base or packaging), type, is_primary |
| `product_prices` | append-only: product_unit, branch (NULL = all), price `(15,2)`, effective_from |
| `suppliers` | name_key unique per company, contact, TIN |
| `supplier_transactions` | append-only liability ledger, unique per source document |
| `stock_locations` | branch, code unique per branch, type |
| `stock_batches` | product × batch_number unique, manufactured_on, expires_on |
| `stock_balances` | location × product × **batch_key** (generated `IFNULL(batch_id,0)`) unique, quantity `(18,4)` |
| `inventory_costs` | branch × product unique: quantity, average_cost, last_cost `(18,4)` |
| `stock_movements` | **immutable**: signed quantity, unit/total cost, balance & WAC after, entered unit/qty/factor snapshot, source (morph), reference, user; **unique (company, source_type, source_id, type)** |
| `purchase_orders` / `purchase_order_lines` | reference unique per company, status, totals; line factor/base snapshot, received_quantity_base |
| `goods_receipts` / `goods_receipt_lines` | PO, location (same branch), status, supplier reference; line → PO line composite FK `(purchase_order_line_id, purchase_order_id)`, cost snapshots, batch |
| `stock_adjustments` / `_lines` | reason, status, requester/approver; direction, qty snapshot, posted cost |
| `stock_transfers` / `_lines` | source/destination branch + location (composite FKs), status, dispatch/receive audit; line cost snapshot |
| `document_sequences` | per-company counters (PO, GRN, ADJ, TRF, internal barcodes) |
| `document_events` | append-only status audit for all documents |

**Receiving redesign (`2026_11_22_000600`, additive only):**
- `purchase_orders.submission_key`, `stock_transfers.submission_key` and
  `goods_receipts.submission_key`, each unique per company, for idempotent
  submissions.
- `goods_receipts`: `delivery_note_number`, `supplier_invoice_number`,
  `goods_value`, `landed_cost_total`.
- `goods_receipt_lines`: per-line `location_id` (composite FK to
  `stock_locations(id, company_id)`; the service also enforces the order's
  branch), `goods_value`, `tax_amount`, `landed_cost_amount`,
  `landed_unit_cost_base`, `notes`.
- New table `goods_receipt_costs` (cost_type, description, amount).

Historical rows keep NULL or 0 in the new columns, and the UI falls back to
the original fields.

**Phase 3 (`2026_11_23_000100`, additive only):**
- `stock_locations.is_sellable`: only existing Sales Floor rows were set to
  true.
- `pos_devices.stock_location_id`: composite FK
  `(stock_location_id, branch_id)`, plus `UNIQUE(id, company_id)`.
- New tables:
  - `customers`: `phone_key` unique per company.
  - `payment_methods`: per company; types cash, mobile_money, card,
    bank_transfer, other, exchange_credit.
  - `sales`: unique `(company, sale_number)` and `(company, client_uuid)`;
    holds totals, net, tax, tendered, change and COGS, plus cashier,
    device, location and customer.
  - `sale_items`: snapshots.
  - `sale_item_allocations`: per lot; the source of each `sale` movement.
  - `sale_payments`: applied, tendered and change amounts.
  - `sale_returns` and `sale_return_items`: per allocation, with location,
    disposition and original cost.
- Sales, items, allocations, payments and returns are immutable. There are
  no shift tables.

Polymorphic source names use a fixed morph map (`goods_receipt_line`,
`stock_adjustment_line`, `stock_transfer_line`, …) rather than class names.

## Cross-tenant integrity at the database level

Composite foreign keys on `(x_id, company_id)` mean the **database itself**
rejects a branch assignment or a POS device that points at a branch of a
different company, even if application code were bypassed.
`BranchAuthorizationTest` covers this. Future tenant tables that reference a
branch, such as sales and stock, must use the same pattern.

## Conventions for future tables

- Tenant-owned tables: `company_id` (FK, `restrictOnDelete`), the
  `BelongsToCompany` trait, and `company_id` kept out of `$fillable`.
- Branch-owned tables: also `branch_id` with a composite FK to
  `branches(id, company_id)`.
- Public identifiers: UUID (`HasUuids` with `uniqueIds() = ['uuid']`). Keep
  integer primary keys internal.
- Money: `DECIMAL(14,2)` or larger. Never floats.
- Timestamps: UTC.
- Offline-created records, such as sales, use a client-generated UUID with a
  unique index for idempotent sync (see `offline-pos-design.md`).
- Sales reference `cashier_user_id`, `pos_device_id`, `branch_id`,
  `company_id` and `transacted_at`. **They never reference a shift.**
