<?php

namespace Rushing\Commerce\Enums;

/**
 * How a hosted payment leg is presented (purchase-walkthrough C-11, BUY-02): `redirect` sends the browser to a URL,
 * `embedded` hands the client a secret to mount in-page. One field on {@see \Rushing\Commerce\Data\Payment}, not a
 * sibling contract.
 */
enum PaymentActionKind: string
{
    case Redirect = 'redirect';
    case Embedded = 'embedded';
}
