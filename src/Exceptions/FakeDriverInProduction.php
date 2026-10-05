<?php

namespace Rushing\Commerce\Exceptions;

use RuntimeException;

/**
 * The `fake` money-in driver was resolved while the app runs in production. A fake payment is always "Succeeded", so
 * collecting through it in production would grant whatever was bought for free. `commerce.driver` defaults to `fake`,
 * which makes an undeclared rail the likely cause: a production host sets `COMMERCE_DRIVER` to a real rail.
 */
class FakeDriverInProduction extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Refusing the fake payment driver in production: it marks every payment Succeeded without collecting. '
            .'Set COMMERCE_DRIVER (commerce.driver) to a real rail such as `stripe`.'
        );
    }
}
