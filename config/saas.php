<?php

/*
|--------------------------------------------------------------------------
| Supermarket SaaS platform configuration
|--------------------------------------------------------------------------
|
| Platform-wide defaults applied when a new company (tenant) is onboarded.
| Each company keeps its own copy of these values so they can later be
| changed per tenant without affecting anyone else.
|
| NOTE: Cashier Shift Management is intentionally NOT part of this product.
| Do not add shift-related configuration here.
|
*/

return [

    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'TZS'),

    // Business/display timezone for new companies. Timestamps are stored
    // in UTC (config/app.php) and rendered in the company's timezone.
    'default_timezone' => env('SAAS_DEFAULT_TIMEZONE', 'Africa/Dar_es_Salaam'),

    'default_country' => env('SAAS_DEFAULT_COUNTRY', 'TZ'),

    'default_locale' => env('APP_LOCALE', 'en'),

    'supported_locales' => [
        'en' => 'English',
        'sw' => 'Kiswahili',
    ],

    // When true, Fortify's email verification feature is enabled and
    // tenant routes require a verified email address.
    'email_verification' => (bool) env('SAAS_EMAIL_VERIFICATION', false),

    // Subscription plan (by code) assigned to newly registered companies.
    'onboarding_plan' => env('SAAS_ONBOARDING_PLAN', 'trial'),

    // Default per-company settings, copied into companies.settings.
    'default_company_settings' => [
        'receipt_paper_width' => 80,      // 58 or 80 (mm)
        'currency_decimal_places' => 0,   // TZS is normally displayed without decimals
        'date_format' => 'd/m/Y',
        'prices_include_tax' => true,
        // When false, "Send PO" approves immediately for anyone allowed to send.
        'purchases_require_approval' => true,
    ],

];
