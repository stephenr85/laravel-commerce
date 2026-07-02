<?php

declare(strict_types=1);

namespace Rushing\Commerce\Enums;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
}
