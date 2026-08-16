<?php

declare(strict_types=1);

namespace Sie\Client\Resources\Concerns;

use Saloon\Http\Request;
use Saloon\Http\Response;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Resources\ChatResource;
use Sie\Client\Resources\GenerateResource;
use Sie\Client\Support\ErrorCodes;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;

/**
 * Shared by {@see GenerateResource} and
 * {@see ChatResource}: generation is
 * non-idempotent, so a 504 (the request may already be dispatched to a
 * worker) is never retried — this rewrites the generic "HTTP 504" message
 * into the specific "why we didn't retry" explanation the Python SDK gives.
 * Requires the using class to expose a `RetryingRequestSender $sender` property.
 */
trait ReportsNonIdempotentGatewayTimeout
{
    /** Provided by the composing class's constructor-promoted `$sender` property. */

    /**
     * @param  callable(float): Request  $requestFactory
     */
    private function sendNonIdempotent(
        callable $requestFactory,
        RetryPolicy $policy,
        string $model,
        ?string $gpu,
        bool $waitForCapacity,
        float $provisionTimeoutS,
    ): Response {
        try {
            return $this->sender->send($requestFactory, $policy, $model, $gpu, $waitForCapacity, $provisionTimeoutS);
        } catch (ServerException $exception) {
            if ($exception->statusCode !== ErrorCodes::HTTP_GATEWAY_TIMEOUT) {
                throw $exception;
            }

            throw new ServerException(
                'Gateway timed out (504) after the request was published to the queue; a worker may already be '
                .'generating. Not retried because generation is non-idempotent (retrying could double-bill).',
                errorCode: $exception->errorCode,
                statusCode: 504,
                previous: $exception,
            );
        }
    }
}
