<?php

namespace Rushing\Commerce\Support;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\InvoiceIssuer;
use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\InvoiceRef;

/**
 * The fake rail's invoice: issued and paid at once, with nothing sent (purchase-walkthrough BUY-3: "bill marked paid"
 * under the fake rail). The caller reconciles it through the same InvoiceReconciler a provider webhook reaches.
 */
class FakeInvoiceIssuer implements InvoiceIssuer
{
    public function issue(Invoice $invoice): InvoiceRef
    {
        return new InvoiceRef(providerRef: 'fake_in_'.Str::lower(Str::random(16)), paid: true, billReference: $invoice->billReference);
    }
}
