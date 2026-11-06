<?php

namespace Rushing\Commerce\Stripe;

use Rushing\Commerce\Contracts\SyncsPlanCatalog;
use Rushing\Commerce\Data\CatalogPlan;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\PlanCatalogRefs;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Stripe's half of {@see SyncsPlanCatalog} (owner-review-20261102 O2), on one merchant's own account.
 *
 * - **One Product per plan**, found by the recorded id, else by `metadata.plan_id` (Stripe Search), else created. Its
 *   name and description are updated in place.
 * - **Prices are immutable**, so a fee change creates a new Price with the plan's `lookup_key`
 *   (`plan:{slug}:{monthly|annual}`) and `transfer_lookup_key`, then archives the old Price (`active: false`). A
 *   slug change alone moves the lookup key onto the same Price.
 * - **Subscribers are never moved.** Nothing here reads or writes a subscription; a subscriber on an archived Price
 *   keeps paying it, and the archived id stays in the refs so the plan still claims it.
 * - **Idempotent.** An unchanged plan writes nothing. A run that lost its write-back finds the Product by metadata
 *   and each Price by its lookup key; every create carries an idempotency key derived from its parameters, so a retry
 *   inside Stripe's idempotency window cannot mint a duplicate even while Search lags.
 *
 * The annual Price is a yearly interval at {@see CatalogPlan::$annual} (twelve annual-monthly payments).
 */
final class StripePlanCatalog
{
    private const INTERVALS = ['monthly' => 'month', 'annual' => 'year'];

    public function __construct(
        private StripeClient $stripe,
        private string $merchantId,
    ) {}

    public function sync(CatalogPlan $plan, ?PlanCatalogRefs $recorded = null): PlanCatalogRefs
    {
        $recorded ??= new PlanCatalogRefs;
        $archived = $recorded->archivedPriceIds;

        $product = $this->findProduct($plan, $recorded->productId);

        if ($product === null && ! $plan->hasFee()) {
            // Never had a fee and never had a Product: nothing to mirror. Recorded prices (if any) are archived below
            // only when a Product exists; without one Stripe holds nothing of this plan.
            return new PlanCatalogRefs(archivedPriceIds: $archived, syncedAt: $this->now());
        }

        $product = $product === null ? $this->createProduct($plan) : $this->reconcileProduct($product, $plan);

        $ids = [];
        $amounts = [];
        foreach (self::INTERVALS as $key => $interval) {
            $desired = $key === 'monthly' ? $plan->monthly : $plan->annual;
            $recordedId = $key === 'monthly' ? $recorded->monthlyPriceId : $recorded->annualPriceId;

            $ids[$key] = $this->reconcilePrice($plan, $product->id, $key, $interval, $desired, $recordedId, $archived);
            $amounts[$key] = $ids[$key] === null ? null : $desired?->minorUnits;
        }

        return new PlanCatalogRefs(
            productId: $product->id,
            monthlyPriceId: $ids['monthly'],
            annualPriceId: $ids['annual'],
            monthlyAmount: $amounts['monthly'],
            annualAmount: $amounts['annual'],
            currency: $plan->hasFee() ? strtolower(($plan->monthly ?? $plan->annual)->currency) : $recorded->currency,
            archivedPriceIds: array_values(array_unique($archived)),
            syncedAt: $this->now(),
        );
    }

    public function retire(PlanCatalogRefs $recorded): PlanCatalogRefs
    {
        $archived = $recorded->archivedPriceIds;

        foreach ([$recorded->monthlyPriceId, $recorded->annualPriceId] as $priceId) {
            if ($priceId !== null) {
                $this->archivePrice($priceId);
                $archived[] = $priceId;
            }
        }

        if ($recorded->productId !== null && ($product = $this->retrieveProduct($recorded->productId)) !== null && $product->active) {
            $this->stripe->products->update($product->id, ['active' => false]);
        }

        return new PlanCatalogRefs(
            productId: $recorded->productId,
            currency: $recorded->currency,
            archivedPriceIds: array_values(array_unique($archived)),
            syncedAt: $this->now(),
        );
    }

    private function findProduct(CatalogPlan $plan, ?string $recordedId): ?Product
    {
        if ($recordedId !== null && ($product = $this->retrieveProduct($recordedId)) !== null) {
            return $product;
        }

        $found = $this->stripe->products->search([
            'query' => sprintf("metadata['plan_id']:'%s'", str_replace("'", "\\'", $plan->planId)),
            'limit' => 1,
        ]);

        return $found->data[0] ?? null;
    }

    private function createProduct(CatalogPlan $plan): Product
    {
        $params = array_filter([
            'name' => $plan->name,
            'description' => $plan->description === '' ? null : $plan->description,
            'metadata' => $this->metadata($plan),
        ], fn (mixed $v): bool => $v !== null);

        return $this->stripe->products->create($params, $this->idempotency($plan, 'product', $params));
    }

    private function reconcileProduct(Product $product, CatalogPlan $plan): Product
    {
        $description = $plan->description === '' ? null : $plan->description;
        $changes = [];

        if ($product->name !== $plan->name) {
            $changes['name'] = $plan->name;
        }
        if (($product->description ?? null) !== $description) {
            // An empty string is how Stripe unsets a Product description.
            $changes['description'] = $description ?? '';
        }
        if ((string) ($product->metadata['plan_slug'] ?? '') !== $plan->slug || (string) ($product->metadata['plan_id'] ?? '') !== $plan->planId) {
            $changes['metadata'] = $this->metadata($plan);
        }
        if (! $product->active && $plan->hasFee()) {
            $changes['active'] = true;
        }

        return $changes === [] ? $product : $this->stripe->products->update($product->id, $changes);
    }

    /**
     * @param  array<int, string>  $archived  appended to when a Price is superseded or withdrawn
     */
    private function reconcilePrice(CatalogPlan $plan, string $productId, string $key, string $interval, ?Money $desired, ?string $recordedId, array &$archived): ?string
    {
        $lookupKey = "plan:{$plan->slug}:{$key}";

        $current = $recordedId !== null ? $this->retrievePrice($recordedId) : null;

        if ($current !== null && ! $current->active) {
            // Archived elsewhere (the dashboard, a retire, or a run that replaced it and lost its write-back): it still
            // bills whoever holds it, so the plan keeps claiming it.
            $archived[] = $current->id;
            $current = null;
        }

        // Nothing current on record: an active Price under this plan's lookup key is the one a previous run made (its
        // write-back lost), so adopt it rather than mint another.
        $current ??= $this->priceByLookupKey($lookupKey, $productId);

        if ($desired === null) {
            if ($current !== null) {
                $this->archivePrice($current->id);
                $archived[] = $current->id;
            }

            return null;
        }

        if ($current !== null && $this->matches($current, $productId, $interval, $desired)) {
            if ($current->lookup_key !== $lookupKey) {
                $this->stripe->prices->update($current->id, ['lookup_key' => $lookupKey, 'transfer_lookup_key' => true]);
            }

            return $current->id;
        }

        $params = [
            'product' => $productId,
            'currency' => strtolower($desired->currency),
            'unit_amount' => $desired->minorUnits,
            'recurring' => ['interval' => $interval],
            'lookup_key' => $lookupKey,
            'transfer_lookup_key' => true,
            'nickname' => "{$plan->name} ({$key})",
            'metadata' => [...$this->metadata($plan), 'interval' => $key],
        ];
        // The key names what this create replaces AND the archive history so far: a retry of the same step replays it,
        // while offering the same amount again after it was withdrawn (archived, so the replay would hand back a dead
        // Price) is a new step with a new key.
        $replacing = $current?->id ?? 'none';
        $history = substr(hash('sha256', implode(',', array_unique($archived))), 0, 12);
        $created = $this->stripe->prices->create($params, $this->idempotency($plan, "price:{$key}:{$replacing}:{$history}", $params));

        if ($current !== null) {
            $this->archivePrice($current->id);
            $archived[] = $current->id;
        }

        return $created->id;
    }

    private function matches(Price $price, string $productId, string $interval, Money $desired): bool
    {
        return $price->product === $productId
            && (int) $price->unit_amount === $desired->minorUnits
            && strtolower((string) $price->currency) === strtolower($desired->currency)
            && ($price->recurring->interval ?? null) === $interval;
    }

    private function priceByLookupKey(string $lookupKey, string $productId): ?Price
    {
        $found = $this->stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1]);
        $price = $found->data[0] ?? null;

        return $price !== null && $price->product === $productId ? $price : null;
    }

    private function archivePrice(string $priceId): void
    {
        $price = $this->retrievePrice($priceId);

        if ($price !== null && $price->active) {
            $this->stripe->prices->update($priceId, ['active' => false]);
        }
    }

    private function retrieveProduct(string $id): ?Product
    {
        try {
            return $this->stripe->products->retrieve($id);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }
            throw $e;
        }
    }

    private function retrievePrice(string $id): ?Price
    {
        try {
            return $this->stripe->prices->retrieve($id);
        } catch (InvalidRequestException $e) {
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<string, string> */
    private function metadata(CatalogPlan $plan): array
    {
        return ['plan_id' => $plan->planId, 'plan_slug' => $plan->slug];
    }

    /**
     * Stripe refuses an idempotency key replayed with different parameters, so the key carries a digest of them: a
     * retry of the same create replays it, while a later, different create gets its own key.
     *
     * @param  array<string, mixed>  $params
     * @return array{idempotency_key: string}
     */
    private function idempotency(CatalogPlan $plan, string $what, array $params): array
    {
        return ['idempotency_key' => sprintf(
            'plan-catalog:%s:%s:%s:%s',
            $this->merchantId,
            $plan->planId,
            $what,
            substr(hash('sha256', (string) json_encode($params)), 0, 16),
        )];
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
