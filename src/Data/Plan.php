<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Billing\BillComposer;
use Rushing\Commerce\Billing\BillingPeriod;
use Rushing\Commerce\Billing\Contracts\BillingComponent;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * A named Offer composed of ordered priced components (the extracted
 * {@see BillingComponent}s), forming a rung in
 * a tier ladder. A subscription binds a Customer to a Plan and may override a
 * component's config (a rate) without cloning the Plan.
 *
 * `features` are feature-gate flags and `unitOfSale` the scoping key
 * (seat/merchant/location/entity) — both catalog concerns; *which capability* each
 * flag unlocks is host-mapped, never resolved here.
 */
class Plan extends Data implements SchemaIdentity
{
    /**
     * @param  array<int, array{type: string, config?: array<string, mixed>}>  $components
     * @param  array<int, string>  $features
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $components = [],
        public array $features = [],
        public ?string $unitOfSale = null,
    ) {}

    public function hasFeature(string $flag): bool
    {
        return in_array($flag, $this->features, true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides
     */
    public function compose(BillComposer $composer, BillingPeriod $period, array $overrides = []): ComposedBill
    {
        return $composer->compose($this->components, $period, $overrides);
    }

    public static function schemaName(): string
    {
        return 'commerce/plan';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
