<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * A single score entry from the reranker.
 *
 * @param  string  $itemId  ID of the item (from request or auto-generated).
 * @param  float  $score  Relevance score (higher = more relevant).
 * @param  int  $rank  Position in sorted order (0 = most relevant).
 */
final class ScoreEntry
{
    public function __construct(
        public readonly string $itemId,
        public readonly float $score,
        public readonly int $rank,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId: (string) $data['item_id'],
            score: (float) $data['score'],
            rank: (int) $data['rank'],
        );
    }
}
