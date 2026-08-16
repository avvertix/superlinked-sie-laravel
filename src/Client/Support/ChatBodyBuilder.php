<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * Assembles the `/v1/chat/completions` request body (snake_case wire shape).
 *
 * Only fields the caller set are included so the gateway applies its own
 * defaults for the rest. `$extraBody` is merged LAST so a caller can still
 * override / supply any forward-compat field not yet named on the typed
 * surface. Direct port of `build_chat_body`.
 */
final class ChatBodyBuilder
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  string|list<string>|null  $stop
     * @param  ?list<array<string, mixed>>  $tools
     * @param  ?array<string, mixed>  $responseFormat
     * @param  ?array<string, float>  $logitBias
     * @param  ?array<string, mixed>  $streamOptions
     * @param  ?array<string, mixed>  $extraBody
     * @return array<string, mixed>
     */
    public static function build(
        string $model,
        array $messages,
        bool $stream,
        ?int $maxCompletionTokens = null,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?float $topP = null,
        ?int $topK = null,
        ?float $repetitionPenalty = null,
        string|array|null $stop = null,
        ?array $tools = null,
        mixed $toolChoice = null,
        ?bool $parallelToolCalls = null,
        ?array $responseFormat = null,
        ?float $frequencyPenalty = null,
        ?float $presencePenalty = null,
        ?int $n = null,
        ?int $bestOf = null,
        ?bool $logprobs = null,
        ?int $topLogprobs = null,
        ?array $logitBias = null,
        ?int $seed = null,
        ?string $user = null,
        ?string $safetyIdentifier = null,
        ?string $loraAdapter = null,
        ?array $streamOptions = null,
        ?array $extraBody = null,
    ): array {
        $body = ['model' => $model, 'messages' => $messages];

        if ($stream) {
            $body['stream'] = true;
        }

        $optional = [
            'max_completion_tokens' => $maxCompletionTokens,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'top_p' => $topP,
            'top_k' => $topK,
            'repetition_penalty' => $repetitionPenalty,
            'stop' => $stop,
            'tools' => $tools,
            'tool_choice' => $toolChoice,
            'parallel_tool_calls' => $parallelToolCalls,
            'response_format' => $responseFormat,
            'frequency_penalty' => $frequencyPenalty,
            'presence_penalty' => $presencePenalty,
            'n' => $n,
            'best_of' => $bestOf,
            'logprobs' => $logprobs,
            'top_logprobs' => $topLogprobs,
            'logit_bias' => $logitBias,
            'seed' => $seed,
            'user' => $user,
            'safety_identifier' => $safetyIdentifier,
            'lora_adapter' => $loraAdapter,
            'stream_options' => $streamOptions,
        ];

        foreach ($optional as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }

        if ($extraBody !== null) {
            $body = [...$body, ...$extraBody];
        }

        return $body;
    }
}
