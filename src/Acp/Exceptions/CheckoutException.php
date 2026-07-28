<?php

namespace Rushing\Commerce\Acp\Exceptions;

use RuntimeException;
use Rushing\Commerce\Acp\Enums\CheckoutStatus;
use Rushing\Commerce\Enums\PaymentStatus;

/**
 * A protocol-level checkout failure. Carries a stable machine `code` so the HTTP
 * boundary maps it to an ACP-shaped error without leaking driver internals.
 */
class CheckoutException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('A checkout session needs at least one line item.', 'empty_cart', 422);
    }

    public static function unresolvableOffer(string $ref): self
    {
        return new self("No purchasable offer resolves for reference [{$ref}].", 'unresolvable_offer', 422);
    }

    public static function unknownSession(string $id): self
    {
        return new self("No checkout session [{$id}].", 'unknown_session', 404);
    }

    public static function notPayable(string $id, CheckoutStatus $status): self
    {
        return new self("Checkout session [{$id}] is {$status->value}, not payable.", 'not_payable', 409);
    }

    public static function chargeFailed(string $id, PaymentStatus $status, ?string $errorCode): self
    {
        return new self(
            "The delegate charge for session [{$id}] did not settle ({$status->value}).",
            $errorCode ?? 'charge_failed',
            402,
        );
    }
}
