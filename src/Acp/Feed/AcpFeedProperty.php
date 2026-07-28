<?php

namespace Rushing\Commerce\Acp\Feed;

use Attribute;

/**
 * Maps a Data property onto a field of an Agentic Commerce Protocol product-feed item. Without a
 * name the DTO property name is used verbatim; pass a name to translate to the ACP field (e.g.
 * `imageLink` → `image_link`). A null value is omitted from the projected item.
 *
 * The ACP-feed twin of a schema.org projection: a catalog DTO can carry this *and* schema.org
 * attributes, and project to both boundaries from one source. This lives with the rest of the ACP
 * protocol (checkout, ports) — ACP is a specific commerce protocol, not the universal schema.org
 * vocabulary, so it belongs here and not in the schema.org projection leaf.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class AcpFeedProperty
{
    public function __construct(public ?string $name = null) {}
}
