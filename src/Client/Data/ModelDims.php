<?php

declare(strict_types=1);

namespace Sie\Client\Data;

use JsonSerializable;

/** Model dimension information. */
final class ModelDims implements JsonSerializable
{
    public function __construct(
        public readonly ?int $dense = null,
        public readonly ?int $sparse = null,
        public readonly ?int $multivector = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dense: $data['dense'] ?? null,
            sparse: $data['sparse'] ?? null,
            multivector: $data['multivector'] ?? null,
        );
    }

    /**
     * The wire shape this was built from, so `fromArray(toArray())` round-trips.
     *
     * @return array{dense: ?int, sparse: ?int, multivector: ?int}
     */
    public function toArray(): array
    {
        return [
            'dense' => $this->dense,
            'sparse' => $this->sparse,
            'multivector' => $this->multivector,
        ];
    }

    /**
     * @return array{dense: ?int, sparse: ?int, multivector: ?int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
