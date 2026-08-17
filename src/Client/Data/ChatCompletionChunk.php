<?php

declare(strict_types=1);

namespace Sie\Client\Data;

use Sie\Client\Support\WireList;

/**
 * One SSE event from `SieClient::streamChatCompletions()`.
 *
 * The terminal usage-only chunk (emitted when `stream_options.include_usage`
 * is `true`) sets `choices: []` and populates `$usage`.
 */
final class ChatCompletionChunk
{
    /**
     * @param  list<ChatChunkChoice>  $choices
     */
    public function __construct(
        public readonly string $id,
        public readonly string $object,
        public readonly int $created,
        public readonly string $model,
        public readonly array $choices,
        public readonly ?string $systemFingerprint = null,
        public readonly ?GenerationUsage $usage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $usage = $data['usage'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            object: (string) ($data['object'] ?? ''),
            created: (int) ($data['created'] ?? 0),
            model: (string) ($data['model'] ?? ''),
            choices: array_map(ChatChunkChoice::fromArray(...), WireList::of($data['choices'] ?? null, 'choices')),
            systemFingerprint: $data['system_fingerprint'] ?? null,
            usage: is_array($usage) ? GenerationUsage::fromArray($usage) : null,
        );
    }
}
