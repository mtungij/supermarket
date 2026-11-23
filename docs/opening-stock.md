# Opening stock

**Inventory → Opening stock** (Kiswahili: *Stoo ya mwanzo*) records the physical stock a shop **already owned before it started using the system**.

It is deliberately separate from the other stock operations:

| Operation | Meaning | Movement type |
|---|---|---|
| Opening stock | Initial stock owned at go-live | `opening_stock` |
| Purchase / GRN | New stock bought from a supplier (creates payables) | `goods_receipt` |
| Stock adjustment | Approved correction after operations started | `adjustment_in` / `adjustment_out` |
| Stock transfer | Moving existing stock between locations or branches | `transfer_out` / `transfer_in` |

Opening stock never creates a purchase order, GRN, supplier, payable, payment, sale or POS transaction.

## Workflow
1. **New opening stock.**
   - Header: branch (accessible branches), stock location (defaults to the sellable Sales Floor), opening date (today or earlier) and notes. The reference (`OPS-000001`) is generated when the draft is first saved.
   - Add products by scanning a barcode (the same keyboard-wedge picker as purchases and transfers, so ROMESON BS31 works) or by searching name/SKU. Products without barcodes work the same way.
   - Scanning again adds +1 to the same product/unit line. For batch-tracked products it adds to a line whose lot is still empty; "Another batch" adds a separate lot line.
   - Each line has: unit, quantity, **cost per unit** (per the selected unit), and batch, manufacture and expiry dates for tracked products.
   - Live cards show the number of products, total base quantity, opening value, branch and location.
2. **Save draft.** Drafts never change stock, and can be edited or cancelled.
3. **Post opening stock.** This is irreversible. Stock, value and weighted-average cost change. Afterwards the document is read-only and shows who created and posted it, when, and its history.

## Bulk import (CSV / XLSX)
`Import CSV / Excel`:
1. Download the template. It is a CSV pre-filled with every active product's SKU, barcode, name and base unit; rows left without a quantity are skipped.
2. Upload a `.csv` or `.xlsx` file (first sheet; max 5 MB / 5,000 rows).
3. Columns are mapped automatically and can be corrected.
4. **Check file** shows row-level errors and totals.

How rows are matched and validated:
- Products are matched by SKU and/or barcode. A packaging barcode selects its carton/pack unit.
- An optional `unit` column accepts the unit code or name.
- Dates may be `YYYY-MM-DD`, `DD/MM/YYYY` or Excel date cells.
- Every row goes through the **same validation** as manual entry (`OpeningStockService::prepareLines`).

Saving is all or nothing: a draft is created only when **every** row is valid. The draft is then posted with the **same posting service**, permissions and duplicate protection.

The reader is dependency-free (`App\Support\SpreadsheetReader`): it applies row and size limits, never evaluates formulas, and loads no external entities. The template neutralises spreadsheet formula injection.

## Valuation rules
- **Cost is required.** It must be explicitly entered and non-negative (up to 4 decimals). It is never taken silently from the product's reference buying price. The form shows that price as a suggestion with a "Use it" button that visibly fills the field.
- **Conversions:**
  - cost per base unit = cost per entered unit ÷ conversion factor (4 dp);
  - base quantity = quantity × factor;
  - **value = base quantity × cost per base unit** (4 dp).
  - Example: 2 cartons of 24 at TZS 24,000 per carton = 48 pieces at 1,000 = 48,000.
- **Zero cost** needs:
  - the line's "no cost" confirmation;
  - a document reason;
  - a poster with `inventory.opening.zero_cost` (recorded as `zero_cost_approved_by`).
- **Posting** goes through `StockLedger`:
  - immutable `opening_stock` movements, with unique source line + type;
  - balance rows and the branch weighted-average cost: with no prior stock, the average equals the opening cost; several locations of one branch are averaged.
- **Unchanged by opening stock:** `last_cost` and `products.purchase_cost` (these come from GRNs).

## Locations, lots and expiry
- Any active location of the branch may be used.
  - Sellable locations (Sales Floor) can be sold by the POS immediately.
  - Other locations (Main Store, Receiving Area, Quarantine) need a normal Stock Transfer first.
- Tracking requirements:
  - batch-tracked products need a batch number;
  - expiry-tracked products need an expiry date;
  - lots are created by `BatchResolver`, so balances stay per lot and POS FEFO is unchanged.
- **Expired lots** are refused for selling locations. They may only be recorded in a non-selling location (e.g. Quarantine), and are never sellable.

## Duplicate prevention (the initial-stock rule)
A product may receive opening stock at a location only if:
1. it has **no stock movement of any other type in that branch** (no receipts, sales, adjustments, transfers or returns), and
2. **no opening balance was posted for it at that location before**.

Otherwise the user is directed to Stock adjustments. A product may be opened at more than one location of a branch (e.g. Main Store and Sales Floor) as long as nothing else has happened in that branch.

**Enforcement:**
- **When editing:** at scan/search time, and when saving a draft.
- **When posting, inside one transaction (`attempts: 3`):**
  - the document row is locked and must still be a draft, so double clicks and retries fail;
  - the branch cost row of every product is locked (serialising with receipts, sales and transfers);
  - eligibility is re-checked with locking reads;
  - a row is inserted per product into `opening_stock_registrations`, whose **UNIQUE(company_id, location_id, product_id)** index makes a second opening balance impossible even across concurrent requests;
  - StockLedger's unique movement index prevents posting a line twice.
- **Real concurrency** is covered by `ConcurrentStockPostingTest::test_concurrent_opening_stock_posting_adds_stock_once` (two forked processes: the same document, and two documents for the same product/location).

There is no "exception" path: corrections to stock that already exists go through approved Stock adjustments.

## Permissions
| Permission | Owner | Branch manager | Store keeper | Others |
|---|---|---|---|---|
| View (`inventory.view`) | ✓ | ✓ | ✓ | as before |
| Prepare / edit drafts, import (`inventory.opening.manage`) | ✓ | ✓ | ✓ | – |
| Post (`inventory.opening.post`) | ✓ | ✓ | – | – |
| Post zero-cost lines (`inventory.opening.zero_cost`) | ✓ | – | – | – |

Branch access is checked on every action. Products and locations are resolved in the user's company only, and composite foreign keys prevent cross-company rows.

There is no separate approval step. The poster is the approver, and both `created_by` and `posted_by` are recorded with document events.

## Reporting
- Opening stock appears in Stock on hand, Stock movements (type "Opening stock"), Product stock details, the inventory value (balances × average cost) and the Opening stock history (posted value, drafts, status, creator and poster).
- It does not appear in sales, purchases, supplier balances, customer payments or POS reports.
