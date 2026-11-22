# Product catalogue

**Phase 2 · Catalogue, Purchasing & Inventory.** No Cashier Shift Management
exists in this module or anywhere else in the product.

## Ownership model

- Categories, brands, units, products, barcodes and prices all belong to a
  **company**. Every table has `company_id`, and every reference between them
  is a composite foreign key `(x_id, company_id)`.
- A product is **one record per company**, shared by all branches. Prices,
  stock balances and costs are what differ per branch, so nothing is
  duplicated per branch.
- Records are selected in the UI by UUID and resolved through tenant-scoped
  queries. Services re-check every related id against the company.

## Categories

- Name (unique per company after normalisation), optional description,
  optional parent category, active flag.
- **Normalised uniqueness:** names are compared on `name_key` (trimmed,
  internal whitespace collapsed, lower-cased, accents folded). So
  `"  soft   DRINKS "` collides with `"Soft Drinks"`, but `"Coca-Cola"` and
  `"Coca Cola"` stay distinct.
- Cycles are rejected: a category cannot sit inside itself or one of its
  descendants.
- **Deletion** is only allowed when no product and no sub-category refers to
  the category. Otherwise deactivate it. A restrict FK enforces this at the
  database level too.
- **Defaults:** every new company receives 15 categories (Beverages, Food &
  Groceries, Dairy Products, Bakery, Snacks, Household Items, Personal Care,
  Baby Products, Health & Beauty, Frozen Foods, Fruits & Vegetables, Meat &
  Seafood, Stationery, Electronics, Other Products) from
  `CompanyDefaultsProvisioner`. The provisioner is idempotent: it inserts
  only what is missing (matched by `name_key`) and never touches another
  company. Backfill existing companies with
  `php artisan tenants:provision-defaults`.

## Brands

Name (normalised-unique per company), description, active flag. The brand
list shows product counts.

## Products

| Field | Notes |
|---|---|
| name, description | |
| sku | Unique per company. Upper-cased, `A-Z 0-9 - _ . /`, max 64. Generated (`SKU-XXXXXXXX`) if empty |
| category, brand | Must be active records of the same company |
| base unit | The inventory unit. **All stock is held in this unit.** See [unit-conversions.md](unit-conversions.md) |
| product type | `standard` (counted; base unit must be a count unit; whole base quantities), `weighed` (base unit mass), `variable_measurement` (base unit mass, volume or length) |
| tax class | `standard` (VAT, default 18% via `INVENTORY_VAT_RATE`), `zero_rated`, `exempt` |
| purchase_cost | Latest purchase cost per base unit (4 dp). Updated by each posted GRN. Informational only |
| selling_price | Default company-wide price per base unit (2 dp). See *Pricing* |
| reference_buying_price | Optional reference buying price (4 dp, nullable). Only the default unit cost on new purchase-order lines before the first receipt; never used for stock value, adjustments or profit |
| min_stock_level, reorder_level | Base units. Drive low-stock alerts |
| track_batches, track_expiry | Lot and expiry tracking. Cannot be switched once stock movements exist |
| image | Optional. See *Images* |
| is_active | Deactivate instead of delete |

The base unit and product type **cannot change once stock movements or
purchase lines exist**.

## One-step registration (Add product)

`/catalogue/products/create` (`App\Livewire\Catalogue\ProductForm`) registers an ordinary product on one screen:

**Product name + Category + Unit + Selling price (+ optional barcode) → Save → ready for POS.**

### The form
- **Essentials:**
  - Product details: name, barcode (optional; a scanner's Enter moves to the next field and never submits), SKU (optional; generated if empty), category, brand.
  - Unit and price: product type (default Standard), unit (default Piece; switches to Kilogram when the type changes to Weighed), selling price, reference buying price, tax type.
  - The tax type defaults to the company setting `default_tax_class`, falling back to Standard.
- **Quick add:** "+ New category" and "+ New brand" add an entry without leaving the form. They need `categories.manage` / `brands.manage`, and duplicate names are refused.
- **Advanced settings** (collapsed by default):
  - barcode type, additional barcodes, and an explicit "create an internal barcode for me" option;
  - cartons and packs: unit, factor, barcode and optional price;
  - minimum stock, reorder level, batch and expiry tracking, description, image.

### What Save writes
`ProductService::create()` writes everything in **one transaction**, so if anything fails, nothing is saved:
- the product and its base unit (factor 1, purchasable and sellable);
- the primary barcode (`BarcodeService`, unique per company);
- extra barcodes and packaging units (`UnitConversionService`, so g/ml/l factors are derived and wrong factors refused; packaging barcodes are linked to their unit);
- the initial selling price through `PriceService::setPrice()`. That creates a company-wide, base-unit price-history record (note "Initial price") and syncs `products.selling_price`, so the product immediately shows the right price in Product Details, Barcode Lookup, POS search and POS scanning.

No stock, GRN, supplier invoice or cost is created. Stock still comes only from goods receipts and the other inventory workflows.

### Permissions
- Users without `products.prices.manage` (e.g. Store keeper) can register products, but the price field is disabled.
- Such a product has no price, and the POS refuses to sell any product whose price is 0 ("has no selling price yet").

### Saving
- **Save product** opens Product Details.
- **Save & add another** keeps category, brand, unit, type and tax, clears everything else and puts the cursor back in Product name.
- A per-form idempotency key (cache lock) makes a double-clicked Save create one product.

### Editing (same layout)
- Name, category, brand, SKU, tax, the reference buying price and stock settings can be changed.
- A changed selling price is written as a **new** effective-dated price record; history and sale snapshots are kept.
- A first barcode can be added to a product that has none. Existing barcodes, units and packaging are managed on Product Details, where changes that would affect past documents are checked.
- The product type and base unit are locked once stock or purchase records exist.

## Barcodes

`product_barcodes(company_id, product_id, product_unit_id, barcode, type, is_primary)`

- **Unique per company** (`UNIQUE(company_id, barcode)`). The same GTIN may
  exist in two different companies.
- Each barcode maps to exactly **one product and one unit**. Scanning a carton
  barcode therefore resolves to "1 carton = 24 pieces" of that product.
- Types:
  - `manufacturer`: the GTIN printed by the manufacturer. EAN-8, UPC-A,
    EAN-13 and GTIN-14 are check-digit validated.
  - `packaging`: a GTIN on a case or carton. It must be linked to a non-base
    (packaging) unit and is check-digit validated.
  - `internal`: generated by the system for unlabelled goods. Internal codes
    are EAN-13 in the GS1 restricted-circulation range (prefix `20`), drawn
    from a per-company sequence with a valid check digit.
- One barcode per product is primary (used on labels).
- **Lookup** (`BarcodeService::lookup`) checks the exact barcode first, then
  the SKU, and returns product, unit and factor. This is the entry point for
  the future POS. The **Barcode lookup** page and every document line picker
  accept input from ordinary USB scanners, which type the code and press
  Enter.
- **Scan inputs** on the purchase, transfer and adjustment forms share one
  partial (`livewire/partials/product-picker.blade.php`). Enter is handled
  by the input itself (`wire:keydown.enter.prevent`), and the input must
  **never be placed inside a `<form>`.** Browsers drop nested form tags, so
  Enter would submit the outer form. That was the cause of the transfer
  scanning bug, and `BarcodeScanMarkupTest` guards against it. An **Add**
  button covers touch devices. No scanner-specific character mapping is
  done in the application.
- **Labels:** `GET /catalogue/products/{uuid}/labels` produces a printable A4
  grid or a 50×30 mm roll. Barcodes are rendered server-side as inline SVG
  (`App\Support\Barcode\BarcodeSvg`): EAN-13 for valid 13-digit GTINs (UPC-A
  is rendered as EAN-13 with a leading 0), Code 128-B for everything else. No
  extra dependency is used.

## Pricing

`product_prices` is an **append-only, effective-dated** price list
`(product, product_unit, branch|NULL, price, effective_from, created_by)`.
Records are never updated: `IsImmutable` throws on update or delete.

Resolution (`PriceService::resolve(product, unit, branch, at)`):

1. Latest branch-specific price for that unit with `effective_from ≤ at`.
2. Latest company-wide price for that unit.
3. Base unit only: `products.selling_price`.
4. Non-base unit with no price: the resolved base price × conversion factor.

Example (Juice): piece 1,500 · pack of 6 8,000 · carton of 24 30,000.
Future-dated prices apply automatically when their date arrives. Back-dating
is rejected. Setting a company-wide base-unit price that is effective now also
updates `products.selling_price`.

Permission `products.prices.manage` is required, plus access to the branch
for branch prices.

**Future sales snapshot:** `resolve()` returns a `PriceQuote` (price, unit,
factor, source, price record id). The POS phase stores these values on the
sale line. Historical sales are never re-priced from the current list.

## Images

- Validation happens in two layers. The form checks `image`, `mimes:jpg,jpeg,png,webp` and a
  2 MB limit. `ProductImageService` then **decodes the file with GD and
  re-encodes it to WebP** (max 1200 px), which strips metadata and embedded
  payloads. Anything GD cannot decode as JPEG, PNG or WebP is rejected.
  During testing, a PHP payload named `shell.png` passed the form-level rules
  through Livewire's upload handling and was stopped only by the decode step.
  That is why both layers exist.
- Images are stored on the **private** `local` disk at
  `companies/{company-uuid}/products/{product-uuid}-{random}.webp`.
- They are served only through `GET /catalogue/products/{uuid}/image`, which
  requires authentication, tenant resolution and `products.view`. The path
  comes from the database. Responses are `private` and send `nosniff`. SVG
  is not accepted.
- Product save and image processing run in one transaction, so a rejected
  image also discards the product change.
