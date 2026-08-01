# The `Contracts/` surface names no payment provider; provider-specific contracts are provider-scoped

**Status:** accepted

The engine's neutral contract surface — `Rushing\Commerce\Contracts\` — names **zero** payment
providers. A contract that is inherently provider-specific (it returns or accepts a provider SDK
type) lives in a **provider-scoped** namespace instead: `Rushing\Commerce\Stripe\`. The
`StripeClientFactory` interface (`for(Merchant): Stripe\StripeClient`) was the one leak and now sits
under `Rushing\Commerce\Stripe\`, alongside its config implementation `ConfigStripeClients`. A guard
(`tests/ContractsProviderNeutralityTest.php`) fails if any `use Stripe\` is reintroduced under
`src/Contracts/`.

## Why

ADR-0001 keeps the engine processor-neutral in its **data** (neutral DTOs) while implementing on one
real processor. The neutral seam is the `Contracts/` namespace: `MoneyInDriver`, `MerchantResolver`,
`CustomerVault`, `SubscriptionBinder`, `UsageMeter`, etc. — none of which names a provider. A
provider-typed contract in that same directory (returning `Stripe\StripeClient`) crossed the agnostic
boundary in name and in import, eroding the "swap the driver, not the core" promise even though the
structure was otherwise sound. Keeping the **name** `StripeClientFactory` (Stripe-named things are
honest) but moving it to a **Stripe-scoped namespace** resolves the leak without a rename churn.

## Consequences

- `src/Contracts/` is grep-clean of `use Stripe\`, enforced by the regression guard — a future
  provider-typed contract cannot silently land there.
- A second real processor (per ADR-0001) gets its own scoped namespace (`Rushing\Commerce\<Provider>\`)
  for any provider-typed factory, never `Contracts/`.
- `stripe/stripe-php` stays in the engine's **`require-dev`** (with a `suggest`), not `require`: the
  neutral engine is usable with the fake driver and Cashier/Stripe absent (a cost-only metering
  tenant). The Stripe **arm** that hard-depends on the SDK is the composed layer
  (`splicewire/laravel-beam-commerce`), where `stripe/stripe-php` is a first-class `require` — recorded
  in that package's ADR-0002.
- Sibling to ADR-0001: same doctrine (neutral core, provider isolated), now enforced at the namespace
  boundary, not just by convention.
