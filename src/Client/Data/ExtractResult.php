<?php

declare(strict_types=1);

namespace Sie\Client\Data;

use Sie\Client\Support\WireList;

/** Result of extraction for a single item. */
final class ExtractResult
{
    /**
     * @param  list<Entity>  $entities
     * @param  list<Relation>  $relations
     * @param  list<Classification>  $classifications
     * @param  list<DetectedObject>  $objects
     * @param  array<string, mixed>|null  $data  Additional structured extraction data (if `output_schema` was provided).
     * @param  ?ExtractItemError  $error  Set when extraction did not complete for this item. Extract batches are
     *                                    mixed-success, so check this before treating empty results as "nothing found".
     */
    public function __construct(
        public readonly array $entities = [],
        public readonly array $relations = [],
        public readonly array $classifications = [],
        public readonly array $objects = [],
        public readonly ?string $id = null,
        public readonly ?array $data = null,
        public readonly ?ExtractItemError $error = null,
        public readonly ?RequestMetadata $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item, ?RequestMetadata $request = null): self
    {
        $error = $item['error'] ?? null;

        return new self(
            entities: array_map(Entity::fromArray(...), WireList::of($item['entities'] ?? null, 'entities')),
            relations: array_map(Relation::fromArray(...), WireList::of($item['relations'] ?? null, 'relations')),
            classifications: array_map(Classification::fromArray(...), WireList::of($item['classifications'] ?? null, 'classifications')),
            objects: array_map(DetectedObject::fromArray(...), WireList::of($item['objects'] ?? null, 'objects')),
            id: $item['id'] ?? null,
            data: $item['data'] ?? null,
            error: $error !== null ? ExtractItemError::fromWire($error) : null,
            request: $request,
        );
    }
}
