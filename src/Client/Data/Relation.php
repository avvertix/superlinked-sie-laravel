<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** A single extracted relation triple. */
final class Relation
{
    public function __construct(
        public readonly string $head,
        public readonly string $tail,
        public readonly string $relation,
        public readonly float $score,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            head: (string) $data['head'],
            tail: (string) $data['tail'],
            relation: (string) $data['relation'],
            score: (float) $data['score'],
        );
    }
}
