<?php

namespace Rushing\Commerce\Support;

use Rushing\Commerce\Contracts\InvoiceIssuer;
use Rushing\Commerce\Data\Invoice;
use Rushing\Commerce\Data\InvoiceRef;
use Rushing\Commerce\Exceptions\MoneyInUnavailable;

/** The none rail sends no invoice (BUY-4, C-3). */
class NoneInvoiceIssuer implements InvoiceIssuer
{
    public function issue(Invoice $invoice): InvoiceRef
    {
        throw MoneyInUnavailable::make('send an invoice');
    }
}
