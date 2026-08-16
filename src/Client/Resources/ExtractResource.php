<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Sie\Client\Data\ExtractResult;
use Sie\Client\Requests\Extract\ExtractRequest;
use Sie\Client\Support\RequestMetadataParser;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;

final class ExtractResource
{
    public function __construct(private readonly RetryingRequestSender $sender) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<ExtractResult>
     */
    public function extract(
        string $model,
        array $payload,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): array {
        // extract() has no LoRA-loading retry branch, and short-circuits 400 INPUT_TOO_LONG before the 503 switch.
        $policy = new RetryPolicy(
            allowLoraRetry: false,
            retryOn504: true,
            retryMidFlightTransportErrors: true,
            checkInputTooLong: true,
            maxOomRetries: $maxOomRetries,
        );

        $requestFactory = static function (float $timeout) use ($model, $payload, $gpu, $poolName): ExtractRequest {
            $request = new ExtractRequest($model, $payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sender->send($requestFactory, $policy, $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        $requestMetadata = RequestMetadataParser::parse($response);
        $data = $response->json();

        return array_map(
            static fn (array $item): ExtractResult => ExtractResult::fromArray($item, $requestMetadata),
            $data['items'] ?? [],
        );
    }
}
