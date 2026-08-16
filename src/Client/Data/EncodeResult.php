<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * Result of encoding a single item.
 *
 * Contains the item ID (if provided) and one or more output representations
 * depending on what was requested. Unlike the Python SDK (which decodes
 * msgpack-numpy `ndarray`s), this client is JSON-only, so `$dense` /
 * `$multivector` are plain nested arrays of floats and `$sparse` carries
 * parallel index/value arrays — see {@see SparseResult}.
 */
final class EncodeResult
{
    /**
     * @param  ?list<float>  $dense
     * @param  ?list<list<float>>  $multivector
     * @param  array<string, mixed>|null  $timing
     * @param  ?string  $model  Model identity from the response envelope — the id that actually served the
     *                          request, which can differ from the one requested (alias or profile resolution).
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?array $dense = null,
        public readonly ?SparseResult $sparse = null,
        public readonly ?array $multivector = null,
        public readonly ?array $timing = null,
        public readonly ?string $model = null,
        public readonly ?RequestMetadata $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $item  Wire shape (per `EncodeResult` in the gateway's OpenAPI spec):
     *                                      `{id?, dense?: {values: float[]}, sparse?: {indices, values}, multivector?: {values: float[][]}}`.
     * @param  ?string  $model  Envelope-level model id, injected into every result in the batch.
     */
    public static function fromArray(array $item, ?array $timing = null, ?string $model = null, ?RequestMetadata $request = null): self
    {
        return new self(
            id: $item['id'] ?? null,
            dense: $item['dense']['values'] ?? null,
            sparse: isset($item['sparse']) ? SparseResult::fromArray($item['sparse']) : null,
            multivector: $item['multivector']['values'] ?? null,
            timing: $timing,
            model: $model,
            request: $request,
        );
    }
}
