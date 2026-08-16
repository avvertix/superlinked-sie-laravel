<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * One SSE event from `SieClient::streamGenerate()` (SIE-native shape).
 *
 * `$usage`/`$ttftMs` land only on the terminal chunk (`$done === true`).
 * A mid-stream `{"error": {...}}` chunk is raised by the caller as a
 * `ServerException` rather than surfaced here — see `SseStream`.
 */
final class GenerateChunk
{
    public function __construct(
        public readonly ?string $requestId = null,
        public readonly ?int $seq = null,
        public readonly ?string $textDelta = null,
        public readonly bool $done = false,
        public readonly ?string $finishReason = null,
        public readonly ?GenerationUsage $usage = null,
        public readonly ?float $ttftMs = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $usage = $data['usage'] ?? null;
        $ttft = $data['ttft_ms'] ?? null;

        return new self(
            requestId: is_string($data['request_id'] ?? null) ? $data['request_id'] : null,
            seq: is_int($data['seq'] ?? null) ? $data['seq'] : null,
            textDelta: is_string($data['text_delta'] ?? null) ? $data['text_delta'] : null,
            done: (bool) ($data['done'] ?? false),
            finishReason: is_string($data['finish_reason'] ?? null) ? $data['finish_reason'] : null,
            usage: is_array($usage) ? GenerationUsage::fromArray($usage) : null,
            ttftMs: is_int($ttft) || is_float($ttft) ? (float) $ttft : null,
        );
    }
}
