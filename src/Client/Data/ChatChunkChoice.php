<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** A single choice in a streaming {@see ChatCompletionChunk}. */
final class ChatChunkChoice
{
    public function __construct(
        public readonly int $index,
        public readonly ChatDelta $delta,
        public readonly ?string $finishReason = null,
        public readonly mixed $logprobs = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (int) ($data['index'] ?? 0),
            delta: ChatDelta::fromArray($data['delta'] ?? []),
            finishReason: $data['finish_reason'] ?? null,
            logprobs: $data['logprobs'] ?? null,
        );
    }
}
