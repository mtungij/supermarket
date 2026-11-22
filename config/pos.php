<?php

/*
|--------------------------------------------------------------------------
| Point of Sale configuration (foundation only)
|--------------------------------------------------------------------------
|
| Phase 1 only prepares the POS architecture: registered devices and
| receipt formats. Offline transactions are designed in
| docs/offline-pos-design.md and implemented in a later phase.
|
| Sales are linked to company, branch, cashier (user), POS device and the
| transaction timestamp. There are NO cashier shifts in this product.
|
*/

return [

    'receipt_paper_widths' => [58, 80],

    'default_receipt_paper_width' => 80,

    // Characters per line for monospace thermal printers.
    'receipt_line_chars' => [
        58 => 32,
        80 => 48,
    ],

];
