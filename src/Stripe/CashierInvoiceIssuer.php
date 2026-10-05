<?php

namespace Rushing\Commerce\Stripe;

use Rushing\Commerce\Contracts\InvoiceIssuer;
use Rushing\Commerce\Data\BillingLineItem;
use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\InvoiceRef;

/**
 * A payable Stripe invoice, inside the Stripe adapter (purchase-walkthrough BUY-02 part D, rule BUY-1). The Cashier calls
 * the flagship's BrokerInvoicer made, moved unchanged onto the host's billable: the customer, its details pushed up, one
 * invoice item per line, and an invoice sent for payment in 30 days (`send_invoice`). Paid later, through the provider's
 * invoice.paid webhook and the host's InvoiceReconciler; nothing is charged here.
 */
class CashierInvoiceIssuer implements InvoiceIssuer
{
    private const DAYS_UNTIL_DUE = 30;

    public function __construct(private BillableResolver $billables) {}

    public function issue(Invoice $invoice): InvoiceRef
    {
        $account = $this->billables->forCustomer($invoice->customer);

        $account->createOrGetStripeCustomer();
        // Push the current owner email/name onto the (possibly pre-existing) Stripe customer.
        $account->syncStripeCustomerDetails();

        foreach ($invoice->lineItems as $item) {
            /** @var BillingLineItem $item */
            // The label is the caller's (BrokerInvoicer keeps its old `description ?? component_type ?? 'Usage'`).
            $account->tab($item->description, (int) round($item->amountUsd * 100));
        }

        // send_invoice: Cashier's invoice() sends a payable invoice instead of charging.
        $stripeInvoice = $account->invoice([
            'collection_method' => 'send_invoice',
            'days_until_due' => self::DAYS_UNTIL_DUE,
        ]);

        return new InvoiceRef(providerRef: (string) $stripeInvoice->id, paid: false, billReference: $invoice->billReference);
    }
}
