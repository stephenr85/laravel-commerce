<?php

namespace Rushing\Commerce\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A neutral inbound dispute (chargeback) fact — `StripeWebhookTranslator::dispute()` translates a
 * raw `charge.dispute.created` payload into this shape. `chargeRef` is the opaque provider charge
 * reference a driver resolves further Split-reversal action against (see
 * `StripeDriver::reverseTransferForDispute()`); the engine itself does not interpret it. Deliberately
 * NO import of any `Stripe`-namespaced class here — this DTO stays provider-neutral, referencing the
 * translator only in prose (ADR-0003's "no payment provider on the neutral surface" discipline, held
 * to even outside `src/Contracts/`, which is the only directory that guard mechanically enforces).
 */
class Dispute extends Data implements SchemaIdentity
{
    public function __construct(
        public string $chargeRef,
        public Money $amount,
        public ?string $paymentIntentRef = null,
        public ?string $reason = null,
        // The rail that collected the disputed charge (its provenance), set by the provider's translator. A reversal
        // settles a PAST charge on that rail, whatever rail the host collects new money on today (BUY-02, build.qa).
        public ?string $rail = null,
    ) {}

    public static function schemaName(): string
    {
        return 'commerce/dispute';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
