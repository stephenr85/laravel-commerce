<?php

namespace Rushing\Commerce\Acp\Feed;

use Spatie\LaravelData\Data;

/**
 * Projects one Data class into an Agentic Commerce Protocol product-feed item (a plain associative
 * array, ready to serialize into the feed's `products` list). Registry-by-class dispatch, mirroring
 * how a boundary projector is registered per DTO.
 */
interface AcpFeedProjector
{
    /**
     * The fully-qualified Data class this projector handles.
     *
     * @return class-string<Data>
     */
    public function subject(): string;

    /**
     * @return array<string, mixed>
     */
    public function project(Data $data): array;
}
