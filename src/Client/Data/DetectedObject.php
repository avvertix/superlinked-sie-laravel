<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** A single detected object with a bounding box `[x, y, width, height]` in pixels. */
final class DetectedObject
{
    /**
     * @param  list<float>  $bbox
     */
    public function __construct(
        public readonly string $label,
        public readonly float $score,
        public readonly array $bbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) $data['label'],
            score: (float) $data['score'],
            bbox: $data['bbox'] ?? [],
        );
    }
}
