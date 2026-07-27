<?php

namespace Rushing\Commerce\Enums;

use Rushing\Commerce\Data\Payment;

/**
 * The four-class outcome taxonomy of an auto-reload attempt (asset 05 §3). The class
 * decides the lifecycle: only an instrument Declined bumps the consecutive-failure
 * counter toward auto-disable; ScaRequired disables on the first occurrence; a
 * TransientError is retried in-attempt by the orchestrator and never counts as an
 * instrument failure; a Blocked outcome is a guardrail stop, audited but not a failure.
 */
enum AutoReloadOutcome: string
{
    case Succeeded = 'succeeded';
    case Declined = 'declined';
    case ScaRequired = 'sca_required';
    case TransientError = 'transient_error';
    case Blocked = 'blocked';

    /**
     * Classify a charge outcome from the neutral Payment. A succeeded Payment is a
     * reload; an authentication_required (surfaced as RequiresAction or that error
     * code) is the terminal SCA class; every other non-success is an instrument
     * decline. Transient-infra never reaches here — it throws and is classified by
     * the orchestrator's catch, not a returned Payment.
     */
    public static function fromPayment(Payment $payment): self
    {
        if ($payment->status === PaymentStatus::Succeeded) {
            return self::Succeeded;
        }

        if ($payment->status === PaymentStatus::RequiresAction || $payment->errorCode === 'authentication_required') {
            return self::ScaRequired;
        }

        return self::Declined;
    }
}
