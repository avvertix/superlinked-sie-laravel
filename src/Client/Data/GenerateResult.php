<?php

declare(strict_types=1);

namespace Sie\Client\Data;

use Sie\Client\Exceptions\RequestException;

/** Aggregated generation result returned by `SieClient::generate()`. */
final class GenerateResult
{
    public function __construct(
        public readonly string $model,
        public readonly string $text,
        public readonly ?string $finishReason = null,
        public readonly ?GenerationUsage $usage = null,
        public readonly ?string $attemptId = null,
        public readonly ?float $ttftMs = null,
        public readonly ?float $tpotMs = null,
        public readonly ?RequestMetadata $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RequestException if `model`/`text` are missing or not strings — a missing/null value here would
     *                          otherwise silently look like an empty completion.
     */
    public static function fromArray(array $data, ?RequestMetadata $request = null): self
    {
        $model = $data['model'] ?? null;

        if (! is_string($model)) {
            throw new RequestException(sprintf("Generate response missing string 'model' field: got %s", get_debug_type($model)));
        }

        $text = $data['text'] ?? null;

        if (! is_string($text)) {
            throw new RequestException(sprintf("Generate response missing string 'text' field: got %s", get_debug_type($text)));
        }

        $usage = $data['usage'] ?? null;
        $ttft = $data['ttft_ms'] ?? null;
        $tpot = $data['tpot_ms'] ?? null;

        return new self(
            model: $model,
            text: $text,
            finishReason: is_string($data['finish_reason'] ?? null) ? $data['finish_reason'] : null,
            usage: is_array($usage) ? GenerationUsage::fromArray($usage) : null,
            attemptId: is_string($data['attempt_id'] ?? null) ? $data['attempt_id'] : null,
            ttftMs: is_int($ttft) || is_float($ttft) ? (float) $ttft : null,
            tpotMs: is_int($tpot) || is_float($tpot) ? (float) $tpot : null,
            request: $request,
        );
    }
}
