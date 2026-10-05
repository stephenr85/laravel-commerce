<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Enums\PaymentActionKind;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The hosted leg a {@see Payment} still needs before it settles (purchase-walkthrough C-11, BUY-02): a `redirect` URL
 * or an `embedded` client secret. Also what {@see \Rushing\Commerce\MoneyIn::manage()} returns for a billing portal.
 */
class PaymentAction extends Data implements SchemaIdentity
{
    public function __construct(
        public PaymentActionKind $kind,
        public ?string $url = null,
        public ?string $clientSecret = null,
    ) {}

    public static function redirect(string $url): self
    {
        return new self(PaymentActionKind::Redirect, url: $url);
    }

    public static function embedded(string $clientSecret): self
    {
        return new self(PaymentActionKind::Embedded, clientSecret: $clientSecret);
    }

    public static function schemaName(): string
    {
        return 'commerce/payment-action';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
