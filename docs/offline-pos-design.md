# Offline-first POS design

**Status:** online POS implemented in Phase 3 (see `pos-and-sales.md`).
Offline selling is **not** implemented. This document is the design for
the offline phase. Phase 1 delivers the foundations, namely registered
POS devices (`pos_devices`), receipt width settings (`config/pos.php`) and
the tenant model. Offline transactions are **not** implemented yet.

> **No shifts.** A cashier logs in and sells. There is no open or close
> shift, no opening or closing cash, and no reconciliation step. Cashier
> reports aggregate sales by `cashier_user_id` over any date range.

## Goals

- Keep selling when the internet connection drops.
- Barcode scanning (USB/Bluetooth HID scanners act as keyboards, plus
  camera scanning as an option).
- A local product catalogue for fast lookups.
- Sync that is safe to retry (idempotent) and never duplicates sales.
- 58mm and 80mm thermal receipts.

## Components (future phases)

```
Browser POS (resources/js/pos/)
 ├─ catalogue store   IndexedDB: products, prices, barcodes (per branch)
 ├─ outbox store      IndexedDB: pending sales (status: pending|synced|rejected)
 ├─ sync worker       pushes outbox, pulls catalogue deltas
 └─ receipt renderer  58mm (32 chars) / 80mm (48 chars) layouts

Server
 ├─ POST /api/pos/sales:batch   idempotent upsert by client UUID
 ├─ GET  /api/pos/catalogue?since=<cursor>
 └─ device authentication       per-device token bound to pos_devices.uuid
```

## Device registration

1. A manager registers a device for a branch (`pos_devices`, available
   **now**).
2. In a future phase, a pairing code links a browser to that device. The
   browser generates a stable `device_identifier`, and the server issues a
   device token scoped to company and branch. Revoking the device
   (`status = revoked`, available **now**) invalidates the token.
3. Every sync request is authorized as (device token AND logged-in cashier
   membership with `pos.access` in that branch).

## Transaction identity & idempotency

- Each sale is created on the device with a **UUIDv7** `client_uuid`, which
  sorts by time.
- The server table gets `UNIQUE(company_id, client_uuid)`. Re-sending a batch
  returns the existing result instead of inserting again.
- Batches are processed one sale at a time inside a transaction. The response
  reports a status per sale (`accepted`, `duplicate`, `rejected` with a
  reason).

### Planned `sales` attribution (no shift)

| Column | Source |
|---|---|
| company_id, branch_id | device's registration (server-verified) |
| pos_device_id | authenticated device |
| cashier_user_id | authenticated cashier |
| client_uuid | device |
| transacted_at | device clock (UTC), plus `received_at` on the server |
| receipt_number | per-device sequence, e.g. `<device-code>-<seq>` |

## Conflict reconciliation

| Conflict | Policy |
|---|---|
| Price changed while offline | Sale keeps the price charged at the till. Flag it for review if the difference exceeds a threshold |
| Product deleted or deactivated | Accept the sale (it happened) and flag it |
| Stock would go negative | Accept and record a negative-stock event for the store keeper |
| Device revoked while offline | Reject new sales after the revocation time, quarantine earlier ones for manager approval |
| Cashier deactivated | Same as above, keyed on the deactivation timestamp |
| Clock skew | Store both device time and server time. Reports use device time with skew alerts |

## Receipts

`config/pos.php` defines `receipt_paper_widths` (58, 80) and characters per
line (32 and 48). Each device stores its `receipt_paper_width`. The company
default lives in `companies.settings.receipt_paper_width`.

## What Phase 1 deliberately does not include

No IndexedDB code, service worker, sync API or sales tables. These belong to
the POS phase, so the foundation stays simple.

## Phase 3 contracts the offline POS will reuse

- **Lookup:** `PosCatalog::lookup / item / units / available`. The offline
  catalogue export will carry the same data per branch: barcode → product +
  unit + factor, active/sell flags, resolved prices with `effective_from`,
  tax class, and the selling location's sellable quantity.
- **Checkout:** `CheckoutService::checkout(company, branch, device, cashier,
  client_uuid, lines, payments, …)`. Offline sales will be replayed through
  the same method. `client_uuid` (UUID, unique per company) makes replays
  idempotent.
- **Inventory invariants the sync must preserve:**
  - stock changes only through `StockLedger` (immutable movements, one per
    source line)
  - no negative stock (unless the company explicitly allows it)
  - expired lots are never sold
  - FEFO allocation happens on the server at sync time
  - COGS is the server WAC at posting
- **Conflicts:** a replayed sale that is now short of stock is not posted
  silently. It must be queued for manager review (oversold-offline report)
  rather than forced. This rule is defined for the offline phase and not
  yet implemented.
- **Scanner:** the keyboard-wedge queue in `resources/js/pos/scanner.js` is
  device-agnostic and will resolve codes against IndexedDB first.
