# Purchasing workflow

## Barcode-first flow (HARDEX-style)

**Purchases → Create Purchase → Purchase Details → Receive Purchase → Posted GRN**

1. **Create Purchase** (`/purchasing/orders/create`). Pick the supplier and
   branch, then scan or search products.
   - Each scan adds the product with quantity 1. Scanning it again increments
     the quantity, and the input clears and refocuses after every scan.
   - The live summary shows subtotal, discount, tax and the grand total.
   - **Save draft** keeps the order editable. **Save & send PO** submits it,
     and approves it immediately when the sender holds `purchases.approve`
     or the company setting `purchases_require_approval` is `false`.
     Otherwise it waits for an approver.
2. **Purchase Details** (`/purchasing/orders/{uuid}`) has the summary,
   lines, receipts, the approval audit trail, **Print PO** (to send to the
   supplier) and the actions allowed by your permissions.
3. **Receive Purchase** (`/purchasing/orders/{uuid}/receive`). See below.
4. **Posted GRN** (`/purchasing/receipts/{uuid}`) is read-only. It is never
   edited after posting; corrections use stock adjustments.

**Purchases list.** Columns are purchase number, supplier, branch, date,
total, **Paid**, **Balance**, status (with a receiving progress bar) and
actions: View, Edit, Send PO, Receive, Cancel. Each action appears only when
the state and your permissions allow it, and the services enforce the same
rules server-side.
- *Paid* is 0.00 because supplier payments are not implemented yet.
- *Balance* is the value of goods actually received (posted GRNs, incl. tax)
  minus Paid. Nothing is ever marked as paid automatically.

**Quick Receive** (`purchases.quick_receive`, granted to Company Owner and
Branch Manager only) lets a user receive a draft or submitted purchase
directly. The order's submit and approve transitions are recorded with the
user and the note "Quick receive", inside the same transaction as the
posting. Without the permission, unapproved orders cannot be received, as
before.

## Receive Purchase screen

- Summary cards: purchase number, supplier, branch, date, ordered by, status,
  total ordered, previously received, remaining (base units).
- Header:
  - GRN reference preview (the real number is assigned when posted)
  - receiving date
  - supplier delivery note number
  - supplier invoice number
  - default receiving location (applies to every line; each line can then
    be changed to another active location of the order's branch)
  - notes
- Lines start empty. **Receive All** fills every line with its outstanding
  quantity. If the outstanding quantity is not a whole number of the line's
  unit (for example, 13 pieces left on a carton-of-24 line), the line
  switches to the base unit and its price is converted.
- Per line:
  - product and SKU, unit, ordered / previously received / remaining
  - received quantity
  - **stock increase in base units** (live)
  - unit buying price
  - receiving location
  - batch, manufacturing and expiry dates (only for tracked products)
  - line notes
  - goods value, allocated additional costs and landed cost per base unit
- **Additional costs** (optional): transport, loading/unloading, handling,
  insurance, customs & duties, other. These types are configurable in
  `config/inventory.php` (`landed_cost_types`).
- **Live summary:**
  - selected products
  - total received quantity
  - goods purchase value
  - additional costs
  - landed cost per unit (only when all items share one base unit)
  - total landed value
  - receiving locations
  - remaining after receipt
- **Post goods receipt** is disabled while posting. Each form has a
  `submission_key`, and `goods_receipts.submission_key` is unique per
  company, so a double click or retry returns the GRN already posted
  instead of posting again.

## Landed costs

- **Allocation method:** proportional to the **received base quantity** of
  each line, `share = total × qty_line / Σ qty`. Rounding uses the
  largest-remainder method at 2 dp, so allocated amounts always add up
  exactly to the entered total (`ReceiptCalculator`, verified by a
  200-case randomised test).
- Inventory cost per base unit = `(goods_value + allocated cost) /
  base_qty`, rounded half-up to 4 dp. This landed cost is what
  `StockLedger` posts, so it is what feeds the weighted average.
- **Kept separate:**
  - The supplier buying price stays in `unit_cost` / `unit_cost_base`,
    and `products.purchase_cost` stays the latest *supplier* price.
  - The supplier liability (`supplier_transactions`, the GRN `total`) is
    goods + tax only. **Landed costs are never added to the supplier
    balance.**
  - Landed-cost amounts are entered excluding VAT. VAT on goods is not part
    of inventory cost (assumed recoverable). PO discounts are already in the
    net buying price. So nothing is counted twice.
- Historical GRNs (before this change) have no landed values. Their
  inventory cost is the supplier cost per base unit
  (`GoodsReceiptLine::inventoryUnitCostBase()`).

## Suppliers

- Company-owned. The name is unique per company after normalisation. Fields:
  phone, email, address, tax identifier (TIN), contact person, notes, active.
- A purchase order can only use an **active supplier of the same company**.
  The service looks the supplier up with `Supplier::forCompany($company)`,
  and the composite FK `(supplier_id, company_id)` enforces the same rule in
  the database.
- **Purchase history:** the supplier page lists that supplier's purchase
  orders in branches the user can access.
- **Outstanding balance foundation:** `supplier_transactions` is an
  append-only ledger. Each posted GRN adds a `goods_receipt` entry for the
  GRN total including tax, unique per GRN. The balance is the sum of the
  entries. Recording payments and supplier returns comes in a later phase.

## Purchase orders

```
draft ──submit──▶ submitted ──approve──▶ approved ──receive──▶ partially_received ──receive──▶ received
  ▲                   │                     │                        │
  └──────reject───────┘                     │                        └──close──▶ closed
draft / submitted / approved ──cancel (nothing received)──▶ cancelled
```

- The transitions are defined in `PurchaseOrderStatus::allowedTransitions()`
  and enforced by `PurchaseOrderService::transition()`. That method locks
  the row, re-reads the status, refuses illegal moves, checks branch access
  and writes a `document_events` audit row (who, when, from, to, notes).
- Only `draft` orders can be edited. Submitting requires at least one line.
- Approval records `approved_by` and `approved_at`. The **approval audit**
  is the full event list shown on the order page.
- **Creating, submitting or approving an order never changes stock.**

| Action | Permission |
|---|---|
| create, edit draft, submit | `purchases.create` |
| approve, return to draft, cancel (non-draft), close | `purchases.approve` |
| receive (GRN) | `purchases.receive` |

Every action also requires access to the order's branch.

### Lines and totals

Each line holds a product, a purchasable unit, a quantity, a unit cost (per
ordered unit, excluding tax), a discount amount and a tax rate (defaulting
from the product's tax class). It also snapshots the conversion factor and
the base quantity.

```
line_subtotal = round2(quantity × unit_cost)
taxable       = line_subtotal − discount_amount      (discount ≤ subtotal)
tax_amount    = round2(taxable × tax_rate / 100)     (rate 0–100)
line_total    = taxable + tax_amount
```

Order totals are the sums of the line values. All maths is exact decimal.
For example, 3 × 24,000.50 − 1,000 + 18% VAT = 83,781.77.

## Goods Received Notes (GRN)

A GRN is the **only** way purchased stock enters inventory. The receiving
screen prefills each line with the outstanding quantity and the order's net
unit cost, and supports:

- full or partial receiving, and multiple deliveries per order
- receiving in a different unit than ordered (for example, ordered in
  cartons, received in pieces), compared in base units
- actual unit cost from the invoice (overrides the order cost)
- batch number, manufacturing date and expiry date (required for tracked
  products)
- supplier invoice or delivery note reference, and receiving notes
- the receiving location (default: Receiving Area of the order's branch)

### Posting (`GoodsReceiptService::post`): one transaction

1. Lock the GRN and require `draft`. **A second post sees `posted` and is
   refused.**
2. Check the `purchases.receive` permission, access to the branch and an
   active subscription.
3. Lock the PO and require `approved` or `partially_received`.
4. For each line, sorted by product: lock the PO line, re-validate the
   unit, conversion and quantity, and refuse over-receipt (quantity >
   outstanding).
5. Resolve or create the batch, then post a `goods_receipt` movement through
   `StockLedger`. This locks the balance and cost rows, updates the WAC and
   snapshots the cost per base unit.
6. Increase the PO line's received quantity, then move the PO to
   `partially_received` or `received`.
7. Record the supplier liability and update the product's latest purchase
   cost.
8. Mark the GRN `posted` with who and when.

Any failure rolls back **everything**. The receiving screen creates and
posts the GRN in one step (`receive()`), with deadlock retries.

### Duplicate-posting protection (several layers)

- The GRN row lock plus the status check.
- The PO row lock, with outstanding quantities re-read per line.
- `UNIQUE(company_id, source_type, source_id, type)` on stock movements.
- `UNIQUE(company_id, source_type, source_id, type)` on supplier
  transactions.

These are verified by `GoodsReceiptTest` (sequential double post, two drafts
exceeding the order together) and `ConcurrentStockPostingTest` (two parallel
processes posting the same GRN).

## Read-only mode

When a company has no usable subscription, catalogue, purchasing and
inventory pages stay viewable, but creating products, orders, receipts,
adjustments and transfers is refused (`SubscriptionGuard`).
