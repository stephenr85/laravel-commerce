<?php

namespace Rushing\Commerce\Acp\Data;

use Rushing\Commerce\Data\BillingAddress;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/**
 * The ACP `payment_data.billing_address` object (api-surface-coherence ticket 64).
 *
 * The input twin of {@see BillingAddress}, and deliberately NOT that class. `BillingAddress` implements
 * `SchemaIdentity`, so referencing it from a declared request body drags the whole versioned-`$id`
 * machinery into the host's JSON-schema generation — measured: `scribe:generate` failed outright with
 * `MissingSchemaBaseUri` the first time this body named it, because an `$id` is write-once and the
 * generator refuses to invent an origin. The wire keys are also snake_case here where the model's are
 * camelCase (`postal_code` vs `postalCode`), so the two shapes genuinely differ.
 */
class CheckoutBillingAddressInput extends Data
{
    public function __construct(
        #[Description('Name on the payment method.')]
        public ?string $name = null,
        #[Description('Street address, first line.')]
        public ?string $line1 = null,
        #[Description('Street address, second line.')]
        public ?string $line2 = null,
        #[Description('City or locality.')]
        public ?string $city = null,
        #[Description('State, province or region.')]
        public ?string $state = null,
        #[Description('Postal or ZIP code.')]
        public ?string $postal_code = null,
        #[Description('Two-letter ISO 3166-1 country code.')]
        public ?string $country = null,
    ) {}

    /** The engine-facing address this object describes. */
    public function toBillingAddress(): BillingAddress
    {
        return new BillingAddress(
            name: $this->name,
            line1: $this->line1,
            line2: $this->line2,
            city: $this->city,
            state: $this->state,
            postalCode: $this->postal_code,
            country: $this->country,
        );
    }
}
