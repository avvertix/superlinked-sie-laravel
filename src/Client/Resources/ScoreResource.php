<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Sie\Client\Data\ScoreResult;
use Sie\Client\Http\SieResponse;
use Sie\Client\Requests\Score\ScoreRequest;
use Sie\Client\Support\RequestMetadataParser;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;

final class ScoreResource
{
    public function __construct(private readonly RetryingRequestSender $sender) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function score(
        string $model,
        array $payload,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): ScoreResult {
        // score() has no LoRA-loading retry branch in the Python SDK — the reranker path never surfaces LORA_LOADING.
        $policy = new RetryPolicy(
            allowLoraRetry: false,
            retryOn504: true,
            retryMidFlightTransportErrors: true,
            maxOomRetries: $maxOomRetries,
        );

        $requestFactory = static function (float $timeout) use ($model, $payload, $gpu, $poolName): ScoreRequest {
            $request = new ScoreRequest($model, $payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sender->send($requestFactory, $policy, $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        return ScoreResult::fromArray(SieResponse::decode($response), RequestMetadataParser::parse($response));
    }
}
