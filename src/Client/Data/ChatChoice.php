<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** A single choice in a non-streaming {@see ChatCompletion}. */
final class ChatChoice
{
    public function __construct(
        public readonly int $index,
        public readonly ChatMessage $message,
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
            message: ChatMessage::fromArray($data['message'] ?? []),
            finishReason: $data['finish_reason'] ?? null,
            logprobs: $data['logprobs'] ?? null,
        );
    }
}
