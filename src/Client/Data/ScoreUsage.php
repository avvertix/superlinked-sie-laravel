<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Authoritative usage reported by a score adapter. */
final class ScoreUsage
{
    public function __construct(
        public readonly int $inputTokens,
        public readonly ?int $images = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $images = $data['images'] ?? null;

        return new self(
            inputTokens: is_int($data['input_tokens'] ?? null) ? $data['input_tokens'] : 0,
            images: is_int($images) ? $images : null,
        );
    }
}
