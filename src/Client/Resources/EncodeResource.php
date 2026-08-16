<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Sie\Client\Data\EncodeResult;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Encode\EncodeRequest;
use Sie\Client\Support\RequestMetadataParser;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;

final class EncodeResource
{
    public function __construct(private readonly RetryingRequestSender $sender) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<EncodeResult>
     */
    public function encode(
        string $model,
        array $payload,
        ?string $gpu,
        ?string $poolName,
        bool $waitForCapacity,
        float $provisionTimeoutS,
        int $maxOomRetries,
    ): array {
        $policy = new RetryPolicy(
            allowLoraRetry: true,
            retryOn504: true,
            retryMidFlightTransportErrors: true,
            maxOomRetries: $maxOomRetries,
        );

        $requestFactory = static function (float $timeout) use ($model, $payload, $gpu, $poolName): EncodeRequest {
            $request = new EncodeRequest($model, $payload, $gpu, $poolName);
            $request->config()->add('timeout', $timeout);

            return $request;
        };

        $response = $this->sender->send($requestFactory, $policy, $model, $gpu, $waitForCapacity, $provisionTimeoutS);

        $requestMetadata = RequestMetadataParser::parse($response);
        $data = $response->json();
        $timing = $data['timing'] ?? null;

        // The envelope's model is the id that actually served the request,
        // which can differ from the one asked for (alias/profile resolution).
        $responseModel = $data['model'] ?? null;
        $responseModel = is_string($responseModel) && $responseModel !== '' ? $responseModel : null;

        $results = array_map(
            static fn (array $item): EncodeResult => EncodeResult::fromArray($item, $timing, $responseModel, $requestMetadata),
            $data['items'] ?? [],
        );

        self::assertResultCount($results, count($payload['items']), $model);

        return $results;
    }

    /**
     * Guard the encode contract: exactly one result per input item.
     *
     * Encode is positional. `SieClient::encode()` returns `$results[0]` for a
     * single-item request, and batch callers reassemble embeddings by index.
     * The contract can break on an HTTP 200 whose `items` list is *shorter*
     * than the request: the gateway returns mixed-success batches as 200
     * carrying only the successful items, so a per-item server-side failure
     * (an input over the model's `max_sequence_length`, say) is silently
     * dropped from the body rather than surfaced as an error envelope.
     *
     * Without this check a short list flows into positional access and every
     * later result is silently attributed to the wrong input. Direct port of
     * `validate_encode_result_count`.
     *
     * @param  list<EncodeResult>  $results
     *
     * @throws ServerException if the returned count differs from the requested count
     */
    private static function assertResultCount(array $results, int $expected, string $model): void
    {
        if (count($results) === $expected) {
            return;
        }

        throw new ServerException(
            sprintf(
                "Encode response desync for model '%s': server returned %d embedding(s) for %d input "
                .'item(s); expected exactly one per input. An input may have failed server-side '
                ."(e.g. exceeding the model's max_sequence_length) and been dropped from the batch.",
                $model,
                count($results),
                $expected,
            ),
            errorCode: 'ENCODE_RESULT_COUNT_MISMATCH',
        );
    }
}
