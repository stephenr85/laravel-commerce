<?php

namespace Rushing\Commerce\Acp\Contracts;

use Rushing\Commerce\Acp\Data\AgentProvenance;
use Rushing\Commerce\Acp\Data\CheckoutSession;
use Rushing\Commerce\Acp\Data\OrderRef;
use Rushing\Commerce\Data\Payment;

/**
 * The `Order` half of the ACP substrate seam: record the **minimal order** a
 * completed checkout produced, plus the agent-provenance trace that drove it, and
 * return the {@see OrderRef} the session carries back. This is deliberately thin —
 * the comprehensive order ledger is the deferred commerce substrate (see the PRD's
 * _Lunar boundary_); a richer substrate plugs in by rebinding this port. Orders
 * reference catalog items only by the opaque refs carried on the session.
 */
interface OrderStore
{
    public function record(CheckoutSession $session, Payment $payment, AgentProvenance $provenance): OrderRef;
}
