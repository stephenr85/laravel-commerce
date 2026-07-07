<?php

namespace Rushing\Commerce\Exceptions;

use RuntimeException;

class RedemptionException extends RuntimeException
{
    public static function unknownCode(string $code): self
    {
        return new self("No redemption exists for code [{$code}].");
    }

    public static function notClaimable(string $code): self
    {
        return new self("Redemption [{$code}] is not in a claimable state.");
    }

    public static function expired(string $code): self
    {
        return new self("Redemption [{$code}] has expired.");
    }

    public static function payerIsBeneficiary(): self
    {
        return new self('A Gift requires the payer and the Beneficiary to differ.');
    }
}
