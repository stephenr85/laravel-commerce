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
One priced line within a checkout Order, carrying its Money subtotal (integer minor
units). Cent-exact — it is what a Customer is charged. The sub-cent cost-metering line
is a separate concept (see BillingLineItem); the two meet only at settlement, where the
metered total rounds once into a chargeable Money amount (ADR-0002).
_Avoid_: item, row, BillingLineItem

**Money**:
An immutable amount as minor units plus a currency.
_Avoid_: price, amount, cost

**Cadence**:
Whether an Order recurs: `one_time` or `recurring`.
_Avoid_: frequency, interval, term

**BillingAddress**:
A postal address carried on an Order for address verification and tax, passed into the
provider's billing-details. Transaction-scoped — a single purchase can use a different address
than any stored default. There is no shipping address: the engine models money-in, not fulfillment.
_Avoid_: shipping address, address book

### Cost-of-goods metering

The plane that sits beneath money-in: composing what a billing party owes from metered
usage. Works in fractional USD because a unit of usage (a token, a generation) costs a
fraction of a cent — so its amounts are not `Money` (see ADR-0002).

**BillingLineItem**:
One composed line on a cost-metering Bill, in fractional USD (`amountUsd`). Distinct from
the cent-exact checkout LineItem: generation costs run sub-cent, so the metering line
keeps six-decimal USD, not integer minor units. Rounds into a Money amount only at
settlement (ADR-0002).
_Avoid_: LineItem (the checkout line), price

**BillingComponent**:
One priced element of a Plan — computes a single BillingLineItem for a period from its
config. The host binds whatever subject it needs (seats, tokens); the engine never names
it.
_Avoid_: fee, charge, meter

### Money-in lifecycle

**Invoice**:
A statement of what is owed, before payment. The platform's `Bill` projects into an Invoice —
preserving the Bill's fractional lines but carrying one chargeable Money total (rounded once, ADR-0002).
_Avoid_: bill (Bill = the platform's internal cost-metering statement), statement

**Settlement**:
The record that an Invoice projected from a Bill was collected: it ties the originating Bill to the
Invoice and the resulting Purchase (whose Payment carries provider state). Closing the loop from
"computed as owed" to "charged" — the Bill stays authoritative; a Settlement never recomputes it.
_Avoid_: payment (Payment = the money movement), reconciliation

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

### Card vault

The saved-card seam. Optional and host-implemented (the `CustomerVault` contract) — the engine binds
no default, like the recurring seam. Every entry is scoped to one Merchant's provider account: a saved
card belongs to the account that will charge it and never crosses accounts. The card number is
tokenized by the provider client-side and never touches our servers.

**CustomerVault**:
The contract for card-on-file: create-or-get a provider customer, begin a card setup, and list /
default / forget saved cards — all scoped by (Customer, Merchant). A host typically backs it with
Laravel Cashier's Billable.
_Avoid_: wallet (Wallet = a Credit balance, a different concept), card store

**VaultedCustomer**:
The link between a host-owned Customer id and the provider's opaque customer reference on one
Merchant account.
_Avoid_: stripe customer, account

**VaultedPaymentMethod**:
A saved card as neutral display data (brand, last4, expiry, default flag) plus its opaque provider
reference — the reference a later Order carries to charge it. Never holds a PAN.
_Avoid_: card, token, source

**SetupTicket**:
The handle to attach a card without charging: the client secret the browser confirms plus the
provider's setup reference.
_Avoid_: setup intent, client secret (that is one field of the ticket)

**RequiresAction** (a `Payment` status):
The charge could not complete unattended — the Customer must authenticate (e.g. a 3-D-Secure
challenge). The host re-prompts on-session using the Payment's providerRef; the engine exposes the
state, not the provider's next-action payload.
_Avoid_: SCA, 3DS, requires_confirmation

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
