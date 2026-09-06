<?php

namespace Rushing\Commerce\Acp\Feed;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Spatie\LaravelData\Data;

/**
 * Dispatch table for ACP product-feed projectors, keyed by the Data class each handles. Dispatch is
 * by class; an unknown class throws rather than guessing. The discovery half of the ACP protocol —
 * it sits with the checkout half (DTOs, ports) in this package, not in the schema.org leaf.
 *
 * ## The key is DERIVED from the subject class, not the class-string itself
 *
 * A FQCN is not a `Key`: backslashes are outside the segment grammar, and that is the same wall
 * [ticket 58](58-the-keyspaces-key-cannot-express.md) records for tower's absolute URIs and
 * beam-market's composer names. This row is not one of those, and the difference is the test amendment
 * 5 of the sweep states: the question is not "is there a key?" but "do the entries have a stable
 * identity a key can be derived from?" They do — `AcpFeedProjector::subject()` — and
 * `Key::fromClass()` is the kernel's explicit, opt-in derivation for precisely this. `OfferData`
 * becomes `commerce.acp.feeds.offer-data`.
 *
 * What that derivation costs is short-name collisions, and it is paid for rather than ignored:
 * registering two projectors whose subjects share a basename throws instead of silently superseding.
 *
 * @implements Registry<AcpFeedProjector>
 */
#[IsRegistry(
    root: 'commerce.acp.feeds',
    entryType: AcpFeedProjector::class,
    onDuplicate: OnDuplicate::Supersede,
    description: 'ACP product-feed projectors keyed by their subject Data class. feed() dispatches each input to one projector via for(), which throws on a miss. Subject classes provide the registration keys; basename collisions are rejected. The package ships no projectors.',
    order: 63,
)]
class AcpFeedRegistry implements Gated, Registry
{
    private BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /**
     * Widened contravariantly from `register(AcpFeedProjector $projector): self`. A projector is a
     * self-keying value object — it names its own subject — so the historical one-argument call shape
     * is unchanged at every registration site in the estate.
     */
    public function register(RegistryKey|string|AcpFeedProjector $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof AcpFeedProjector) {
            $entry = $key;
            $key = Key::fromClass($key->subject());

            $occupant = $this->entries->tryResolve($key);

            if ($occupant instanceof AcpFeedProjector && $occupant->subject() !== $entry->subject()) {
                throw new InvalidArgumentException(sprintf(
                    'Two ACP feed projectors derive the key `%s`: [%s] is already registered and [%s] '
                        .'would supersede it. A `Key` cannot hold a fully-qualified class name, so the '
                        .'subject\'s BASENAME is the key — rename one of the Data classes, or register '
                        .'the later projector under an explicit key.',
                    (string) $key,
                    $occupant->subject(),
                    $entry->subject(),
                ));
            }
        }

        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * The contract's existence check. Widened from `has(string $dataClass)`, and it still answers that
     * question: a string naming a live class is read as a subject and run through the same derivation
     * `register()` used, so `has(OfferData::class)` and `has('offer-data')` are one method.
     */
    public function has(RegistryKey|string $key): bool
    {
        return $this->entries->has($this->address($key));
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->entries->resolve($this->address($key));
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($this->address($key));
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($this->address($key));
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }

    /**
     * The projector for a Data class.
     *
     * ⚠️ The exception change is DECLINED here, deliberately. Conforming would swap this
     * `InvalidArgumentException` for the kernel's `RegistryMiss`, which is a `RuntimeException` and so
     * NOT a subtype — the package's own `toThrow(InvalidArgumentException::class)` spec would go red,
     * and so would any host catch. Nothing is gained: the message this throws names the Data class the
     * caller passed, which is the actionable fact, and `RegistryMiss` would report the derived key
     * instead. Sweep pass 1 measured two other rows where declining paid zero consumer edits; this is
     * the third.
     *
     * @param  class-string<Data>  $dataClass
     */
    public function for(string $dataClass): AcpFeedProjector
    {
        // A class-string that names nothing never reaches the store: `Key::parse()` would raise
        // `InvalidRegistryKey` on the backslashes and lose the message this method exists to give.
        $projector = class_exists($dataClass) || interface_exists($dataClass)
            ? $this->entries->tryResolve(Key::fromClass($dataClass))
            : null;

        return $projector instanceof AcpFeedProjector
            ? $projector
            : throw new InvalidArgumentException("No ACP feed projector registered for [{$dataClass}].");
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

    /**
     * A subject class-string, or an already-spelled key, as a registry address.
     *
     * The two are told apart by `class_exists()` rather than by sniffing for a backslash: a Data class
     * in the global namespace has none, and the package's own test fixtures are exactly that shape.
     */
    private function address(RegistryKey|string $key): RegistryKey
    {
        if ($key instanceof RegistryKey) {
            return $key;
        }

        return class_exists($key) || interface_exists($key) ? Key::fromClass($key) : Key::parse($key);
    }
}
