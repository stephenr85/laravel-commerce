<?php

namespace Rushing\Commerce\Acp\Enums;

/**
 * The lifecycle of an ACP Agentic Checkout session, mirroring the Agentic
 * Commerce Protocol's session states. The tracer uses only the payment-ready and
 * terminal states; address/fulfillment gating (`not_ready_for_payment`) is modelled
 * for protocol fidelity but never entered until a substrate supplies it.
 */
enum CheckoutStatus: string
{
    case NotReadyForPayment = 'not_ready_for_payment';
    case ReadyForPayment = 'ready_for_payment';
    case Completed = 'completed';
    case Canceled = 'canceled';
}
