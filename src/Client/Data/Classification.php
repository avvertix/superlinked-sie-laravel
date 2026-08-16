<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** A single classification result. */
final class Classification
{
    public function __construct(
        public readonly string $label,
        public readonly float $score,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) $data['label'],
            score: (float) $data['score'],
        );
    }
}
