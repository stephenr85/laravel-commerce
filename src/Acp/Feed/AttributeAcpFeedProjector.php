<?php

namespace Rushing\Commerce\Acp\Feed;

use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelData\Data;

/**
 * The reusable primitive: projects any Data class annotated with {@see AcpFeedProperty} into an ACP
 * product-feed item by reflection — zero bespoke code per DTO. Null-valued fields are omitted; a
 * nested Data value is flattened via its own array form.
 */
class AttributeAcpFeedProjector implements AcpFeedProjector
{
    /**
     * @param  class-string<Data>  $dataClass
     */
    public function __construct(private string $dataClass) {}

    public function subject(): string
    {
        return $this->dataClass;
    }

    public function project(Data $data): array
    {
        $reflection = new ReflectionClass($data);
        $item = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $mapping = $property->getAttributes(AcpFeedProperty::class);

            if ($mapping === []) {
                continue;
            }

            $value = $property->getValue($data);

            if ($value === null) {
                continue;
            }

            /** @var AcpFeedProperty $attribute */
            $attribute = $mapping[0]->newInstance();
            $field = $attribute->name ?? $property->getName();

            $item[$field] = $value instanceof Data ? $value->toArray() : $value;
        }

        return $item;
    }
}
