<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Generator;
use Sie\Client\Data\ChatCompletion;
use Sie\Client\Data\ChatCompletionChunk;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Chat\ChatCompletionsRequest;
use Sie\Client\Requests\Chat\StreamChatCompletionsRequest;
use Sie\Client\Resources\Concerns\ReportsNonIdempotentGatewayTimeout;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;
use Sie\Client\Support\SseStream;

final class ChatResource
{
    use ReportsNonIdempotentGatewayTimeout;

    public function __construct(private readonly RetryingRequestSender $sender) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function chatCompletions(
        array $payload,
        string $model,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): ChatCompletion {
        $requestFactory = static function (float $timeout) use ($payload, $gpu, $poolName): ChatCompletionsRequest {
            $request = new ChatCompletionsRequest($payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sendNonIdempotent($requestFactory, self::policy($maxOomRetries), $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        return ChatCompletion::fromArray($response->json());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Generator<int, ChatCompletionChunk>
     */
    public function streamChatCompletions(
        array $payload,
        string $model,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): Generator {
        $requestFactory = static function (float $timeout) use ($payload, $gpu, $poolName): StreamChatCompletionsRequest {
            $request = new StreamChatCompletionsRequest($payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sendNonIdempotent($requestFactory, self::policy($maxOomRetries), $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        foreach (SseStream::payloads($response->stream()) as $payload) {
            $chunk = json_decode($payload, true);

            if (! is_array($chunk)) {
                throw new ServerException("Malformed SSE chunk from chat stream: {$payload}");
            }

            $error = $chunk['error'] ?? null;

            if (is_array($error)) {
                throw new ServerException(
                    (string) ($error['message'] ?? 'stream error'),
                    errorCode: (string) ($error['code'] ?? 'error'),
                );
            }

            yield ChatCompletionChunk::fromArray($chunk);
        }
    }

    private static function policy(int $maxOomRetries): RetryPolicy
    {
        return new RetryPolicy(
            allowLoraRetry: false,
            retryOn504: false,
            retryMidFlightTransportErrors: false,
            maxOomRetries: $maxOomRetries,
        );
    }
}
