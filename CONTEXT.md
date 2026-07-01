# Commerce

The shared commerce engine for the ecosystem: the vocabulary and behavior of **money-in**
(a party collecting payment from another party) plus the **cost-of-goods** metering that
sits beneath it. A single package of `spatie/laravel-data` DTOs (projected DTO → TypeScript
→ JSON Schema via `laravel-data-schemas`) and the behavior over them (pricing, billing
composition, spend gating, checkout via Laravel Cashier, gifting). Consumed **directly** by
`splicewire-app` and by every satellite; the satellite-specific integration lives in a
separate `laravel-splicewire-satellite-commerce` package layered on top.

Provider mechanics live behind a driver seam (Stripe/Cashier is the only driver today).
Host- and vertical-specific vocabulary (a numero Subject, an audiostud license, a compliance
report) never lives here.

**Never merchant-of-record:** every `Merchant` owns its own provider account; the model never
expresses one party collecting on behalf of another.

## Language

### Parties

**Merchant**:
The party that collects payment and owns the payment-provider account. Fractal — the platform,
a satellite app, and a broker are each a Merchant with their own account.
_Avoid_: platform, seller, vendor, payee

**Customer**:
The party that pays.
_Avoid_: client, buyer, account, user, payer

### Catalog

**Offer**:
Something a Merchant makes available for purchase, at a Price, with a Cadence.
_Avoid_: SKU, product, item

**Plan**:
A named Offer composed of ordered priced components, forming a tier in a ladder; a subscription
binds a Customer to one and may override its components.
_Avoid_: package, tier (tier = a Plan's rung, not a synonym)

**Price**:
The Money charged for an Offer or one of a Plan's components, resolved by a pricing strategy.
_Avoid_: rate, cost

**Discount**:
A reduction applied to an Order or line — including a promo/gift code.
_Avoid_: coupon (coupon = a Discount's redeemable code), markdown

**TaxLine**:
A jurisdiction's tax applied to an Order as its own line.
_Avoid_: VAT, levy

### Transaction

**Order**:
The envelope of a single money-in transaction a Customer places with a Merchant — its line
items, total, and cadence.
_Avoid_: transaction, cart, checkout

**LineItem**:
One priced line within an Order (or a Bill), carrying its Money subtotal.
_Avoid_: item, row

**Money**:
An immutable amount as minor units plus a currency.
_Avoid_: price, amount, cost

**Cadence**:
Whether an Order recurs: `one_time` or `recurring`.
_Avoid_: frequency, interval, term

### Money-in lifecycle

**Invoice**:
A statement of what is owed, before payment. The platform's `Bill` projects into an Invoice.
_Avoid_: bill (Bill = the platform's internal cost-metering statement), statement

**Payment**:
The fact that money moved for an Order — amount, time, status. Provider capture mechanics stay
behind the driver seam.
_Avoid_: charge, transaction

**Receipt**:
The Customer-facing proof of a completed Payment.
_Avoid_: confirmation

**Refund**:
A reversal of a Payment.
_Avoid_: chargeback (a chargeback is provider-initiated; distinct)

### Outcome

**Purchase**:
The durable record of what money bought — a perpetual unlock, a subscription term, or a Credit
top-up — with its status and provider reference. The outcome of a paid Order.
_Avoid_: Entitlement (reserved — see below), Order, transaction

### Stored value

**Credit**:
A unit of prepaid, consumable value held in a Wallet — bought with Money at a pricing tier and
debited by usage. The unit is host-defined (tokens, generations, or USD).
_Avoid_: token, point, coin (host names for a Credit)

**Wallet**:
A party's running balance of Credits: purchased credits minus consumed debits, over one append-only
ledger. Its balance feeds the spend gate (a `Credit` implementation of the budget source).
_Avoid_: balance, account, purse

### Gifting

**Gift**:
A Purchase whose **payer differs from its beneficiary** — the buyer pays; someone else receives.
Wraps *any* Purchase, including a Credit pack (gifted credits need no special form).
_Avoid_: present, share

**Redemption**:
The lifecycle by which a Gift's Beneficiary claims it: issued → delivered → redeemed, via a
redemption code decoupled from the payer's account.
_Avoid_: claim (claim = one step; redemption is the whole lifecycle), activation

**Beneficiary**:
The party a Purchase is *for*, when not the Customer who paid.
_Avoid_: recipient, giftee

## Related concepts this context does NOT own

Kept out on purpose to stop "entitlement" smearing across three axes:

- **Entitlement** — *feature-inclusion*: what a party's plan includes. A downstream host/platform
  concept (`splicewire-app`'s `app/Entitlements`), produced *from* a Purchase. Never a synonym
  for Purchase.
- **Grant** — *access*: who may see or use a thing. A separate downstream axis.
