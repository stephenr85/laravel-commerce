# Two line items: a chargeable `Money` line vs a sub-cent metering line

**Status:** accepted

`laravel-commerce` keeps **two** distinct line-item types, not one:

- **`LineItem`** (`src/Data/LineItem.php`) — a line on a checkout `Order`, carrying a
  **`Money`** subtotal (integer minor units + currency).
- **`BillingLineItem`** (`src/Data/BillingLineItem.php`) — a line on a composed
  cost-metering **Bill**, carrying a **fractional-USD** `amountUsd` (float).

They meet — and the fractional total is rounded once into a chargeable `Money`
amount — only at settlement (`Bill → Invoice → Payment`, the slice-08 seam).

## Why

The two lines measure different things:

- **Checkout is cent-exact.** A customer is charged whole minor units ($9.00 = `900`),
  and Stripe's API itself takes integer minor units. `Money` models this precisely and
  hands the provider boundary exactly what it expects.
- **Cost metering is sub-cent.** A unit of usage (an LLM token, a generation) costs a
  *fraction of a cent* — e.g. `$0.0000034`. The platform already stores this as
  `decimal(_,6)` (six decimal places of a dollar) throughout its cost tables
  (`bills.total_usd`, `token_transactions.cost_usd`, the usage summaries), and its
  `App\Data\LineItemData` carries a `float $amountUsd`.

`Money`'s integer minor units (cents) **cannot represent `$0.0000034`.** Forcing the
metering line onto `Money` would mean either rounding every token to a whole cent (which
destroys the metering math the whole billing engine exists to compute) or redefining
"minor units" as micro-dollars for *all* amounts (which pushes sub-cent precision onto
checkout and mismatches Stripe's cents at the provider boundary).

So the split is not an oversight — it is the domain: **what a customer is charged**
(cent-exact `Money`) and **what a unit of usage cost, before rounding** (sub-cent float)
are genuinely different quantities. The extraction of the platform's billing engine
(the ministerial `app/Billing`) surfaced this; the original one-line glossary entry
predated it.

## Considered options

- **Keep both, convert once at settlement** — accepted. The metering engine works in
  fractional USD; the single conversion to a chargeable `Money` amount happens at the
  `Bill → Invoice` projection, where rounding belongs. Cost: this ADR + a glossary edit;
  no code churn.
- **One `LineItem`, redefine `Money` to micro-units** — rejected. Sub-cent becomes
  representable and the glossary holds, but every amount (including checkout) is now in
  millionths of a dollar — surprising, and error-prone exactly at the Stripe boundary,
  which wants integer cents. Larger blast radius than the problem.
- **One `LineItem` on `float USD` everywhere** — rejected. Floating-point money at the
  charge boundary is the classic defect: rounding drift, and a representation that does
  not match Stripe's integer cents.

## Consequences

- `LineItem` is the checkout/Order line; `BillingLineItem` is the metering/Bill line.
  Neither is a synonym for the other, and code should not convert between them except at
  the settlement seam.
- **Rounding is confined to one place** — the `Bill → Invoice` projection (slice 08).
  That projection sums the fractional-USD lines and rounds once to a `Money` total; the
  rounding rule (and any minimum-charge / rounding-direction policy) is documented there,
  not scattered.
- The platform's `App\Data\LineItemData` (float USD) maps 1:1 onto `BillingLineItem`
  when `app/Billing` adopts the extracted engine — the two-line model is what makes that
  adoption a faithful, lossless swap rather than a precision-losing rewrite.
- `CONTEXT.md` is updated: the `LineItem` entry is narrowed to the checkout line, and a
  `BillingLineItem` entry is added under the cost-of-goods vocabulary.
