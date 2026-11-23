# Inventory ledger, balances & costing

## Model

| Table | Role | Written by |
|---|---|---|
| `stock_locations` | Main Store, Sales Floor, Receiving Area (defaults per branch) plus other authorised storage | `StockLocationService`, provisioner |
| `stock_batches` | Lots: batch number, manufacturing date, expiry (per product) | `BatchResolver` |
| `stock_movements` | **Immutable ledger**, one row per stock change | `StockLedger` only |
| `stock_balances` | Current quantity per location × product × batch (derived) | `StockLedger` only |
| `inventory_costs` | Branch quantity, weighted-average cost and last cost per product | `StockLedger` only |

- Balances and costs have **no forms and no fillable attributes**
  (`$guarded = ['*']`). They change only inside `StockLedger::post()`, in the
  same transaction as the movement that explains the change.
- Movements are immutable: the `IsImmutable` trait refuses updates and
  deletes. A correction is a new movement (an adjustment), never an edit.
- Movement types: `goods_receipt`, `adjustment_in`, `adjustment_out`,
  `transfer_out`, `transfer_in`. Future: `sale` and `sale_return`, linked to
  company, branch, device, cashier and time. **There is no shift movement.**
- `stock_balances` uses a generated column `batch_key = IFNULL(batch_id, 0)`
  so the unique key `(location, product, batch_key)` also works for unbatched
  stock. MySQL treats NULLs as distinct in unique indexes.

## Posting algorithm (`StockLedger::post`)

The caller must already be inside a DB transaction. For each posting:

1. Verify ownership: product, location, batch, unit and source document all
   belong to the same company (and the batch to the product). A batch is
   required for batch- or expiry-tracked products, and the location must be
   active.
2. **Lock** the branch cost row (`inventory_costs`) `FOR UPDATE`, creating it
   if missing.
3. **Lock** the location balance row `FOR UPDATE`, creating it if missing.
4. Outbound: refuse if the balance < quantity (`InsufficientStockException`),
   unless the company setting `allow_negative_stock` is true. The default is
   false.
5. Inbound: update the weighted-average cost.
6. Write the new balance, the cost row and an immutable movement storing the
   signed quantity, unit cost, total cost, balance after, branch quantity
   after, average cost after, entered unit, quantity and factor, source
   document line, reference, user and timestamp.

### Concurrency & idempotency

- **Lock order** is always cost row then balance row, and documents post
  their lines sorted by product id, so concurrent postings can't deadlock on
  each other's rows.
- Goods receipts post at the **landed** cost per base unit (supplier net
  price + allocated additional costs). See `purchasing-workflow.md`.
- **Lock-or-create, never `INSERT IGNORE` then lock.** The concurrency test
  found a real deadlock here: on an existing row, `INSERT IGNORE` takes a
  *shared* lock, and two transactions holding shared locks deadlock when both
  upgrade to `FOR UPDATE`. `App\Support\LockedRow::lockOrCreate()` locks the
  existing row first and inserts only when it is missing. Batches and
  document sequences use the same helper.
- Posting transactions run with `DB::transaction(..., attempts: 3)`, which
  retries the rare deadlock between two *first-time* inserts.
- `UNIQUE(company_id, source_type, source_id, type)` on `stock_movements`
  means one document line can produce at most one movement of each type,
  even if a status check were bypassed.
- `ConcurrentStockPostingTest` uses two **forked processes with separate
  MySQL connections**:
  - Posting the same GRN concurrently yields exactly one success and one
    refusal, and stock is added once.
  - Two concurrent 7-unit issues against 10 on hand yield one success and one
    insufficient-stock error, leaving 3, never negative.

## Costing

| Figure | Where | Meaning |
|---|---|---|
| **Latest purchase cost** | `products.purchase_cost`, `inventory_costs.last_cost` | Net (post-discount, pre-tax) cost per base unit of the most recent posted receipt |
| **Weighted-average cost (WAC)** | `inventory_costs.average_cost` (per branch) | Valuation cost of stock on hand |
| **Selling price** | `PriceService::resolve` | Effective-dated, branch- and unit-aware |
| **Gross profit estimate** | product page | resolved base price − WAC (or latest cost if no stock), per base unit, excluding tax |

Weighted average on every inbound movement:

```
new_avg = (Q × A + q × c) / (Q + q)        rounded half-up to 4 dp
if Q ≤ 0: new_avg = c
```

- Outbound movements (adjustment out, transfer out, and future sales) leave
  at the current WAC, and the WAC itself doesn't change.
- Transfers carry the source WAC (snapshotted on the transfer line at
  dispatch) into the destination's WAC.
- "In" adjustments (found goods, positive counts) enter at the current WAC,
  or the latest purchase cost if the branch has none.
- **History is never rewritten.** Each movement stores the cost it used and
  the average after it, and later purchases change only future averages.
- Stock cost excludes VAT, on the assumption that input VAT is recoverable.
  Supplier liabilities include tax.

### Decimal & rounding rules

All arithmetic uses bcmath strings (`App\Support\Decimal`). PHP floats are
never used for quantities, costs or money.

| Kind | Scale | Column |
|---|---|---|
| Quantities | 4 | `DECIMAL(18,4)` |
| Unit costs, WAC | 4 | `DECIMAL(18,4)` |
| Money (line and document totals, prices) | 2 | `DECIMAL(15,2)` |
| Conversion factors | 6 | `DECIMAL(18,6)` |

Intermediate results use scale 12, and values are rounded explicitly with
ROUND_HALF_UP (away from zero).

### Valuation: implemented vs. not yet

Implemented:

- On-hand value per branch (Σ quantity × WAC).
- In-transit value of outgoing transfers.
- Per-product value on the stock page.
- Cost snapshots on every movement.

**Not yet implemented** (planned for the reporting phase):

- Valuation as of a past date.
- FIFO or specific-lot costing (lots are tracked for expiry, not costed
  separately).
- Landed-cost allocation (freight and duties).
- Cost-of-goods-sold reporting (needs sales).
- Revaluation journals.
- Accounting integration.

## Adjustments

`StockAdjustmentService`. Reasons: damaged, expired, lost (out only), found
(in only), stock count correction (in or out). A reason and a written
explanation are required, and the requester is recorded.

1. **Request** (`inventory.adjust`, branch access) creates a
   `pending_approval` document. **Stock does not change.**
2. **Approve** (`inventory.adjust.approve`, branch access) posts the
   movements, giving `posted`. **Reject** records a reason and posts nothing.
3. Both lock the document and require `pending_approval`, so a document
   can't be approved twice. A failed posting (such as insufficient stock)
   rolls everything back and the request stays pending.

All adjustments require approval, so the Phase 2 rules treat every
adjustment as sensitive. Approvers may approve their own requests (practical
for small shops). Value thresholds and four-eyes enforcement are future
options.

## Transfers

`StockTransferService`, with explicit states:

- **Between branches:** `draft` → **dispatch** (`transfer_out` at source;
  goods are `in_transit` and count in neither branch's on-hand stock) →
  **receive** (`transfer_in` at destination, at the dispatch cost snapshot)
  → `received`.
- **Within one branch:** dispatch posts out and in together, going straight
  to `received`.
- Dispatch needs `inventory.transfer` and access to the source branch.
  Receive needs `inventory.transfer.receive` and access to the destination
  branch.
- Each step locks the transfer and checks its status, and movements are
  unique per (line, type). That prevents duplicate dispatch and duplicate
  receipt. `draft` → `cancelled` is allowed before dispatch.
- Batches travel with the goods (the same `batch_id` at the destination).
- **Scanning on the transfer form:** a source location must be chosen first.
  Each scan adds quantity 1 (repeat scans increment it) and is refused if it
  would exceed the stock available at the source. Saving re-checks
  availability per line. Saving only creates a draft: scanning never
  dispatches. Drafts are idempotent per form `submission_key`.
- **Limitation:** receipt is all-or-nothing. Shortages found on arrival are
  recorded afterwards with an adjustment (`lost`).

## Alerts

- **Low stock:** products whose branch quantity is ≤ the reorder level.
  Those ≤ the minimum stock level are marked *critical*.
- **Expiry:** batches with stock that expire within
  `INVENTORY_EXPIRY_ALERT_DAYS` (default 30), or have already expired.

Both are computed live on the Stock on hand page. There are no scheduled
notifications yet.

## Opening stock

`opening_stock` is an inbound movement type for initial stock owned before go-live. It is posted at the confirmed opening cost and never creates purchasing records. See [opening-stock.md](opening-stock.md).
