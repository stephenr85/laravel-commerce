<?php

namespace Rushing\Commerce\Acp;

use Illuminate\Support\Str;
use Rushing\Commerce\Acp\Contracts\BeneficiaryResolver;
use Rushing\Commerce\Acp\Contracts\CheckoutSessionStore;
use Rushing\Commerce\Acp\Contracts\OfferResolver;
use Rushing\Commerce\Acp\Contracts\OrderStore;
use Rushing\Commerce\Acp\Data\AgentProvenance;
use Rushing\Commerce\Acp\Data\CheckoutLineItem;
use Rushing\Commerce\Acp\Data\CheckoutSession;
use Rushing\Commerce\Acp\Data\DelegatePayment;
use Rushing\Commerce\Acp\Enums\CheckoutStatus;
use Rushing\Commerce\Acp\Exceptions\CheckoutException;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\MoneyIn;

/**
 * The ACP Agentic Checkout **protocol seam**: create a session against resolved
 * offers, retrieve it, and complete it by charging the delegate payment token
 * through the existing money-in driver (`fake` in the tracer, the satellite's own
 * `stripe` vault in slice 03) and recording a minimal order + agent-provenance
 * trace. Orthogonal to the commerce substrate — it composes the `OfferResolver` /
 * `OrderStore` ports and the neutral `MoneyIn` boundary, never a host catalog.
 */
class AgenticCheckout
{
    public function __construct(
        private OfferResolver $offers,
        private CheckoutSessionStore $sessions,
        private OrderStore $orders,
        private MoneyIn $moneyIn,
        private BeneficiaryResolver $beneficiaries,
    ) {}

    /**
     * Open a checkout session for the given line requests. Each request names an
     * opaque catalog ref and a quantity; unknown refs are rejected so a session is
     * never created against an unpurchasable offer.
     *
     * @param  array<int, array{item_ref: string, quantity?: int}>  $items
     */
    public function create(array $items, ?AgentProvenance $provenance = null, ?string $currency = null): CheckoutSession
    {
        if ($items === []) {
            throw CheckoutException::emptyCart();
        }

        $lines = [];
        foreach ($items as $request) {
            $ref = (string) ($request['item_ref'] ?? '');
            $quantity = max(1, (int) ($request['quantity'] ?? 1));

            $offer = $this->offers->resolve($ref);
            if ($offer === null) {
                throw CheckoutException::unresolvableOffer($ref);
            }

            $unit = $offer->price->amount;
            $lines[] = new CheckoutLineItem(
                id: (string) Str::uuid(),
                itemRef: $ref,
                name: $offer->name,
                quantity: $quantity,
                unitAmount: $unit,
                amount: $unit->times($quantity),
            );
        }

        // Reconcile currencies before summing — an agent may request a currency the
        // offers aren't priced in, or (via multiple refs) mix currencies. Reject it as a
        // protocol error (422) rather than letting Money::plus throw an uncaught 500 on
        // the public endpoint.
        $currency ??= $lines[0]->unitAmount->currency;
        foreach ($lines as $line) {
            if ($line->unitAmount->currency !== $currency) {
                throw CheckoutException::currencyMismatch($currency, $line->unitAmount->currency);
            }
        }

        $total = Money::zero($currency);
        foreach ($lines as $line) {
            $total = $total->plus($line->amount);
        }

        $session = new CheckoutSession(
            id: (string) Str::uuid(),
            status: CheckoutStatus::ReadyForPayment,
            currency: $currency,
            lineItems: $lines,
            total: $total,
            provenance: $provenance,
            order: null,
        );

        $this->sessions->put($session);

        return $session;
    }

    public function retrieve(string $id): ?CheckoutSession
    {
        return $this->sessions->find($id);
    }

    /**
     * The order's fulfillment descriptor, sourced from the resolved offers (issues 05–07). Each line's
     * offer is re-resolved by its opaque ref; the first line that declares a `kind` OR a `grant` is the
     * fulfilling line (an Order carries one kind — mixed-kind carts are out of scope). Returns:
     *   - `kind`: the offer's host-facing kind matched to the engine's {@see PurchaseKind}, tolerating
     *     either separator (`credit-topup` vs `credit_topup`); null → `Order::for()`'s cadence default.
     *   - `grant`: the offer's opaque grant bag PLUS the purchased line context (`offer` ref + `qty`), so
     *     a `PurchaseCompleted` listener — which fires BEFORE any order is recorded — can build its
     *     fulfillment (e.g. Circuit Run parameters {buyer, qty, offer}) from the event alone.
     *
     * @return array{kind: ?PurchaseKind, grant: array<string, mixed>}
     */
    private function fulfillment(CheckoutSession $session): array
    {
        foreach ($session->lineItems as $line) {
            $offer = $this->offers->resolve($line->itemRef);

            if ($offer === null) {
                continue;
            }

            $kind = ($offer->kind === null || $offer->kind === '')
                ? null
                : (PurchaseKind::tryFrom($offer->kind) ?? PurchaseKind::tryFrom(str_replace('-', '_', $offer->kind)));

            if ($kind !== null || $offer->grant !== []) {
                return [
                    'kind' => $kind,
                    'grant' => $offer->grant + ['offer' => $line->itemRef, 'qty' => $line->quantity],
                ];
            }
        }

        return ['kind' => null, 'grant' => []];
    }

    /**
     * Complete a checkout session: charge the delegate token through the money-in
     * driver and, on a captured payment, record the minimal order + provenance and
     * flip the session to `completed`. A declined/step-up charge leaves the session
     * payable and surfaces the failure to the caller (no order is recorded).
     */
    public function complete(string $id, DelegatePayment $payment, ?AgentProvenance $provenance = null): CheckoutSession
    {
        $session = $this->sessions->find($id);
        if ($session === null) {
            throw CheckoutException::unknownSession($id);
        }

        // Idempotent: a session that already completed returns its recorded order.
        if ($session->status === CheckoutStatus::Completed) {
            return $session;
        }

        if ($session->status !== CheckoutStatus::ReadyForPayment) {
            throw CheckoutException::notPayable($id, $session->status);
        }

        // The completing request's provenance enriches — never erases — what the
        // session captured at create time, so the recorded order names the agent
        // that opened it even if `complete` resent only a subset of headers.
        $provenance = ($session->provenance ?? new AgentProvenance)->merge($provenance);

        // The fulfillment descriptor (issues 05–07): the offer's kind (→ Order) and its grant bag +
        // purchased context (→ the Purchase, for listeners). Resolved once here.
        $fulfillment = $this->fulfillment($session);

        $order = Order::for(
            customer: new Customer(id: $provenance->customerKey()),
            lineItems: array_map(
                fn (CheckoutLineItem $line): LineItem => LineItem::for($line->name, $line->unitAmount, $line->quantity),
                $session->lineItems,
            ),
            currency: $session->currency,
            // The fulfillment kind (issue 06): so a completed purchase declares what it grants (e.g.
            // CreditTopup), not the Perpetual default.
            kind: $fulfillment['kind'],
            reference: $session->id,
            // The delegate token rides through the driver seam as the opaque
            // payment-method ref; the satellite charges it as merchant-of-record. It is a
            // delegated/shared token (no customer of ours), and off-session (no buyer
            // present for a step-up) — the driver charges it directly on the merchant account.
            paymentMethodRef: $payment->token,
            offSession: true,
            billingAddress: $payment->billingAddress,
            delegated: true,
        );

        // Resolve who the grant lands on (issue 06). Off-session/agent-driven — the host maps the
        // provenance to an opaque beneficiary id; the engine stays account-blind. Null → the sale is
        // recorded but grant listeners early-return (money-in without value-out).
        $beneficiaryId = $this->beneficiaries->resolve($provenance);

        // Carry the fulfillment grant onto the Purchase (issue 07) so a listener can fulfill through a
        // second tier (e.g. a Circuit Run) — it fires before any order is recorded, so the offer ref +
        // qty must ride here, not in a stored order.
        $purchase = $this->moneyIn->place($order, beneficiaryId: $beneficiaryId, grant: $fulfillment['grant']);
        $captured = $purchase->payment;

        if (! $captured->succeeded()) {
            throw CheckoutException::chargeFailed($id, $captured->status, $captured->errorCode);
        }

        $orderRef = $this->orders->record($session, $captured, $purchase->receipt, $provenance);

        $completed = new CheckoutSession(
            id: $session->id,
            status: CheckoutStatus::Completed,
            currency: $session->currency,
            lineItems: $session->lineItems,
            total: $session->total,
            provenance: $provenance,
            order: $orderRef,
        );

        $this->sessions->put($completed);

        return $completed;
    }
}
