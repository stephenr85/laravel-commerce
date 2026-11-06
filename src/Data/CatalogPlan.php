<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Contracts\SyncsPlanCatalog;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * The provider-neutral view of a plan that a catalog-keeping rail mirrors ({@see SyncsPlanCatalog}): who it is and
 * what it recurs at. `monthly` is the per-month fee; `annual` is the per-YEAR amount of an annual commitment (twelve
 * annual-monthly payments), the shape a catalog sells a yearly plan in. Null means "not offered at that interval".
 */
class CatalogPlan extends Data implements SchemaIdentity
{
    public function __construct(
        public string $planId,
        public string $slug,
        public string $name,
        public ?string $description = null,
        public ?Money $monthly = null,
        public ?Money $annual = null,
    ) {}

    /**
     * From a subscription fee in whole currency units: the monthly price and the per-month rate of an annual
     * commitment. A zero or missing rate offers nothing at that interval.
     */
    public static function fromFee(
        string $planId,
        string $slug,
        string $name,
        ?string $description,
        ?float $monthlyPriceUsd,
        ?float $annualMonthlyPriceUsd,
        string $currency = 'USD',
    ): self {
        $monthly = $monthlyPriceUsd !== null && $monthlyPriceUsd > 0 ? Money::fromUsd($monthlyPriceUsd, $currency) : null;
        $annual = $annualMonthlyPriceUsd !== null && $annualMonthlyPriceUsd > 0
            ? Money::fromUsd($annualMonthlyPriceUsd, $currency)->times(12)
            : null;

        return new self($planId, $slug, $name, $description, $monthly, $annual);
    }

    public function hasFee(): bool
    {
        return $this->monthly !== null || $this->annual !== null;
    }

    public static function schemaName(): string
    {
        return 'commerce/catalog-plan';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
