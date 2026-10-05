# A `Payment` carries a hosted action; `MoneyIn` gains `manage()` and `posture()`

**Status:** accepted. Owner question **BQ-11** (purchase-walkthrough SPEC) was answered on 2026-10-05: accept, the
recommended option (purchase SPEC decided, ecosystem 04de2be0). It had been implemented as the default while the
question was open; nothing changed on acceptance.

Three additions to the engine's rail (purchase-walkthrough BUY-02, ruling C-11):

- `Payment::$action` (`?PaymentAction`): the hosted leg a `RequiresAction` payment still needs, as ONE field with two
  kinds: `redirect` (a URL) or `embedded` (a client secret). An `Order` asks for it with `hosted` and `returnUrl`.
  It settles exactly as before: the provider's webhook (Stripe's `checkout.session.completed`, read by the existing
  `StripeWebhookTranslator::creditTopup()`) or the fake rail's local confirm page dispatches the same
  `PurchaseCompleted`.
- `MoneyIn::manage(Customer, returnUrl)`: the customer's self-service billing page, a redirect action. Every
  `MoneyInDriver` implements it; `none` refuses.
- `MoneyIn::posture()`: who holds the money, read only from `commerce.custody` (`none | hosted | direct`). The rail
  never changes it. Unset is `hosted`, today's behaviour.

A fourth rail, `none`, refuses every verb. It is the rail of a host that collects no money, and unlike `fake` it is
legal in production.

## Why

ADR-0001 says the engine does not wrap Cashier's surface. Without these verbs, four money paths stay off the rail:
credit top-up, the billing portal, the broker invoice, and the plan checkout. The rail would then be bypassed exactly
where a fake rail matters, and BUY-1's static ban could never go green.

These additions do not wrap Cashier. The rail reports a payment outcome that the provider already produces: a
Checkout Session's client secret or URL, and a billing-portal URL. Cashier is not in the call path at all (the Stripe
driver calls the SDK directly).

One field, not a sibling contract: a hosted leg is a state of a payment (`RequiresAction` plus how to resolve it),
so it belongs on the `Payment` the caller already receives. This is P-A4's own flip condition, met without a second
seam.

## Had BQ-11 been rejected (recorded for the reasoning)

Move the two verbs and the field to a beam-commerce sibling contract, `Hosted`, under the same manager and the same
`commerce.driver` key. BUY-01's static ban and BUY-03's runtime dataset are unchanged, so no rule changes; only the
home of `manage()`, `posture()` and `PaymentAction` moves. Callers change their import, not their behaviour.

## Consequences

- `MoneyInDriver` gains `manage()`. The shipped drivers (`fake`, `stripe`, `none`) are its only implementations.
- The fake rail's pages are host-mounted (`Route::commerceFakeRailRoutes()`, inside `web`), like the ACP routes. An
  unmounted fake hosted leg throws, naming the macro.
- Tests: `tests/MoneyInRailTest.php`. The §2.4 embedded-session probe drives the credit flow through the Stripe
  adapter with a stubbed transport. Every `PurchaseKind` is covered on `fake`, `none` and `stripe`. Zero outbound HTTP
  outside the stubbed transport.
