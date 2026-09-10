<?php

declare(strict_types=1);

namespace Sie\Client\Data;

use JsonSerializable;

/** Information about a model returned by `listModels()`/`getModel()`. */
final class ModelInfo implements JsonSerializable
{
    /**
     * @param  ?list<string>  $inputs
     * @param  ?list<string>  $outputs
     */
    public function __construct(
        public readonly string $name,
        public readonly ?bool $loaded = null,
        public readonly ?array $inputs = null,
        public readonly ?array $outputs = null,
        public readonly ?ModelDims $dims = null,
        public readonly ?int $maxSequenceLength = null,
        public readonly ?ModelCapabilities $capabilities = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $dims = $data['dims'] ?? null;
        $capabilities = $data['capabilities'] ?? null;

        return new self(
            name: (string) ($data['name'] ?? ''),
            loaded: $data['loaded'] ?? null,
            inputs: $data['inputs'] ?? null,
            outputs: $data['outputs'] ?? null,
            dims: is_array($dims) ? ModelDims::fromArray($dims) : null,
            maxSequenceLength: $data['max_sequence_length'] ?? null,
            capabilities: is_array($capabilities) ? ModelCapabilities::fromArray($capabilities) : null,
        );
    }

    /**
     * The wire shape this was built from, so `fromArray(toArray())` round-trips.
     * The keys stay in the gateway's snake_case for that reason.
     *
     * Useful when a catalog has to survive somewhere that holds arrays but not
     * objects — a cache, a queue payload, a JSON response. Laravel 13 gates
     * cache reads on `cache.serializable_classes`, so an application caching
     * `SIE::models()` should store `toArray()` and hydrate with `fromArray()`
     * rather than the objects themselves.
     *
     * @return array{name: string, loaded: ?bool, inputs: ?list<string>, outputs: ?list<string>, dims: ?array{dense: ?int, sparse: ?int, multivector: ?int}, max_sequence_length: ?int, capabilities: ?array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'loaded' => $this->loaded,
            'inputs' => $this->inputs,
            'outputs' => $this->outputs,
            'dims' => $this->dims?->toArray(),
            'max_sequence_length' => $this->maxSequenceLength,
            'capabilities' => $this->capabilities?->toArray(),
        ];
    }

    /**
     * @return array{name: string, loaded: ?bool, inputs: ?list<string>, outputs: ?list<string>, dims: ?array{dense: ?int, sparse: ?int, multivector: ?int}, max_sequence_length: ?int, capabilities: ?array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
