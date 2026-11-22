# POS, sales, payments & returns (Phase 3)

> **No Cashier Shift Management.** There is no open or close shift,
> handover, opening or closing cash, reconciliation, or shift table. Every
> sale records its **company, branch, POS device (register), cashier and
> timestamp**. Cashier reports are built directly from sales.

## Using the POS

- Cashiers (users with `pos.access` and no management permissions) land on
  `/pos` straight after login.
- On first use, the browser picks one of the branch's **registered POS
  devices**. The choice is remembered in the session and in a cookie, and
  re-validated on every request. Devices are required by default (company
  setting `pos_require_device`, default `true`), and the subscription plan's
  device limit still applies.
- The layout is full screen. On desktop the cart is on the left and the
  totals, customer and payment panel on the right. On mobile and tablet the
  panels stack, with a sticky total and **Pay** bar.

### Barcode scanning (keyboard-wedge, e.g. ROMESON BS31)

`resources/js/pos/scanner.js` handles scanning:

- The scan input is focused on load and after every action. Keystrokes made
  while focus is elsewhere (not inside another field) are routed to it.
- **Enter** (sent by the scanner, or typed) reads the value, clears the
  input immediately and **queues** the code.
- Codes are sent to `Terminal::scan()` **one at a time**: a fast second scan
  is never lost, and a single scan is never processed twice. No request is
  made per keystroke.
- There is no Add button requirement, but a touch **Add** button exists.
  Characters are never altered by the application.

`PosCatalog::lookup()` is a tenant-scoped exact-barcode lookup, falling back
to SKU. It covers manufacturer, internal and packaging barcodes, mapped to
the product unit they belong to. Inactive products, inactive units and units
not marked *sell* are refused. The resolved unit and **server-side price**
are shown. Search covers name, SKU and exact barcode. Repeated scans of the
same product and unit increase that line's quantity.

### Product search (products without barcodes)

The "Search by name, SKU or barcode" box sits under the scan input. It is separate from the scanner and works alongside it.

**Matching** (`PosCatalog::searchSellable`):
- Live, debounced by 250 ms, from 2 characters.
- Partial and case-insensitive.
- Every word typed must appear in the product name, so "soap family" finds FAMILY MEDICATED SOAP.
- SKUs match on any part of the code; barcodes match from the start of the code.

**Ranking** (at most 12 results):
1. Exact barcode or SKU.
2. Name starts with the search text.
3. A word in the name starts with it.
4. Anything else, then alphabetical.

**Each result shows:**
- name, SKU and barcode (if any);
- the price of the default selling unit, from the server (effective-dated, branch-aware);
- the quantity available at the register's **selling location only**;
- a status: In stock, Out of stock or Not for sale.

**What the browser sees:**
- Only active products of the current company appear.
- Rows carry public UUIDs only, no internal IDs.
- The catalogue is never sent to the browser.

**Rendering and stale results:**
- `Terminal::searchProducts()` is `#[Renderless]`, so typing never re-renders the POS.
- Each request carries a sequence number; an older, slower response is discarded.

**Keyboard and touch:**
- ↑/↓ move the highlight. It starts on the first in-stock result.
- Enter adds the highlighted result, waiting for a pending search if needed.
- Esc closes the list.
- Tap or click adds directly.

**Adding a product** (`Terminal::addProduct(uuid)`):
- The product is re-resolved through the tenant scope, so another company's UUID is "not found".
- Inactive products and products with no sellable unit are refused.
- The default sellable unit is used, base unit first.
- Adds quantity 1, or +1 if the product is already in the cart.
- The same stock check as scanning applies.
- **On success:** the search clears, the list closes and focus returns to the scan input.
- **Out of stock:** nothing is added, and the reason is shown. Stock in Receiving Area or other non-selling locations is never sold. POS never sells beyond sellable stock, whatever the company's negative-stock setting.

**Scanner compatibility:**
- When the picked result is an **exact** barcode or SKU, for example when the scanner fires while the search box has focus, it is routed through the scanner's own queue (`pos-scan` event → `scan()`). Packaging barcodes therefore still add the carton or crate unit, and nothing is added twice.
- Keystrokes typed with nothing focused still go to the scan input.

### Cart

Each line shows the name, SKU, barcode, unit (any sellable unit, using the
product's conversions), quantity (− / + / typed), unit price, discount
(only with `sales.discount`), VAT and line total, plus the **available
sellable stock**. Adding more than is available at the selling location is
refused with a clear message. Clearing the cart asks for confirmation.

The client can only change quantities, units, permitted discounts, the
customer and payments. A discount typed without permission is reset on the
server, and **checkout re-prices everything**.

## Selling locations (stock source)

- `stock_locations.is_sellable` marks the locations the POS may sell from.
  By default only the **Sales Floor** is sellable. Receiving Area and Main
  Store are not, unless an authorised user explicitly marks them sellable
  (Inventory → Stock locations). **Damaged / Quarantine** can never be
  sellable.
- A register sells from its configured `stock_location_id` (Company → POS
  devices), otherwise from the branch's default sellable location (Sales
  Floor first).
- There is **no fallback** that sells from whatever location has stock.

## Prices & tax

- The price comes from `PriceService::resolve()`: branch price, then
  company price, then the default price, then base price × conversion
  factor. Effective-dated, branch- and unit-specific prices are all
  respected.
- Tax comes from the product's tax class (`config/inventory.php`:
  standard = `INVENTORY_VAT_RATE`, default 18%; zero-rated and exempt =
  0%). The company setting `prices_include_tax` (default `true`) decides
  the method:
  - **Inclusive:** tax = taxable × r / (100 + r), and the total equals the
    shelf price.
  - **Exclusive:** tax = taxable × r / 100, added on top.
- No rate is ever invented. Subtotal, discount, VAT and grand total are
  shown on screen and on the receipt.

## Checkout (`CheckoutService`)

One DB transaction, **idempotent per cart UUID** (`sales.client_uuid` is
unique per company; a new key is issued after every completed sale):

1. **Checks:** `pos.access` and `sales.create` (discounts also need
   `sales.discount`), branch access, an active subscription, and an active
   device of this branch.
2. **Pricing:** every line is re-validated and re-priced server-side.
   Client totals are ignored.
3. **Payments** (all **manually confirmed** by staff; there is no provider
   integration, so nothing is "verified"):
   - Amounts must be > 0 (2 dp), and the method must be active and belong
     to this company. Mobile Money and Bank Transfer require a reference.
   - Non-cash payments must not exceed the amount due. Underpayment is
     refused; credit sales are disabled.
   - Change comes only from cash. Each payment stores `tendered`, `amount`
     (applied) and `change_given`, so **Σ amount = sale total**. Change is
     never revenue.
   - Split payments are allowed, for example Cash 3,000 + Mobile Money
     2,000.
4. **Stock**, for each product sorted by id:
   - Lock the branch cost row (`StockLedger::lockBranchProduct`), then read
     the selling location's **non-expired** lots **FEFO** (earliest expiry
     first, lots without expiry last) and allocate.
   - Insufficient stock is refused, and the message mentions any expired
     units.
5. **Records** (all immutable):
   - `sales`: number `S-000001`, totals, net, VAT, tendered, change, COGS,
     cashier, device, location, customer.
   - `sale_items`: name, SKU, barcode, unit, factor, price, price source,
     discount, tax snapshots and COGS.
   - `sale_item_allocations`: one row per lot.
   - `sale_payments`.
6. **Ledger:** one `sale` stock movement per allocation, at the branch
   weighted-average cost (that is the **COGS**). Balances update under the
   held locks.

Any failure rolls back everything. A double click, browser retry or a
concurrent duplicate request with the same key returns the existing sale.
Concurrent checkouts by different cashiers serialise on the product lock, so
the second one gets "not enough stock" rather than overselling. This is
verified with forked processes in `ConcurrentStockPostingTest`. Checkout,
scans and returns are rate-limited per user.

## Receipts

`GET /sales/{uuid}/receipt[?width=58|80][&autoprint=1]` renders a
standalone, always-light page.

- `@page { size: <width>mm auto; margin: 0 }` gives a continuous roll with
  no blank pages. Long names wrap (`overflow-wrap: anywhere`) and
  monospace text keeps columns aligned.
- Content:
  - company, branch and contacts
  - sale number, date and time, cashier, register, customer
  - lines with quantity, unit, price and discount
  - subtotal, discount, VAT, total
  - payments, amount received, change
  - footer (company setting `receipt_footer`, else "Thank you for
    shopping with us!")
- The paper width comes from the register (`pos_devices.receipt_paper_width`)
  unless `?width=` is given.
- After checkout the receipt loads into a hidden iframe and calls
  `print()`, so popup blockers don't interfere. The preview page has
  58 mm, 80 mm and Print buttons.
- Reprint is available from the sale page and the sales list. No printer
  SDK is needed.

## Sales page (`/sales`): Products Sold & Transactions

Reporting only, with **no shifts**. Periods are calendar ranges in the
company timezone (default Africa/Dar_es_Salaam): Today (default), Yesterday,
This week (from Monday), This month, Custom range, and All time.

- **Summary cards** (`MySalesReport::build`):
  - total sales and VAT included, completed transactions, items sold per
    unit, net sales after refunds
  - collections: cash (applied amount, so **change excluded**), Mobile
    Money, Card + Bank, other and exchange credit
  - refunds
- **Products Sold** tab (default for cashiers; `MySalesReport::productsSold`):
  - One row per product + unit + conversion snapshot, aggregated from the
    **immutable sale-line snapshots** (name, SKU, unit, historical price).
    Current prices are never used.
  - Columns: quantity sold, unit price (a min – max range plus
    quantity-weighted average when a product sold at different prices),
    total sales, returned quantity and amount, net quantity, net sales.
  - Shown as cards on phones and a table on large screens, so no
    horizontal scrolling is needed.
- **Transactions** tab (default for managers): sale number, date and time,
  cashier, branch, customer, total, payments, status (completed, partially
  returned or returned), view, and **reprint**.
- **Refund semantics:** refunds and returned quantities are the returns
  **against the sales of the selected period**, whenever the return was
  processed. That way the cards and the product rows always agree. Gross
  and net are shown separately.
- **Accuracy:**
  - Only completed sales exist; a failed checkout rolls back completely.
  - Split payments are counted once per payment row.
  - Line totals keep each sale's tax-inclusive or tax-exclusive semantics.
- **Security:** every aggregate, list and receipt query starts from
  `SalesQueries::visibleTo()`.
  - Cashiers (no `sales.view`) see **only their own sales**; the
    `cashier` URL parameter is ignored for them.
  - Managers see their permitted branches, owners the whole company.
  - Branch and cashier filters can only narrow the scope. Other companies
    get 404 through the tenant scope.
- **Navigation:** the POS header has a **My Sales** button that is visible
  at every width. (The cause of the earlier problem: the only link was
  hidden below the `sm` breakpoint, and the totals page needed
  `reports.view`, which cashiers rightly lack.) `/my-sales` redirects to
  `/sales`.

## Sales reports

**`/sales/reports`** (`reports.view`; COGS and profit need
`reports.financial`) covers, per date range and branch:
- total sales, transactions, items sold, discounts, tax, returns and
  refunds, net sales
- net revenue excluding tax, COGS (sales minus returns at original cost)
  and estimated gross profit
- breakdowns by product (top sellers), category, cashier, branch, payment
  method and refund method

Everything is aggregated in SQL over indexed `(company, branch, sold_at)`
columns.

## Customers

`/customers`: name, phone, email, notes, active. Phone numbers are unique
per company, compared on digits (`0712…` = `+255712…`). Customers can be
selected or quickly added in the POS (`customers.manage`). A customer is
never required, and walk-in is the default. **Credit (debt) sales are
disabled.**

## Returns, refunds & exchanges (`SaleReturnService`)

- Requires `sales.refund` and access to the sale's branch. One transaction,
  idempotent per return UUID. The original **sale row is locked**, so
  concurrent returns can't exceed what was sold.
- Full or partial returns are allowed, with a required reason. The user who
  processed it and the refund method and reference are recorded.
- Quantities use the **sale line's conversion snapshot**. Returning more
  than was sold, or listing the same line twice in one return, is refused.
- **Refund amount:** proportional to the original line total (including its
  discount and VAT). The final return of a line refunds the exact
  remainder, so total refunds never exceed what was charged.
- **Stock and COGS:** goods come back with a `sale_return` movement at the
  **original allocation cost**, which reverses COGS at that cost.
  - Fit for resale: back to the sale's selling location, or the branch
    default sellable location.
  - Damaged or expired: to the branch's non-sellable **Damaged /
    Quarantine** location, created automatically. It is written off later
    through an approved stock adjustment.
  - An expired lot can never be restocked as sellable.
- **Exchange:** refund with *Exchange credit*. No money is paid out; the
  credit is then used once, on a replacement sale linked by
  `sales.exchange_return_id` (unique).
- The original sale is never modified.

## Permissions (Phase 3)

| Permission | Default roles |
|---|---|
| `pos.access` | Owner, Branch Manager, Supervisor, Cashier |
| `sales.create` | Owner, Branch Manager, Supervisor, Cashier |
| `sales.view` (branch-wide history) | Owner, Branch Manager, Supervisor, Accountant, Auditor |
| `sales.discount` | Owner, Branch Manager, Supervisor |
| `sales.refund` | Owner, Branch Manager, Supervisor |
| `customers.view` / `customers.manage` | per role |
| `payment_methods.manage` (new) | Owner, Branch Manager |
| `reports.view` / `reports.financial` | per role |
