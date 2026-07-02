<?php

declare(strict_types=1);

namespace Rushing\Commerce\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
