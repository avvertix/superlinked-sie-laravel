<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * A single chat message.
 *
 * `$content` is a string OR an array of content parts (text and, for
 * vision-capable models, base64 `data:` image parts); left as a raw
 * array/string/null passthrough rather than modeled further, mirroring the
 * Python SDK's `str | list[ChatContentPart] | None`. `$toolCalls` stays a raw
 * array of the wire objects for the same reason.
 */
final class ChatMessage
{
    /**
     * @param  string|list<array<string, mixed>>|null  $content
     * @param  ?list<array<string, mixed>>  $toolCalls
     */
    public function __construct(
        public readonly string $role,
        public readonly string|array|null $content = null,
        public readonly ?string $name = null,
        public readonly ?string $toolCallId = null,
        public readonly ?array $toolCalls = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: (string) ($data['role'] ?? ''),
            content: $data['content'] ?? null,
            name: $data['name'] ?? null,
            toolCallId: $data['tool_call_id'] ?? null,
            toolCalls: $data['tool_calls'] ?? null,
        );
    }
}
