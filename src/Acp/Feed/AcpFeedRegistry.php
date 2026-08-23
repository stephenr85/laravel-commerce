<?php

namespace Rushing\Commerce\Acp\Feed;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Spatie\LaravelData\Data;

/**
 * Dispatch table for ACP product-feed projectors, keyed by the Data class each handles. Dispatch is
 * by class; an unknown class throws rather than guessing. The discovery half of the ACP protocol —
 * it sits with the checkout half (DTOs, ports) in this package, not in the schema.org leaf.
 */
#[IsRegistry(
    root: 'commerce.acp.feeds',
    of: 'ACP product-feed projectors keyed by the catalog Data class each handles — the discovery half of '
        .'the agentic-checkout protocol (the engine ships none)',
    arity: RegistryArity::PickOne,
    onDuplicate: OnDuplicate::Supersede,
    note: 'PickOne, and the reasoning is the interesting part: `feed(iterable $items)` LOOKS like a fold '
        .'and is not one. It fans over the INPUTS, dispatching each by $data::class to exactly ONE '
        .'projector via for(), which throws on a miss. Arity counts ENTRIES a resolution engages, and '
        .'that is one — so ComposeMany and RunAll are both wrong. The projector names its own key via '
        .'subject(), so there is not even a key to put in config.',
    order: 63,
)]
class AcpFeedRegistry
{
    /** @var array<class-string<Data>, AcpFeedProjector> */
    private array $byClass = [];

    public function register(AcpFeedProjector $projector): self
    {
        $this->byClass[$projector->subject()] = $projector;

        return $this;
    }

    /**
     * @param  class-string<Data>  $dataClass
     */
    public function for(string $dataClass): AcpFeedProjector
    {
        return $this->byClass[$dataClass]
            ?? throw new InvalidArgumentException("No ACP feed projector registered for [{$dataClass}].");
    }

    /**
     * @param  class-string<Data>  $dataClass
     */
    public function has(string $dataClass): bool
    {
        return isset($this->byClass[$dataClass]);
    }

    /**
     * Project one Data instance to its ACP feed item.
     *
     * @return array<string, mixed>
     */
    public function project(Data $data): array
    {
        return $this->for($data::class)->project($data);
    }

    /**
     * Project a set of Data instances into the `products` list of an ACP product feed.
     *
     * @param  iterable<int, Data>  $items
     * @return array<int, array<string, mixed>>
     */
    public function feed(iterable $items): array
    {
        $products = [];

        foreach ($items as $item) {
            $products[] = $this->project($item);
        }

        return $products;
    }
}
