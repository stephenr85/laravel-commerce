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
    | Custody posture
    |--------------------------------------------------------------------------
    |
    | Who holds the money (purchase-walkthrough BUY-4): `none` offers no purchase,
    | `hosted` collects through a provider-hosted leg, `direct` charges in-app.
    | MoneyIn::posture() reads ONLY this key; the rail above never changes it.
    | Unset keeps today's behaviour (`hosted`). A host that collects nothing sets
    | `none` and binds the `none` rail.
    |
    */
    'custody' => env('COMMERCE_CUSTODY'),

    /*
    |--------------------------------------------------------------------------
    | Fake rail
    |--------------------------------------------------------------------------
    |
    | The `fake` driver's own settings. `decline_minor_units` is its MAGIC DECLINE
    | AMOUNT — an Order totalling exactly this comes back Failed with
    | `decline_code`, the way a real sandbox declines one magic card number. It
    | exists because the in-process outcome script (FakeDriver::fakeDecline()) is
    | unreachable from a browser fixture driving a real HTTP server, which leaves
    | the failed-checkout half of a payment journey unprovable end to end. Any
    | other amount succeeds; an in-process script still wins; the real drivers
    | never read this. Set to null to disable.
    |
    */
    'fake' => [
        'decline_minor_units' => env('COMMERCE_FAKE_DECLINE_MINOR_UNITS', 66602),
        'decline_code' => env('COMMERCE_FAKE_DECLINE_CODE', 'card_declined'),
    ],

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
        'auto_reload_configs' => 'commerce_auto_reload_configs',
        'auto_reload_attempts' => 'commerce_auto_reload_attempts',
        'acp_checkout_sessions' => 'commerce_acp_checkout_sessions',
        'acp_orders' => 'commerce_acp_orders',
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
        // The Wallet unit engine-owned credit-topup funding credits in. Currency units
        // fund in major units (minor/100); a host with a non-currency unit (tokens) sets
        // this and applies its own rate.
        'unit' => env('COMMERCE_CREDIT_UNIT', 'usd'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-reload
    |--------------------------------------------------------------------------
    |
    | Off-session prepaid-credit top-up: when a party's Wallet balance drops below
    | a configured floor, the engine tops it up behind the money-in driver with no
    | user present. `defaults` fill an unset tenant value; `policy` is the hard
    | safety clamp a tenant value can never weaken — effective = clamp(config,
    | policy) — the runaway defense for autonomous off-session money movement.
    |
    */
    'autoreload' => [
        'defaults' => [
            'amount_mode' => 'fixed',
            'cooldown_seconds' => 300,
            'period_days' => 30,
        ],
        'policy' => [
            'min_cooldown_seconds' => 60,
            'max_spend_ceiling_usd' => 500,
            'max_reloads_ceiling' => 30,
            'max_per_reload_ceiling_usd' => 200,
            // Consecutive instrument-declines before the engine auto-disables a config
            // (non-tunable — a failure backstop, not a tenant knob).
            'disable_after_consecutive_failures' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agentic Commerce Protocol (sell-side)
    |--------------------------------------------------------------------------
    |
    | The ACP merchant seam: an external shopping agent discovers an offer and
    | completes an Agentic Checkout, handing over a delegate payment token the
    | satellite charges as merchant-of-record. `route_root` is the URI root the
    | host mounts the protocol under (via `Route::commerceAcpRoutes()`). `fixtures`
    | are the tracer's stand-in offers, keyed by opaque catalog ref — slice 02
    | rebinds `OfferResolver` to a real schema.org/ACP feed and these go unused.
    |
    */
    'acp' => [
        'route_root' => env('COMMERCE_ACP_ROUTE_ROOT', 'agentic-commerce'),

        'fixtures' => [
            'demo-offer' => [
                'name' => 'Demo Offer',
                'amount' => 900,
                'currency' => 'USD',
            ],
        ],
    ],
];
