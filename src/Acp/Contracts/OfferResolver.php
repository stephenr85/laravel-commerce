<?php

namespace Rushing\Commerce\Acp\Contracts;

use Rushing\Commerce\Data\Offer;

/**
 * The `Offer` half of the ACP substrate seam: resolve an opaque catalog reference
 * to a priced {@see Offer}, or null when the ref is unknown/unpurchasable. The
 * tracer binds a fixture resolver; slice 02 binds a resolver reading the real
 * schema.org/ACP catalog feed. The protocol layer depends only on this port — it
 * never learns what a ref *is*.
 */
interface OfferResolver
{
    public function resolve(string $itemRef): ?Offer;
}
