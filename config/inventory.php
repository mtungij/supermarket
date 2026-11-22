<?php

/*
|--------------------------------------------------------------------------
| Catalogue & inventory configuration (Phase 2)
|--------------------------------------------------------------------------
|
| Company-level overrides live in companies.settings (see
| config('saas.default_company_settings')).
|
*/

return [

    // Percent rates per tax class (Tanzania VAT standard rate: 18%).
    'tax_rates' => [
        'standard' => env('INVENTORY_VAT_RATE', '18.00'),
    ],

    // Days ahead to flag batches as "expiring soon".
    'expiry_alert_days' => (int) env('INVENTORY_EXPIRY_ALERT_DAYS', 30),

    // Internal (in-store) barcodes: restricted-circulation EAN-13 prefix.
    'internal_barcode_prefix' => '20',

    'product_images' => [
        'disk' => env('PRODUCT_IMAGE_DISK', 'local'),   // private disk; served via authorised route
        'max_kilobytes' => 2048,
        'max_dimension' => 1200,                        // re-encoded to fit within this box
        'allowed_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    // Additional (landed) cost types on goods receipts. Amounts are entered
    // excluding VAT and are NOT owed to the goods supplier.
    'landed_cost_types' => [
        'transport' => 'Transport',
        'loading' => 'Loading / unloading',
        'handling' => 'Handling',
        'insurance' => 'Insurance',
        'customs' => 'Customs & duties',
        'other' => 'Other',
    ],

    'default_categories' => [
        'Beverages', 'Food & Groceries', 'Dairy Products', 'Bakery', 'Snacks',
        'Household Items', 'Personal Care', 'Baby Products', 'Health & Beauty',
        'Frozen Foods', 'Fruits & Vegetables', 'Meat & Seafood', 'Stationery',
        'Electronics', 'Other Products',
    ],

    // code => [name, dimension, ratio to the dimension's reference unit]
    // Count units have no global ratio: packaging factors are per product.
    'default_units' => [
        'pc' => ['Piece', 'count', null],
        'pack' => ['Pack', 'count', null],
        'ctn' => ['Carton', 'count', null],
        'box' => ['Box', 'count', null],
        'btl' => ['Bottle', 'count', null],
        'can' => ['Can', 'count', null],
        'bag' => ['Bag', 'count', null],
        'kg' => ['Kilogram', 'mass', '1'],
        'g' => ['Gram', 'mass', '0.001'],
        'l' => ['Litre', 'volume', '1'],
        'ml' => ['Millilitre', 'volume', '0.001'],
        'm' => ['Metre', 'length', '1'],
    ],

];
