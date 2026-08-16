<?php

declare(strict_types=1);

namespace Sie\Results;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Sie\Client\Data\ScoreEntry;
use Sie\Client\Data\ScoreResult;
use Sie\Client\Data\ScoreUsage;

/**
 * The scored inputs of a rerank, already sorted by relevance (descending) by
 * the cluster. Preserves that order.
 *
 * A score response has two parts: the per-item `scores`, which are this
 * collection's items, and the per-request envelope — which model actually
 * served the request, what it cost, and the query id — which belongs to the
 * whole call and so lives on the collection itself.
 *
 * The envelope survives `filter()`, `sortBy()`, `take()` and friends because
 * {@see newInstance()} carries it onto every derived collection. Without that
 * override, `$results->filter(...)->usage` would silently be null, which is
 * exactly the sort of ambiguous nothing this package refuses to return.
 *
 * Note that {@see EncodeResults} needs no equivalent: the encode envelope's
 * model and timing are already copied onto every `EncodeResult` by the client,
 * so there is nothing left over to carry.
 *
 * @extends Collection<int, ScoreEntry>
 */
final class ScoreResults extends Collection
{
    /**
     * @param  Arrayable<int, ScoreEntry>|iterable<int, ScoreEntry>|null  $items
     * @param  ?string  $model  The model that actually served the request, which can differ from the one
     *                          asked for when an alias or profile was resolved.
     * @param  ?ScoreUsage  $usage  Authoritative usage for the call — the billable numbers.
     * @param  ?string  $queryId  Server-assigned id for the query, when the cluster assigns one.
     */
    public function __construct(
        $items = [],
        public readonly ?string $model = null,
        public readonly ?ScoreUsage $usage = null,
        public readonly ?string $queryId = null,
    ) {
        parent::__construct($items);
    }

    public static function fromResult(ScoreResult $result): self
    {
        return new self($result->scores, $result->model, $result->usage, $result->queryId);
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

    /**
     * Every collection `Collection` derives from this one is built here, so
     * this is the single place the envelope has to be carried forward.
     *
     * @param  Arrayable<int, ScoreEntry>|iterable<int, ScoreEntry>|null  $items
     */
    protected function newInstance($items = []): static
    {
        return new self($items, $this->model, $this->usage, $this->queryId);
    }
}
