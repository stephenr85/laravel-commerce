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

    /**
     * Whether a hosted leg (a provider checkout page) can settle for this kind: a provider webhook translator turns its
     * completion into a PurchaseCompleted. A credit top-up (StripeWebhookTranslator::creditTopup) is a paid session; a
     * subscription's is a card SETUP that charges nothing (StripeWebhookTranslator::subscriptionSetup, BUY-04 under BQ-2:
     * the host's own bills are the price of record and charge the saved card later). Every rail refuses a hosted leg for
     * the rest rather than charge and grant nothing (BUY-02, build.qa).
     */
    public function settlesHosted(): bool
    {
        return $this === self::CreditTopup || $this === self::Subscription;
    }
}
