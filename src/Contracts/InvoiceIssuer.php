<?php

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\InvoiceRef;

/**
 * Issue a PAYABLE invoice through the rail (purchase-walkthrough BUY-02 part D): the customer is sent an invoice to pay
 * later, never charged on the spot (that is Settlements::settle). The returned ref says whether the rail settled it on
 * issue (the fake rail) or will report payment later (a provider's invoice.paid webhook).
 */
interface InvoiceIssuer
{
    public function issue(Invoice $invoice): InvoiceRef;
}
