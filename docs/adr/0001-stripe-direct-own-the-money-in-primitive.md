# Stripe-direct via Cashier; own the money-in primitive; no generic gateway library

**Status:** accepted

`laravel-commerce` owns a neutral money-in primitive — `Order → Payment → Receipt → Purchase` —
and implements it on **Stripe** (one-off via PaymentIntents/Checkout, recurring via Laravel
Cashier), behind a thin `fake | stripe` mode switch selected per-tenant by config. We do **not**
adopt a generic multi-gateway library, and we do **not** wrap Cashier's behavior in a
provider-portability interface.

## Why

The processor-neutral "server processes a card" layer that a generic gateway library promises was
obsoleted by **client-side tokenization (PCI SAQ-A)** and **SCA/3DS2** (client-side auth
challenges). A uniform server-side interface can now only cover the trivial hosted-redirect case
or it leaks each processor's specifics. So "basic payment processing" is inherent to *our* package
(the neutral primitive), not to a fantasy generic layer — implemented on one real processor.

## Considered options

- **Omnipay (thephpleague)** — rejected: built for the pre-tokenization world; v3 maintenance
  stalled, many drivers unmaintained, weak on SCA/3DS2. A sand foundation for modern cards + subs.
- **stephenjude/laravel-payment-gateways** — rejected as a foundation: a fine thin hosted-redirect
  abstraction, but small, community-maintained, scoped, and no subscription depth. A reference, not
  a base to bet the ecosystem on.
- **Wrapping Cashier in a generic `PaymentProvider` interface** — rejected: Cashier already
  abstracts Stripe; a second abstraction either mirrors it (pointless) or is a lowest-common-
  denominator that hides Cashier's value (Checkout, billing portal, proration, metered, webhooks).
  Cashier-Paddle is Merchant-of-Record, which we've ruled out anyway.

## Consequences

- We normalize the **data** (our DTOs, for UI + DTO→TS→JSON-Schema + cross-app consistency), not
  the provider **behavior** — Cashier/Stripe is called directly and fully.
- A real second processor (e.g. PayPal, if ever demanded) becomes a **second driver behind the same
  `Payment` primitive**, written on that processor's own SDK — never via a generic library.
- Going live is a **credential/mode swap** in the tenant `data` column (`fake`/test keys → live
  keys), not a code change. The `fake` driver powers deterministic seeders and feature tests.
