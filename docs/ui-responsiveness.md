# Responsive UI (Phase 3 UI/UX)

Mobile-first layout rules for the whole app. Supported widths: 320 px phones
to 1920 px desktops, including 200 % browser zoom (a 1280 px window at 200 %
behaves like 640 px).

## Navigation (`components/app/shell.blade.php`, `components/app/topbar.blade.php`)

- From `lg` (1024 px) up, the sidebar is always visible. Below that, it is an off-canvas drawer.
- The drawer opens from the hamburger button, which has `aria-controls="app-sidebar"` and `aria-expanded`. Opening it locks page scrolling.
- The drawer closes on any of: tapping the backdrop, the close button, Escape (focus goes back to the menu button), or following any link inside it.
- When closed on small screens, the drawer is `inert`, so keyboard and screen-reader users cannot tab into hidden links.
- Role-based menu items are unchanged. `App\Support\Navigation\Navigation` still decides what each role sees.

## POS (`livewire/pos/terminal.blade.php`, `components/layouts/pos.blade.php`)

**Desktop (≥ 1024 px):** two columns. The cart is on the left. A sticky, independently scrolling column on the right holds the customer, totals and payment.

**Phones and tablets:**
- Everything is stacked.
- The scan box stays pinned under the header.
- A fixed bottom bar shows the total, the item count and **Pay**, which changes to **Complete sale** once payment starts. The bar respects the iPhone safe area.
- The bar hides while a quantity, payment or customer field is focused, so the on-screen keyboard never covers what is being typed.

**Header:** at under 400 px the "My Sales" link shows only its icon. The label stays available to screen readers.

**Scanner (ROMESON BS31 / keyboard wedge):** behaviour is unchanged.
- `resources/js/pos/scanner.js` still queues codes on Enter.
- It still sends one request at a time.
- It still routes stray keystrokes back to the scan box.
- The scan input is now `wire:ignore`, so a Livewire re-render can never reset a half-typed code.

## Tables (`components/ui/table.blade.php`, `resources/js/responsive-tables.js`)

- From `sm` (640 px) up, data tables are normal tables. If wider than the screen, they scroll inside their card; the page itself never scrolls sideways.
- Below 640 px, each row is shown as a card:
  - the first cell is the card title;
  - every other cell is a "LABEL value" line;
  - the action buttons wrap underneath.
- How it works:
  - Tables marked `data-stack` get stacked. `x-ui.table` adds the attribute by default; pass `:stack="false"` to opt out.
  - `responsive-tables.js` copies each header's text into a `data-label` attribute on the matching cells, and re-applies it after Livewire updates.
- The table wrapper is `relative`. This stops absolutely positioned `sr-only` header text from leaking outside the scroller and widening the page, which was the cause of the overflow on the POS devices and categories pages.

## Touch targets and focus

- Row actions, Remove buttons, the POS device Save/Revoke buttons, the location "Sellable" toggle and the theme toggle are at least 40–44 px tall.
- Keyboard focus shows a 3 px brand-colour outline (`:focus-visible`). Mouse clicks are unaffected.

## Printing

- **Thermal receipts** (`sales/receipt.blade.php`) are unchanged: `@page size 58mm/80mm auto`, forced light colours in dark mode, and long names wrap.
- **Purchase order print:** on phones the line table scrolls sideways inside the page. Printing uses the full width.

## How this was tested

Automated markup regression tests are in `tests/Feature/ResponsiveLayoutTest.php`.

Browser testing used headless Chrome over the DevTools Protocol against an isolated `supermarket_saas_browser` database:
- Every owner page was checked at 320, 360, 375, 390, 430, 640, 768, 1024, 1280, 1440 and 1920 px for horizontal overflow, small touch targets and console errors.
- POS scanning was driven with real key events.
- The drawer and the transfer modal (at 320 px) were exercised.
- 58 mm and 80 mm receipts were printed to PDF with dark mode emulated.
