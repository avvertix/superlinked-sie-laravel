<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Model dimension information. */
final class ModelDims
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
}
