<?php

declare(strict_types=1);

namespace Sie\Client\Data;

use Sie\Client\Support\WireList;

/** Result of scoring items against a query, sorted by relevance (descending). */
final class ScoreResult
{
    /**
     * @param  list<ScoreEntry>  $scores
     */
    public function __construct(
        public readonly string $model,
        public readonly array $scores,
        public readonly ?string $queryId = null,
        public readonly ?ScoreUsage $usage = null,
        public readonly ?RequestMetadata $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?RequestMetadata $request = null): self
    {
        $usage = $data['usage'] ?? null;

        return new self(
            model: (string) $data['model'],
            scores: array_map(ScoreEntry::fromArray(...), WireList::of($data['scores'] ?? null, 'scores')),
            queryId: $data['query_id'] ?? null,
            usage: is_array($usage) ? ScoreUsage::fromArray($usage) : null,
            request: $request,
        );
    }
}
