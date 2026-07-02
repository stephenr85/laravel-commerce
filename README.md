# laravel-commerce

The shared money-in engine for the ecosystem: a neutral `Order → Payment → Receipt → Purchase`
primitive behind a driver seam, plus the cost-of-goods metering that sits beneath it — billing
composition, spend gating, credit/wallet, gifting, a card vault, and a small catalog.

It is a package of `spatie/laravel-data` DTOs and the behavior over them. Provider mechanics
(Stripe via Laravel Cashier) live behind a driver; host- and vertical-specific vocabulary — a
numero Subject, a compliance report, an audiostud license — never lives here. `splicewire-app`
and every satellite consume it directly; tenancy-aware wiring lives in a separate
`laravel-splicewire-satellite-commerce` package layered on top.

**Never merchant-of-record.** Every `Merchant` owns its own provider account. The model has no way
to express one party collecting on behalf of another — a broker collecting for its own sub-tenants
is that broker being a Merchant, resolved per request.

See [CONTEXT.md](CONTEXT.md) for the full domain glossary; it is the source of truth for the
vocabulary used throughout.

## Installation

```
composer require rushing/laravel-commerce
```

The service provider is auto-discovered. Publish the config to choose a driver:

```
php artisan vendor:publish --tag=commerce-config
```

```php
// config/commerce.php
'driver' => env('COMMERCE_DRIVER', 'fake'), // 'fake' | 'stripe'
```

`laravel/cashier` and `stripe/stripe-php` are only needed for the `stripe` driver — a host using
the metering plane alone does not require them.

## Money-in

One primitive collects money regardless of provider. Build an `Order`, place it, get a `Purchase`:

```php
use Rushing\Commerce\MoneyIn;
use Rushing\Commerce\Data\Order;

$purchase = app(MoneyIn::class)->place($order);

$purchase->payment->succeeded();     // bool
$purchase->payment->requiresAction(); // customer must authenticate (e.g. 3-D Secure)
```

The `fake` driver records the full `Order → Payment → Receipt → Purchase` graph with no network,
so seeds and tests run offline. Swapping to `stripe` is a driver/credential change, not a code
change: the same feature path drives both.

The engine emits neutral events (`PurchaseCompleted`, `PaymentRefunded`, `GiftRedeemed`, ...). What
a purchase *grants* is the host's concern — the host maps a `Purchase` to its own access model
(`splicewire-app` maps it to an Entitlement/Grant). The engine never owns "entitlement".

## Host seams

The engine defines contracts and, for the optional ones, ships no default binding. A host binds
what it needs:

- `MerchantResolver` — the billing party that collects for an Order (`parent_tenant_id ?? self` in a
  tenancy-aware host). Ships a single-merchant config default.
- `StripeClientFactory` — a Stripe client on the resolved Merchant's own key.
- `SubscriptionBinder` — recurring Orders; the host calls Cashier directly (Cashier is never wrapped).
- `CustomerVault` — saved cards (see below); no default binding.
- `UsageMeter` — the read side of the spend gate; each host keeps its own usage ledger.
- `ComponentRegistry` / `BillingComponent` — the host registers its own priced components.

## Cost-of-goods metering

The plane beneath money-in: composing what a billing party owes from metered usage, in fractional
USD (a token or a generation costs a fraction of a cent, so these amounts are not `Money`).

`BillComposer` runs a Plan's `BillingComponent`s for a `BillingPeriod` and totals their
`BillingLineItem`s into a `ComposedBill`. A finalized bill projects into an `Invoice` (one
chargeable `Money` total) and is collected via `Settlements`, which ties the bill to its `Purchase`.
The sub-cent metering line and the cent-exact checkout `LineItem` meet exactly once, at settlement,
where the total rounds into `Money` (ADR-0002).

## Stored value

`Credit` is prepaid, consumable value held in a `Wallet` (an append-only credit/debit ledger); the
unit is host-defined (tokens, generations, USD). A `Credit` implements the same `BudgetSource` a
postpaid spending cap does, so the `BudgetGate` gates prepaid and postpaid usage with one code path —
allow, warn, or deny against `cap` vs `spend`.

## Gifting

A `Gift` is a `Purchase` whose payer differs from its beneficiary. The buyer pays; the engine issues
a `Redemption` (issued → delivered → redeemed) via a code decoupled from the payer's account, and the
beneficiary claims it — attaching on signup, for example. Wraps any Purchase, including a credit pack.

## Card vault

`CustomerVault` is the optional saved-card seam — create-or-get a provider customer, begin a card
setup (`SetupTicket`), and list / default / forget cards, all scoped by `(Customer, Merchant)`. A
saved card belongs to the one account that will charge it and never crosses accounts. The card number
is tokenized by the provider client-side and never reaches your servers. A host typically backs the
contract with Cashier's `Billable`.

A charge that needs authentication returns a `Payment` in the `RequiresAction` status; the host
re-prompts on-session using the Payment's `providerRef`. The engine exposes the state, not the
provider's next-action payload.

## Testing without live keys

The Stripe driver is exercised by injecting a canned `Stripe\HttpClient\ClientInterface`, so the real
SDK object graph and the DTO mapping run with no network and no keys. See `tests/` for the pattern
(`CannedStripeHttpClient`) and cross-driver parity coverage.

```
composer test
```

## License

MIT.
