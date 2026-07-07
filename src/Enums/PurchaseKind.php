<?php

namespace Rushing\Commerce\Enums;

/**
 * What a paid Order produced. Perpetual = a one-time unlock; Subscription = a
 * recurring term; CreditTopup = stored value added to a Wallet. Host access
 * vocabulary (Entitlement/Grant) is mapped from this downstream, not here.
 */
enum PurchaseKind: string
{
    case Perpetual = 'perpetual';
    case Subscription = 'subscription';
    case CreditTopup = 'credit_topup';
}
