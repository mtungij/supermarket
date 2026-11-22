# Supermarket SaaS

Multi-tenant Supermarket Management & Point of Sale platform for small, medium
and large supermarkets. One platform hosts many independent supermarket
companies; each company runs one or more branches.

> **Product rule — Cashier Shift Management is excluded.**
> There is no Open Shift, Close Shift, shift handover, opening/closing cash or
> shift reconciliation, and no shift tables. Cashiers log in and use the POS
> directly according to their permissions. Every sale is linked to company,
> branch, cashier, POS device and timestamp, and cashier reports are built
> directly from sales transactions.

**Status:** Phase 1 (foundation), Phase 2 (product catalogue, purchasing
and inventory) and Phase 3 (online POS, sales, payments, returns) are
complete. See
[docs/development-roadmap.md](docs/development-roadmap.md) and
[docs/phase-2-validation.md](docs/phase-2-validation.md).

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.3+, tested on 8.4) |
| UI | Livewire 3, Alpine (bundled with Livewire), Tailwind CSS 4, Poppins (self-hosted at build) |
| Auth | Laravel Fortify |
| Database | MySQL 8.4 |
| Cache/queue | `database` by default, Redis via env when available (`phpredis`) |
| Locale | English (`en`) and Kiswahili (`sw`) |
| Defaults | Currency `TZS`, business timezone `Africa/Dar_es_Salaam` |

## Getting started

Requirements: PHP 8.3+ with `pdo_mysql`, Composer 2, Node 20+, and MySQL 8.4
(local or via Docker).

```bash
composer install
npm install
cp .env.example .env        # then set DB_* credentials
php artisan key:generate

# Optional: MySQL 8.4 + Redis + Mailpit for local development
docker compose up -d        # MySQL on 127.0.0.1:33084 (set DB_PORT=33084)

php artisan migrate --seed  # reference data only: roles, permissions, trial plan
php artisan tenants:provision-defaults   # only for companies created before Phase 2 (idempotent)
php artisan platform:create-admin   # interactive; the only way to create a platform admin
npm run build               # or: npm run dev
php artisan serve
```

Open http://localhost:8000 and **Register your supermarket** to onboard a
company.

### Tests

`php artisan test` runs everything, including `ConcurrentStockPostingTest`,
which forks two processes with separate MySQL connections (requires `pcntl`).
It commits data, then truncates and re-seeds the **test** database.

Tests run against a **separate** MySQL database, `supermarket_saas_test`
(see `phpunit.xml`). The base `TestCase` refuses to run against any database
whose name does not end in `_test`.

```bash
# once: create the test database (the compose file does this automatically)
#   CREATE DATABASE supermarket_saas_test; GRANT ALL ON supermarket_saas_test.* TO 'supermarket'@'%';
php artisan test
```

## Areas & routes

| Area | Path | Who |
|---|---|---|
| Public | `/`, `/login`, `/register`, `/forgot-password` | Guests |
| Platform | `/platform`, `/platform/companies`, `/platform/plans` | Platform Super Admin only |
| Company | `/company`, `/company/branches`, `/company/staff`, `/company/pos-devices`, `/company/subscription` | Company-wide roles (by permission) |
| Branch | `/branch` | Branch staff |
| Catalogue | `/catalogue/products`, `/catalogue/categories`, `/catalogue/brands`, `/catalogue/units`, `/catalogue/barcode-lookup` | `products.view` and finer permissions |
| Purchasing | `/purchasing/suppliers`, `/purchasing/orders`, `/purchasing/receipts` | `suppliers.view` / `purchases.*` |
| POS & sales | `/pos`, `/sales`, `/sales/reports`, `/sales/{uuid}/receipt`, `/customers`, `/settings/payment-methods` | `pos.access`, `sales.*`, `customers.*`, `reports.view`, `payment_methods.manage` |
| Inventory | `/inventory/stock`, `/inventory/movements`, `/inventory/adjustments`, `/inventory/transfers`, `/inventory/locations` | `inventory.*` (current branch from the branch selector) |

`/dashboard` redirects each user to the right area.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `SAAS_DEFAULT_CURRENCY` | `TZS` | Currency for new companies |
| `SAAS_DEFAULT_TIMEZONE` | `Africa/Dar_es_Salaam` | Business/display timezone for new companies |
| `SAAS_ONBOARDING_PLAN` | `trial` | Plan code given to newly registered companies |
| `SAAS_EMAIL_VERIFICATION` | `false` | Enables Fortify email verification for tenant routes |
| `SESSION_ENCRYPT` | `true` | Encrypt session payloads |
| `SESSION_SECURE_COOKIE` | `false` | **Set `true` in production (HTTPS)** |
| `INVENTORY_VAT_RATE` | `18.00` | Default rate for the `standard` tax class |
| `INVENTORY_EXPIRY_ALERT_DAYS` | `30` | Days ahead that batches are flagged as expiring |
| `PRODUCT_IMAGE_DISK` | `local` | **Private** disk for product images (served via an authorised route) |

## Documentation

- [Architecture](docs/architecture.md)
- [Database design](docs/database-design.md)
- [Tenant security](docs/tenant-security.md)
- [Offline POS design](docs/offline-pos-design.md)
- [Development roadmap](docs/development-roadmap.md)
- [Product catalogue](docs/product-catalogue.md)
- [Unit conversions](docs/unit-conversions.md)
- [Inventory ledger & costing](docs/inventory-ledger.md)
- [Purchasing workflow](docs/purchasing-workflow.md)
- [Phase 2 validation](docs/phase-2-validation.md)
- [POS, sales & returns](docs/pos-and-sales.md)
