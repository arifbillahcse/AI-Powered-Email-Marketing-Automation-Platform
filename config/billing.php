<?php

/*
|--------------------------------------------------------------------------
| Billing (SaaS mode)
|--------------------------------------------------------------------------
|
| Off by default: a self-hosted or custom-built install has no plans and no
| limits. Turn it on to sell workspaces on plans (Super admin → Plans).
|
| Billing is invoice-based: each period is an invoice the customer pays
| through a gateway (cards via Stripe, bKash, SSLCommerz, or a manual bank
| transfer confirmed by a super admin). Renewal invoices are created a few
| days before the period ends; unpaid ones get a grace period, then the
| workspace stops sending until it pays.
|
*/

return [

    'enabled' => (bool) env('BILLING_ENABLED', false),

    // Days of grace after a period ends unpaid before sending stops.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Renewal invoices are created this many days before the period ends.
    'renewal_notice_days' => 3,

    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),

    // Limits for a workspace without a usable subscription (trial ended,
    // expired). It can sign in and see its data, but not send.
    'inactive_limits' => [
        'mailboxes' => 1,
        'leads' => 500,
        'emails_per_month' => 0,
        'team_members' => 1,
        'clients' => 0,
    ],

    // Printed on invoices.
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', env('APP_NAME', 'Outreach')),
        'address' => env('BILLING_SELLER_ADDRESS', ''),
        'email' => env('BILLING_SELLER_EMAIL', env('MAIL_FROM_ADDRESS')),
    ],

    // Local gateways (bKash, SSLCommerz) charge in BDT. Plans priced in
    // another currency are converted with these rates.
    'exchange_rates' => [
        'USD' => ['BDT' => (float) env('BILLING_USD_TO_BDT', 122)],
        'EUR' => ['BDT' => (float) env('BILLING_EUR_TO_BDT', 132)],
        'GBP' => ['BDT' => (float) env('BILLING_GBP_TO_BDT', 155)],
    ],

    'gateways' => [

        'manual' => [
            'enabled' => (bool) env('BILLING_MANUAL_ENABLED', true),
            'label' => env('BILLING_MANUAL_LABEL', 'Bank transfer'),
            'instructions' => env('BILLING_MANUAL_INSTRUCTIONS', 'Transfer the amount to our bank account and email the receipt with your invoice number. We confirm payments within one business day.'),
        ],

        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', false),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'sslcommerz' => [
            'enabled' => (bool) env('SSLCOMMERZ_ENABLED', false),
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
            'currency' => env('SSLCOMMERZ_CURRENCY', 'BDT'),
        ],

        'bkash' => [
            'enabled' => (bool) env('BKASH_ENABLED', false),
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'sandbox' => (bool) env('BKASH_SANDBOX', true),
        ],

    ],

];
