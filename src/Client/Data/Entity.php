<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * A single extracted entity (NER span or document region).
 *
 * `$start`/`$end` are character offsets into the original text (null for
 * image-based extraction); `$bbox` is `[x, y, width, height]` in pixels for
 * image regions.
 */
final class Entity
{
    /**
     * @param  ?list<float>  $bbox
     */
    public function __construct(
        public readonly string $text,
        public readonly string $label,
        public readonly float $score,
        public readonly ?int $start = null,
        public readonly ?int $end = null,
        public readonly ?array $bbox = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: (string) $data['text'],
            label: (string) $data['label'],
            score: (float) $data['score'],
            start: isset($data['start']) ? (int) $data['start'] : null,
            end: isset($data['end']) ? (int) $data['end'] : null,
            bbox: $data['bbox'] ?? null,
        );
    }
}
