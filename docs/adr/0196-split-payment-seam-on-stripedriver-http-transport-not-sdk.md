# StripeDriver gains a Connect-shaped split-payment seam, over `Http`, not the SDK

**Status:** accepted

`StripeDriver` gains a genuinely new charge/refund/dispute-reversal path — {@see
`Rushing\Commerce\Data\Split`}, an optional field on `Order`/`Payment` — reached only when a caller
(today, exclusively `splicewire/laravel-beam-market`'s marketplace checkout, ticket 06 of the
splicewire-marketplace build) supplies one. When present, `pay()` resolves it to Stripe destination-
charge parameters (`application_fee_amount` + `transfer_data.destination`); `refundWithSplit()` and
`reverseTransferForDispute()` (new, driver-only methods, not on `MoneyInDriver`) resolve refund- and
dispute-time proration to `refund_application_fee`/`reverse_transfer`/`transfer_reversal_amount` and a
`/v1/transfers/{id}/reversals` call, respectively.

## Why this is hard to reverse

This is the engine's first Connect-shaped money-movement path. Once a marketplace transacts real
money through it, `StripeDriver`'s charge lifecycle and refund/dispute handling for a Split-carrying
Payment become load-bearing for every future split-payment consumer, not just this ticket's — undoing
or reshaping it later means migrating live payment/refund/reversal semantics, not a quiet refactor.

## What we decided

1. **`Split` stays provider-neutral in name and lives on `Data/`, never `Contracts/`.** `platformFee`
   (Money) + an optional `payeeRef` (string) — no field named for a Stripe Connect concept
   (`destinationAccount`, `applicationFee`, `transferData`). The literal Stripe param names
   (`application_fee_amount`, `transfer_data`, `destination`) appear ONLY inside `StripeDriver`'s own
   method bodies — the one place "plain Stripe parameters below the DTOs" (per the ticket's own
   framing) are allowed to exist. `laravel-beam-market`'s own `tests/Unit/CommerceStaysConnectBlindTest.php`
   already enforces the adjacent, stricter constraint (no Connect ACCOUNT-PROVISIONING vocabulary —
   `Connect`, `on_behalf_of`, `fees_collector`, Accounts v2, etc. — anywhere in this package); that
   guard's own token list deliberately excludes `application_fee_amount`/`transfer_data`/`destination`,
   confirming this ticket's vocabulary was anticipated and is intentionally in scope for `src/Drivers`.
2. **A `null` `payeeRef` is "no split partner," not "skip the seam."** A first-party ($0-fee) sale
   still carries a `Split` (so it goes through the exact same `payWithSplit()` method as a paid
   third-party sale — one shared entry point, no bypass branch) but with `platformFee` zero and
   `payeeRef` null; `intentParams()` omits the Connect keys entirely in that case, so the charge is a
   plain PaymentIntent with the fee forced to zero by construction, not by a hand-written conditional.
3. **The split-payment transport is `Illuminate\Support\Facades\Http`, not the `stripe-php` SDK** —
   deliberately breaking from the existing SDK-based `pay()`/`refund()`. `Splicewire\Beam\Market\
   Payout\Stripe\StripePayoutGatewayResolver` (ticket 05) already established the precedent for this
   exact reason: going through `Http` is what lets a test assert the exact request params via
   `Http::fake()`, which the ticket's own acceptance criteria require for the destination-charge
   params, the fee-forced-to-zero path, and the dispute-webhook transfer reversal. The secret is
   pulled from the SAME `StripeClientFactory` seam the SDK path already uses
   (`StripeClient::getApiKey()`), so a tenancy-aware host's factory rebinding covers both transports
   with nothing new to keep in sync.
4. **The existing SDK-based `pay()`/`refund()` path is untouched for every split-less Order/Payment.**
   `Order::$split`/`Payment::$split` are both nullable, appended as the LAST constructor param on
   each `spatie/laravel-data` DTO — every existing fleet-wide caller (`Order::for(...)` is always
   called with named arguments; confirmed by grep across the whole workspace) is unaffected. `pay()`
   branches to `payWithSplit()` only when `$order->split !== null`; nothing about the non-split path's
   control flow, params, or SDK usage changed. `refundWithSplit()`/`reverseTransferForDispute()` are
   NEW public methods, not additions to the `MoneyInDriver` interface — `FakeDriver` and every other
   implementer are unaffected, and a marketplace caller reaches them by type-hinting the concrete
   `StripeDriver`.
5. **Refund/dispute proration is computed explicitly, not left entirely to Stripe's own auto-proration.**
   `refundWithSplit()` still sets Stripe's own `refund_application_fee`/`reverse_transfer` flags (so
   Stripe's documented proportional application-fee refund behavior applies), but computes and sends
   an explicit `transfer_reversal_amount` via the driver's own `proratedMinorUnits()` — floor-rounded,
   never over-reversing — so the exact arithmetic is asserted directly by the feature test rather than
   trusted to an opaque provider-side calculation. `reverseTransferForDispute()` uses the same helper
   against a live `GET /v1/charges/{id}` lookup (the dispute webhook payload itself carries no
   transfer id), pro-rating the transfer reversal to the disputed amount.

## Consequences

- A second Connect-shaped consumer of `StripeDriver` (a future marketplace-shaped package) reuses
  `Split`/`payWithSplit()`/`refundWithSplit()`/`reverseTransferForDispute()` directly — no new driver
  method needed, only a `Split` supplied on its own Orders/Payments.
- `StripeDriver` now speaks to Stripe over two transports (SDK for the legacy path, `Http` for the
  split path). This is accepted complexity, not a target to unify — collapsing the SDK path onto
  `Http` (or vice versa) is explicitly out of this ticket's scope and would touch the widely-depended,
  currently-proven non-split path for no split-payment benefit.
- `MoneyInDriver` itself — the neutral contract every driver implements — carries zero Connect
  vocabulary and zero new methods; `ContractsProviderNeutralityTest` and
  `CommerceStaysConnectBlindTest` both continue to pass unmodified.
- v1 is one-time-purchase only: `Split` is never read on the `Cadence::Recurring` branch (`pay()`
  checks cadence before checking `split`), and no recurring/usage-metered split-payment path exists —
  that remains ticket 13's scope.
