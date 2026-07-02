<?php

declare(strict_types=1);

namespace Rushing\Commerce;

use Illuminate\Support\Str;
use Rushing\Commerce\Data\ComposedBill;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Settlement;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\InvoiceStatus;

/**
 * Settles the platform's cost-metering Bill into real money-in without retiring it:
 * a finalized Bill projects into a neutral Invoice, which is then collected as a
 * one-off Payment on the billing party's own account. The Bill stays the source of
 * truth for the math; this only projects and collects.
 */
final class Settlements
{
    public function __construct(private MoneyIn $moneyIn) {}

    public function invoice(
        ComposedBill $bill,
        Customer $customer,
        ?string $billReference = null,
        string $currency = 'USD',
    ): Invoice {
        return Invoice::fromComposedBill($bill, $customer, $currency, $billReference);
    }

    public function settle(Invoice $invoice, ?string $driver = null): Settlement
    {
        $order = Order::for(
            customer: $invoice->customer,
            lineItems: [LineItem::for('Invoice '.$invoice->id, $invoice->total)],
            cadence: Cadence::OneTime,
            currency: $invoice->total->currency,
            reference: $invoice->billReference,
        );

        $purchase = $this->moneyIn->place($order, $driver);

        return new Settlement(
            id: (string) Str::uuid(),
            invoice: $invoice->withStatus(InvoiceStatus::Paid),
            purchase: $purchase,
            billReference: $invoice->billReference,
        );
    }
}
