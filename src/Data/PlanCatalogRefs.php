<?php

namespace Rushing\Commerce\Data;

use Rushing\Commerce\Contracts\SyncsPlanCatalog;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * What a catalog-keeping rail recorded for one plan on one merchant ({@see SyncsPlanCatalog}): the product, the
 * current price per interval, the amounts those prices carry (so a host can tell drift without calling the rail), and
 * every price it has since archived. Archived prices still bill the subscribers who bought them, so {@see claims()}
 * answers for them too: that is how a provider subscription on a superseded price maps back to its plan.
 *
 * {@see toBag()} / {@see fromBag()} are the snake_case storage shape a host keeps under its own plan settings.
 */
class PlanCatalogRefs extends Data implements SchemaIdentity
{
    /**
     * @param  array<int, string>  $archivedPriceIds
     */
    public function __construct(
        public ?string $productId = null,
        public ?string $monthlyPriceId = null,
        public ?string $annualPriceId = null,
        public ?int $monthlyAmount = null,
        public ?int $annualAmount = null,
        public ?string $currency = null,
        public array $archivedPriceIds = [],
        public ?string $syncedAt = null,
    ) {}

    /** Whether this plan owns the price, current or archived. */
    public function claims(string $priceId): bool
    {
        return $priceId !== ''
            && ($priceId === $this->monthlyPriceId || $priceId === $this->annualPriceId || in_array($priceId, $this->archivedPriceIds, true));
    }

    /**
     * @return array{product_id: ?string, prices: array{monthly: ?string, annual: ?string}, amounts: array{monthly: ?int, annual: ?int}, currency: ?string, archived_prices: array<int, string>, synced_at: ?string}
     */
    public function toBag(): array
    {
        return [
            'product_id' => $this->productId,
            'prices' => ['monthly' => $this->monthlyPriceId, 'annual' => $this->annualPriceId],
            'amounts' => ['monthly' => $this->monthlyAmount, 'annual' => $this->annualAmount],
            'currency' => $this->currency,
            'archived_prices' => array_values($this->archivedPriceIds),
            'synced_at' => $this->syncedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    public static function fromBag(array $bag): self
    {
        $prices = (array) ($bag['prices'] ?? []);
        $amounts = (array) ($bag['amounts'] ?? []);
        $int = fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $str = fn (mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        return new self(
            productId: $str($bag['product_id'] ?? null),
            monthlyPriceId: $str($prices['monthly'] ?? null),
            annualPriceId: $str($prices['annual'] ?? null),
            monthlyAmount: $int($amounts['monthly'] ?? null),
            annualAmount: $int($amounts['annual'] ?? null),
            currency: $str($bag['currency'] ?? null),
            archivedPriceIds: array_values(array_map('strval', (array) ($bag['archived_prices'] ?? []))),
            syncedAt: $str($bag['synced_at'] ?? null),
        );
    }

    public static function schemaName(): string
    {
        return 'commerce/plan-catalog-refs';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
