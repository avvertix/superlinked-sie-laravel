<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Incremental delta emitted on each streaming chat chunk. */
final class ChatDelta
{
    /**
     * @param  ?list<array<string, mixed>>  $toolCalls
     */
    public function __construct(
        public readonly ?string $role = null,
        public readonly ?string $content = null,
        public readonly ?array $toolCalls = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'] ?? null,
            content: $data['content'] ?? null,
            toolCalls: $data['tool_calls'] ?? null,
        );
    }
}
