<?php

declare(strict_types=1);

namespace Sie\Results;

use Illuminate\Support\Collection;
use Sie\Client\Data\ScoreEntry;
use Sie\Client\Data\ScoreResult;
use Sie\Client\Data\ScoreUsage;

/**
 * The scored inputs of a rerank, already sorted by relevance (descending) by
 * the cluster. Preserves that order.
 *
 * The envelope metadata — {@see model()}, {@see usage()}, {@see queryId()} —
 * belongs to the request, not to the items, so it lives on the instance
 * returned by `score()`. `Collection` derives new instances with `new static`,
 * which cannot carry it: `$results->filter(...)->model()` is null. Read the
 * metadata from the collection you were handed, before deriving from it.
 *
 * @extends Collection<int, ScoreEntry>
 */
final class ScoreResults extends Collection
{
    private ?string $model = null;

    private ?ScoreUsage $usage = null;

    private ?string $queryId = null;

    public static function fromResult(ScoreResult $result): self
    {
        $results = new self($result->scores);
        $results->model = $result->model;
        $results->usage = $result->usage;
        $results->queryId = $result->queryId;

        return $results;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function usage(): ?ScoreUsage
    {
        return $this->usage;
    }

    public function queryId(): ?string
    {
        return $this->queryId;
    }

    /**
     * The ids of the top `$limit` inputs, most relevant first.
     *
     * @return list<string>
     */
    public function top(int $limit): array
    {
        return array_map(
            static fn (ScoreEntry $entry): string => $entry->itemId,
            array_slice(array_values($this->all()), 0, $limit),
        );
    }
}
