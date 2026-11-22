# Development roadmap

> Standing rule for **every** phase: **Cashier Shift Management is
> excluded.** No open or close shift, handover, opening or closing cash,
> shift reconciliation or shift tables. Cashier reports come straight from
> sales.

## Phase 1: Foundation ✅

Multi-tenancy (fail-closed scope), companies, branches, memberships, RBAC
(8 roles), Fortify authentication, company onboarding, subscription limits,
POS device registration, three layouts, English and Kiswahili.

## Phase 2: Product catalogue, purchasing & inventory ✅

- Categories (hierarchical, normalised-unique, default set per company),
  brands, company units
- Products: SKU, standard, weighed and variable-measurement types, tax
  class, cost and price, reorder and minimum levels, batch and expiry
  tracking, private re-encoded images
- Product-specific unit conversions with snapshots
- Barcodes: manufacturer, packaging and internal (EAN-13 prefix 20), lookup,
  USB-scanner input, printable labels
- Effective-dated branch- and unit-specific prices with history, and a
  `PriceQuote` snapshot for the future POS
- Suppliers with purchase history and an outstanding-balance ledger
- Purchase orders with an enforced state machine and approval audit
- Goods receipts: partial and multiple deliveries, batches and expiry,
  idempotent atomic posting
- Inventory: locations, immutable ledger, balances, weighted-average cost,
  approved adjustments, two-step inter-branch transfers, low-stock and
  expiry alerts, valuation foundation
- 18 Phase 2 permissions, role mapping, subscription read-only mode

Known gaps are listed in [phase-2-validation.md](phase-2-validation.md).

## Phase 3: POS, sales & checkout (online) ✅

Barcode-first POS (keyboard-wedge queue), server-priced cart with VAT
inclusive or exclusive, sellable locations, FEFO, split and manually
confirmed payments, idempotent atomic checkout with COGS, 58/80 mm browser
receipts, sales history and reports, customers, returns, refunds and
exchanges, and payment-method settings. No shifts. See
[pos-and-sales.md](pos-and-sales.md).

### Original Phase 3 proposal (delivered as above)

- POS screen with barcode scanning (reusing `BarcodeService::lookup`) and a
  keyboard-first flow
- Sales with `client_uuid`, attributed to company, branch, device, cashier
  and time (**no shift**), with price snapshots from `PriceQuote`
- `sale` and `sale_return` stock movements through `StockLedger`, FEFO batch
  allocation
- Payments (cash and mobile money references), 58mm and 80mm receipts
- Voids, refunds and discounts gated by permissions

## Phase 4 (proposed): Staff & company administration

Staff invitations, role and branch assignment UI, company settings (including
`allow_negative_stock`), an audit log, platform plan management, and optional
2FA.

## Phase 5 (proposed): Offline POS

IndexedDB catalogue and outbox, device pairing, idempotent batch sync and
conflict reconciliation (see `offline-pos-design.md`).

## Phase 6 (proposed): Reporting & finance

Sales by branch, cashier, device and product (from sales, not shifts), COGS,
historical valuation, supplier payments and returns, expenses, exports.

## Phase 7 (proposed): Billing

Payment gateway integration, invoices, renewals, dunning.
