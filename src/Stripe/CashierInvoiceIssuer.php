<?php

namespace Rushing\Commerce\Stripe;

use Laravel\Cashier\Cashier;
use LogicException;
use Rushing\Commerce\Contracts\InvoiceIssuer;
use Rushing\Commerce\Data\BillingLineItem;
use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\InvoiceRef;

/**
 * A payable Stripe invoice, inside the Stripe adapter (purchase-walkthrough BUY-02 part D, rule BUY-1). The customer, its
 * details pushed up, one invoice item per line, and an invoice sent for payment in 30 days (`send_invoice`). Paid later,
 * through the provider's invoice.paid webhook and the host's InvoiceReconciler; nothing is charged here.
 *
 * IDEMPOTENT per Bill (review-r1): the caller issues inside a transaction, so a failure after Stripe answered rolls the
 * Bill back to un-invoiced and a retry issues again. Every create therefore carries an Idempotency-Key derived from the
 * Bill (`broker-bill-{bill}-customer`, `-invoice`, `-item-{n}`, `-send`), so within Stripe's 24-hour key window a retry gets the SAME
 * items and invoice back instead of a second invoice; the invoice also carries `metadata.bill_id`. Cashier's `tab()` and
 * `invoice()` take no request options, so the adapter makes the calls on Cashier's Stripe client itself (the parity
 * recording pins them). Unlike Cashier's tab() then invoice(), the invoice is created first and the lines attached to it,
 * so only this Bill's lines are ever on it.
 */
class CashierInvoiceIssuer implements InvoiceIssuer
{
    private const DAYS_UNTIL_DUE = 30;

    public function __construct(private BillableResolver $billables) {}

    public function issue(Invoice $invoice): InvoiceRef
    {
        $account = $this->billables->forCustomer($invoice->customer);
        $key = 'broker-bill-'.($invoice->billReference ?? $invoice->id);

        // Keyed too: the account's stripe_id is saved on the caller's connection, inside its transaction, so a rollback
        // erases it and the retry creates the customer again. The key returns the SAME customer, so the replayed item
        // keys carry the same params (review-r1, build.qa). An existing customer is only read, as before.
        $account->createOrGetStripeCustomer([], ['idempotency_key' => "{$key}-customer"]);
        // Push the current owner email/name onto the (possibly pre-existing) Stripe customer.
        $account->syncStripeCustomerDetails();

        if (Cashier::$calculatesTaxes) {
            // As Cashier's tab() refuses: automatic tax needs price_data, which a broker line does not carry.
            throw new LogicException('When using automatic tax calculation, you must include "price_data" in the provided options array.');
        }

        $stripe = $account::stripe();

        // The invoice FIRST, taking no pending items (`exclude`), then each line attached to it by id (build.qa, lead).
        // `include` swept EVERY pending item on the customer into this invoice, and the lock is per Bill, not per
        // customer: another Bill of the same broker, or an item orphaned by an earlier failed run, could land on this
        // invoice. send_invoice: a payable invoice the customer is sent, never charged here.
        $stripeInvoice = $stripe->invoices->create([
            'automatic_tax' => array_filter(['enabled' => Cashier::$calculatesTaxes]),
            'customer' => $account->stripe_id,
            'currency' => $account->asStripeCustomer()->currency ?? config('cashier.currency'),
            'pending_invoice_items_behavior' => 'exclude',
            'collection_method' => 'send_invoice',
            'days_until_due' => self::DAYS_UNTIL_DUE,
            // Never let Stripe finalize (and email) this draft before its lines are attached; the send below finalizes
            // it. A run that dies part-way leaves an un-sent draft the retry completes (review-r1).
            'auto_advance' => false,
            'metadata' => ['bill_id' => (string) ($invoice->billReference ?? $invoice->id)],
        ], ['idempotency_key' => "{$key}-invoice"]);

        foreach (array_values($invoice->lineItems) as $n => $item) {
            /** @var BillingLineItem $item */
            // The label is the caller's (BrokerInvoicer keeps its old `description ?? component_type ?? 'Usage'`).
            $stripe->invoiceItems->create([
                'customer' => $account->stripe_id,
                'currency' => $account->preferredCurrency(),
                'description' => $item->description,
                'amount' => (int) round($item->amountUsd * 100),
                'invoice' => $stripeInvoice->id,
            ], ['idempotency_key' => "{$key}-item-{$n}"]);
        }

        // Sending a draft finalizes it with the lines attached above.
        $stripeInvoice->sendInvoice(null, ['idempotency_key' => "{$key}-send"]);

        return new InvoiceRef(providerRef: (string) $stripeInvoice->id, paid: false, billReference: $invoice->billReference);
    }
}
