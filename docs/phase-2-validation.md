# Phase 2 validation report

Scope: product catalogue, purchasing and inventory management.
Environment: PHP 8.4.1, Laravel 13.43.0, Livewire 3.8.12, Fortify 1.43.0,
MySQL 8.4.13 (Docker container `supermarket-saas-mysql`, dedicated database
`supermarket_saas_test`), Node 25.8.1 / Vite 8.
Date: 2026-11-22.

> **Cashier Shift Management:** none. A code scan of `app`, `database`,
> `routes`, `resources`, `config`, `lang` and `tests` finds no shift
> implementation, no shift migrations and no shift tables in the MySQL test
> schema. `ProductRulesTest` guards this permanently.

## Automated tests (all executed, MySQL 8.4)

`php artisan test`: **163 tests, 163 passed, 894 assertions, 0 failures.**

| Area | Test class | Tests |
|---|---|---|
| Phase 1 regression | Authentication, BranchAuthorization, CompanyMembership, CompanyRegistration, CrossCompanyManipulation, PageRendering, StaffPermissions, SubscriptionLimits, TenantIsolation, Unit/ProductRules | 78 |
| Product & category CRUD, defaults | `Inventory\CatalogueServicesTest` | 10 |
| Unit conversions | `Inventory\UnitConversionTest` | 7 |
| Barcode uniqueness & lookup | `Inventory\BarcodeTest` | 6 |
| Branch-, unit-specific & dated prices | `Inventory\PricingTest` | 6 |
| Supplier ownership, PO transitions | `Inventory\PurchaseOrderTest` | 9 |
| Partial/full GRN, duplicate prevention, WAC | `Inventory\GoodsReceiptTest` | 10 |
| Concurrent stock posting (forked processes) | `Inventory\ConcurrentStockPostingTest` | 2 |
| Insufficient stock, adjustments & approvals | `Inventory\StockAdjustmentTest` | 7 |
| Transfer lifecycle | `Inventory\StockTransferTest` | 6 |
| Expiry & batch tracking | `Inventory\BatchExpiryTest` | 4 |
| Ledger immutability & balance integrity | `Inventory\StockLedgerIntegrityTest` | 3 |
| Pages, role access, branch access, labels, Kiswahili | `Inventory\Phase2PagesTest` | 6 |
| Cross-company via HTTP **and** Livewire | `Inventory\Phase2CrossTenantTest` | 4 |
| Product image security | `Inventory\ProductImageTest` | 3 |
| UI end-to-end flow, subscription read-only | `Inventory\SubscriptionAndWorkflowUiTest` | 2 |
| **Total** | | **163** |

Coverage of the required list:

| # | Requirement | Tests |
|---|---|---|
| 1 | Tenant isolation across Phase 2 models | Phase2CrossTenant, PurchaseOrder (foreign supplier), Catalogue (foreign category), Barcode (lookup per company) |
| 2 | Product & category CRUD | CatalogueServices |
| 3 | Barcode uniqueness & lookup | Barcode |
| 4 | Product-specific conversions | UnitConversion |
| 5 | Branch-specific prices | Pricing |
| 6 | Supplier ownership | PurchaseOrder, Phase2CrossTenant |
| 7 | PO status transitions | PurchaseOrder, GoodsReceipt (close) |
| 8 | Partial & full GRN | GoodsReceipt, SubscriptionAndWorkflowUi |
| 9 | Duplicate GRN prevention | GoodsReceipt, ConcurrentStockPosting |
| 10 | Concurrent stock posting | ConcurrentStockPosting |
| 11 | Insufficient stock protection | StockAdjustment, StockTransfer, ConcurrentStockPosting |
| 12 | Adjustments & approvals | StockAdjustment |
| 13 | Transfer lifecycle | StockTransfer |
| 14 | Expiry & batch tracking | BatchExpiry |
| 15 | Subscription & branch permissions | SubscriptionAndWorkflowUi, Phase2Pages, PurchaseOrder, StockTransfer, Pricing |
| 16 | Product image security | ProductImage |
| 17 | Phase 1 regression | the 78 Phase 1 tests |

### Concurrency runs

`ConcurrentStockPostingTest` was run **5 times in a row: 5/5 passed (10/10
test executions)**.

- The first full-suite run found a **real deadlock** (MySQL 1213) between two
  concurrent adjustment approvals. The cause was `INSERT IGNORE` taking shared
  locks before `SELECT … FOR UPDATE`.
- The fix was `LockedRow::lockOrCreate` plus `attempts: 3` deadlock retries
  on posting transactions, applied to balances, costs, batches and document
  sequences. See `inventory-ledger.md`.
- After the fix, every run passes.

### Other defects found and fixed during validation

- **`ownedBelongsTo()` built the wrong foreign key.** Laravel guessed the
  relation name from the wrapper method. It now passes the caller's relation
  name explicitly.
- **Product save and image upload were not atomic.** A rejected image left a
  new product behind. Both now run in one transaction.
- **A disguised upload passed form validation.** A PHP payload named
  `shell.png` passed Laravel's `image`/`mimes` rules under Livewire's upload
  handling. The GD decode and re-encode step rejected it. Both layers are
  kept and documented.

## Builds & static checks

| Check | Result |
|---|---|
| `npm run build` | ✓ built (Vite 8; app CSS 62.78 kB, gzip 10.50 kB) |
| `vendor/bin/pint` | passes (style fixes applied) |
| `php artisan view:cache` | all Blade templates compile |
| Whitespace check | The project is not a git repository, so `git diff --check` has no baseline. `git diff --no-index --check /dev/null <file>` was run over every authored source, test, doc and config file: **0 issues**. The only hits are Laravel's generated, git-ignored `bootstrap/cache/*.php` |
| Routes | 24 Phase 2 routes, all inside `auth` + `tenant` (+ `can:`) |

## Development database

- A full backup was taken first:
  `storage/app/private/backups/supermarket_saas-before-phase2-20261122-093329.sql`.
- The Phase 2 migrations were already applied (batch 2) by the developer
  during the session. `php artisan migrate` reported nothing to migrate.
  `migrate:fresh` was never run on the dev database.
- `RolesAndPermissionsSeeder` synced the 50 permissions and removed the three
  Phase 1 placeholders (`products.manage`, `inventory.receive`,
  `purchases.manage`).
- `tenants:provision-defaults` backfilled the pre-Phase 2 company (+12 units,
  +15 categories, +3 locations). The company registered after Phase 2
  already had its defaults (+0).
- From the application log: one registration attempt at 06:19 UTC failed
  because the Phase 2 tables did not exist yet, and onboarding rolled back
  completely. Four "view not found" errors at 06:21–06:22 came from pages
  opened while their views were still being written. All those pages now
  render and are covered by `Phase2PagesTest`.

## Known limitations (not implemented in Phase 2)

- **Valuation:** no historical as-of-date valuation, FIFO or lot costing,
  landed costs, COGS or accounting export. Weighted average per branch only.
- **Transfers:** receipt is all-or-nothing. Shortages are recorded afterwards
  with an adjustment.
- **Adjustments:** every adjustment needs approval, and approvers may approve
  their own requests. There are no value thresholds or four-eyes rule.
- **Suppliers:** the balance includes receipts only. Payments, supplier
  returns and credit notes are not implemented.
- **Batches:** no automatic FEFO allocation. Users pick the batch on
  adjustments and transfers. FEFO is planned for the POS.
- **Alerts:** low-stock and expiry alerts are computed on screen. There are
  no email or SMS notifications.
- **Negative stock:** the `allow_negative_stock` company setting exists in
  the ledger but has no UI yet (default: not allowed).
- **Prices:** prices on PO lines are typed manually. There are no supplier
  price lists.
- **Weighed products:** price-embedded scale barcodes (prefix 21–29 with
  weight or price) are not parsed yet. That belongs to the POS phase.
- **Images:** if the database commit fails after the image file was written,
  an orphan file can remain. No cleanup job exists yet.
- **Kiswahili:** all new UI strings are translated, but a native speaker
  should still review them.
- **No POS checkout, offline sync or sales.** These are out of scope by
  design.

## Addendum (2026-11-22): purchasing & receiving redesign, transfer scan fix

- **Root cause of the transfer scan bug:** the scan input's `<form>` was
  nested inside the transfer modal's `<form>`. Browsers discard nested form
  tags, so the scanner's Enter submitted the outer form (`save()`, which
  failed validation) and `scanBarcode` never ran. The adjustment form had
  the same latent bug. Both now use a form-free scan input.
- `php artisan test`: **189 tests, 189 passed, 2,097 assertions**.
  `ConcurrentStockPostingTest` was run 5× in a row: 5/5 passed.
- `npm run build`: ✓. Pint: passes. Whitespace check: 0 issues.
- Dev database: backed up to
  `storage/app/private/backups/supermarket_saas-before-receiving-redesign-20261122-123741.sql`
  before applying the additive migration and the permission sync. The
  existing PO, GRN and stock movement were unchanged, and the existing GRN
  renders on the new page.
