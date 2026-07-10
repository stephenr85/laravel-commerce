<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Money-in driver
    |--------------------------------------------------------------------------
    |
    | Which driver the money-in service resolves per request: `fake` records
    | the same DTOs and fires the same events with no network (seeders + tests),
    | `stripe` collects real money (Cashier + PaymentIntents). Selected per-tenant
    | in production by pointing this at the tenant's configured mode.
    |
    */
    'driver' => env('COMMERCE_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Credentials for the default single-Merchant resolver. A tenancy-aware host
    | (the satellite layer) ignores `secret` here and resolves each billing
    | party's own key from its `TenantConfig` — the platform is never
    | merchant-of-record. `webhook_secret` verifies inbound Stripe callbacks.
    |
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'merchant_id' => env('COMMERCE_MERCHANT_ID', 'default'),
        'merchant_name' => env('COMMERCE_MERCHANT_NAME'),

        // The app-local path the `commerce:stripe-listen` dev command forwards Stripe
        // webhooks to (joined onto app.url). Defaults to Cashier's conventional path;
        // override if a consumer mounts its webhook elsewhere. This keeps the command
        // Cashier-agnostic — it never reads cashier.* config.
        'webhook_path' => env('STRIPE_WEBHOOK_PATH', 'stripe/webhook'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | ISO-4217 code used when an Order is built without an explicit currency.
    |
    */
    'currency' => env('COMMERCE_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Single-tenant consumers auto-load the package migrations as central
    | migrations. A multi-tenant broker sets this false and publishes them into
    | its per-tenant migration set instead (the satellite layer flips this).
    |
    */
    'register_migrations' => env('COMMERCE_REGISTER_MIGRATIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Prefixed to avoid colliding with a host's own tables. A consumer may
    | publish this config and rename them.
    |
    */
    'table_names' => [
        'redemptions' => 'commerce_redemptions',
        'credit_entries' => 'commerce_credit_entries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Redemption
    |--------------------------------------------------------------------------
    |
    | How long an issued Gift redemption code stays claimable, in days. Null =
    | never expires.
    |
    */
    'redemption' => [
        'ttl_days' => env('COMMERCE_REDEMPTION_TTL_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credit / Wallet
    |--------------------------------------------------------------------------
    |
    | The soft-warn threshold (fraction of the credited balance consumed) at
    | which the spend gate warns before the hard stop at exhaustion.
    |
    */
    'credit' => [
        'warn_fraction' => env('COMMERCE_CREDIT_WARN_FRACTION', 0.8),
    ],
];
