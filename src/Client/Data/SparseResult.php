<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Sparse vector result with non-zero indices and values. */
final class SparseResult
{
    /**
     * @param  list<int>  $indices
     * @param  list<float>  $values
     */
    public function __construct(
        public readonly array $indices,
        public readonly array $values,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Wire shape: `{dims?, dtype, indices: int[], values: float[]}`.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            indices: $data['indices'] ?? [],
            values: $data['values'] ?? [],
        );
    }
}
