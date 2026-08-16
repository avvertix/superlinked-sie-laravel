<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Information about a model returned by `listModels()`/`getModel()`. */
final class ModelInfo
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
}
