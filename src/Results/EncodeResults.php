<?php

declare(strict_types=1);

namespace Sie\Results;

use Illuminate\Support\Collection;
use Sie\Client\Data\EncodeResult;
use Sie\Client\Data\SparseResult;

/**
 * The results of an encode, one per input, in the order they were sent.
 *
 * Always a collection even for a single input — use `sole()` or `first()` to
 * unwrap — so callers never branch on the return type.
 *
 * @extends Collection<int, EncodeResult>
 */
final class EncodeResults extends Collection
{
    /**
     * The dense vector of every result, in order.
     *
     * @return list<list<float>|null>
     */
    public function dense(): array
    {
        return array_map(static fn (EncodeResult $result): ?array => $result->dense, array_values($this->all()));
    }

    /**
     * The sparse representation of every result, in order.
     *
     * @return list<SparseResult|null>
     */
    public function sparse(): array
    {
        return array_map(static fn (EncodeResult $result): ?SparseResult => $result->sparse, array_values($this->all()));
    }

    /**
     * The multivector representation of every result, in order.
     *
     * @return list<list<list<float>>|null>
     */
    public function multivector(): array
    {
        return array_map(static fn (EncodeResult $result): ?array => $result->multivector, array_values($this->all()));
    }

    /**
     * The model id that actually served the batch, which can differ from the
     * one requested when an alias or profile was resolved.
     */
    public function model(): ?string
    {
        return $this->first()?->model;
    }
}
