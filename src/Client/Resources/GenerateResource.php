<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Generator;
use Sie\Client\Data\GenerateChunk;
use Sie\Client\Data\GenerateResult;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Generate\GenerateRequest;
use Sie\Client\Requests\Generate\StreamGenerateRequest;
use Sie\Client\Resources\Concerns\ReportsNonIdempotentGatewayTimeout;
use Sie\Client\Support\RequestMetadataParser;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;
use Sie\Client\Support\SseStream;

final class GenerateResource
{
    use ReportsNonIdempotentGatewayTimeout;

    public function __construct(private readonly RetryingRequestSender $sender) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(
        string $model,
        array $payload,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): GenerateResult {
        $requestFactory = static function (float $timeout) use ($model, $payload, $gpu, $poolName): GenerateRequest {
            $request = new GenerateRequest($model, $payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sendNonIdempotent($requestFactory, self::policy($maxOomRetries), $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        return GenerateResult::fromArray($response->json(), RequestMetadataParser::parse($response));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Generator<int, GenerateChunk>
     */
    public function streamGenerate(
        string $model,
        array $payload,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): Generator {
        $requestFactory = static function (float $timeout) use ($model, $payload, $gpu, $poolName): StreamGenerateRequest {
            $request = new StreamGenerateRequest($model, $payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sendNonIdempotent($requestFactory, self::policy($maxOomRetries), $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        foreach (SseStream::payloads($response->stream()) as $payload) {
            $chunk = json_decode($payload, true);

            if (! is_array($chunk)) {
                throw new ServerException("Malformed SSE chunk from generate stream: {$payload}");
            }

            $error = $chunk['error'] ?? null;

            if (is_array($error)) {
                throw new ServerException(
                    (string) ($error['message'] ?? 'stream error'),
                    errorCode: (string) ($error['code'] ?? 'error'),
                );
            }

            yield GenerateChunk::fromArray($chunk);
        }
    }

    private static function policy(int $maxOomRetries): RetryPolicy
    {
        // generate() is non-idempotent: a 504 or mid-flight transport error may mean a worker
        // already started generating, so neither is retried (unlike encode/score/extract).
        return new RetryPolicy(
            allowLoraRetry: false,
            retryOn504: false,
            retryMidFlightTransportErrors: false,
            maxOomRetries: $maxOomRetries,
        );
    }
}
