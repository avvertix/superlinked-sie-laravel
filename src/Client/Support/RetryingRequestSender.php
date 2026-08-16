<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use GuzzleHttp\Exception\ConnectException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Exceptions\LoraLoadingException;
use Sie\Client\Exceptions\ModelLoadingException;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\ResourceExhaustedException;
use Sie\Client\Exceptions\SieConnectionException;

/**
 * The shared provisioning / model-loading / LoRA-loading / OOM retry loop
 * used by encode, score, extract, generate, and chat completions.
 *
 * Direct port of the `while True` retry loop duplicated (with small,
 * endpoint-specific deltas) across `sync.py`'s `encode()`, `score()`,
 * `extract()`, `generate()`, and `chat_completions()`. The deltas are
 * captured by {@see RetryPolicy} instead of duplicating the loop per
 * endpoint.
 */
final class RetryingRequestSender
{
    public function __construct(
        private readonly SieConnector $connector,
        private readonly Clock $clock = new SystemClock,
        private readonly Sleeper $sleeper = new SystemSleeper,
    ) {}

    /**
     * @param  callable(float $requestTimeoutSeconds): Request  $requestFactory  Builds (or refreshes the timeout
     *                                                                           of) the request to send for this attempt, given the timeout to apply — capped to whatever
     *                                                                           remains of `$provisionTimeoutS`.
     */
    public function send(
        callable $requestFactory,
        RetryPolicy $policy,
        string $model,
        ?string $gpu,
        bool $waitForCapacity,
        float $provisionTimeoutS,
    ): Response {
        $start = $this->clock->now();
        $loraRetries = 0;
        $oomRetries = 0;

        while (true) {
            $elapsed = $this->clock->now() - $start;
            $remaining = $provisionTimeoutS - $elapsed;

            if ($remaining <= 0) {
                throw new ProvisioningException(
                    sprintf('Provision timeout (%.1fs) exceeded before request could be sent', $provisionTimeoutS),
                    gpu: $gpu,
                );
            }

            $request = $requestFactory(min($remaining, $this->connector->timeoutSeconds()));

            try {
                $response = $this->connector->send($request);
            } catch (FatalRequestException $exception) {
                if ($this->shouldRetryConnectFailure($exception, $policy, $waitForCapacity)) {
                    $delay = Backoff::transientRetryDelay($elapsed, $provisionTimeoutS);

                    if ($delay !== null) {
                        $this->sleeper->sleep($delay);

                        continue;
                    }
                }

                throw new SieConnectionException(
                    "Failed to connect to {$this->connector->resolveBaseUrl()}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            ErrorParser::raiseIfModelLoadFailed($response, $model);

            if ($policy->checkInputTooLong) {
                ErrorParser::raiseIfInputTooLong($response, $model);
            }

            $status = $response->status();

            if ($status === ErrorCodes::HTTP_SERVICE_UNAVAILABLE) {
                $code = ErrorParser::getErrorCode($response);

                if ($code === ErrorCodes::PROVISIONING) {
                    $this->sleeper->sleep($this->resolveProvisioningDelay($response, $gpu, $waitForCapacity, $start, $provisionTimeoutS));

                    continue;
                }

                if ($code === ErrorCodes::LORA_LOADING && $policy->allowLoraRetry) {
                    $loraRetries++;

                    if ($loraRetries > ErrorCodes::LORA_LOADING_MAX_RETRIES) {
                        throw new LoraLoadingException("LoRA loading timeout after {$loraRetries} retries", model: $model);
                    }

                    $this->sleeper->sleep(ErrorParser::getRetryAfter($response) ?? ErrorCodes::LORA_LOADING_DEFAULT_DELAY_S);

                    continue;
                }

                if ($code === ErrorCodes::MODEL_LOADING) {
                    $elapsed = $this->clock->now() - $start;

                    if ($elapsed >= $provisionTimeoutS) {
                        throw new ModelLoadingException(
                            sprintf("Model loading timeout after %.1fs for '%s'", $elapsed, $model),
                            model: $model,
                        );
                    }

                    $delay = ErrorParser::getRetryAfter($response) ?? ErrorCodes::MODEL_LOADING_DEFAULT_DELAY_S;
                    $this->sleeper->sleep(min($delay, $provisionTimeoutS - $elapsed));

                    continue;
                }

                if ($code === ErrorCodes::RESOURCE_EXHAUSTED) {
                    $oomRetries = $this->handleOomRetry($response, $start, $oomRetries, $policy->maxOomRetries, $provisionTimeoutS, $model);

                    continue;
                }
            }

            if ($status === ErrorCodes::HTTP_GATEWAY_TIMEOUT && $policy->retryOn504 && $waitForCapacity) {
                $elapsed = $this->clock->now() - $start;

                if ($elapsed < $provisionTimeoutS) {
                    $delay = ErrorParser::getRetryAfter($response) ?? ErrorCodes::MODEL_LOADING_DEFAULT_DELAY_S;
                    $this->sleeper->sleep(min($delay, $provisionTimeoutS - $elapsed));

                    continue;
                }
            }

            if ($status >= ErrorCodes::HTTP_CLIENT_ERROR) {
                ErrorParser::handleError($response);
            }

            return $response;
        }
    }

    private function shouldRetryConnectFailure(FatalRequestException $exception, RetryPolicy $policy, bool $waitForCapacity): bool
    {
        if (! $waitForCapacity) {
            return false;
        }

        $previous = $exception->getPrevious();

        if ($previous instanceof ConnectException) {
            return ConnectionErrorClassifier::isTransient($previous);
        }

        return $policy->retryMidFlightTransportErrors;
    }

    private function resolveProvisioningDelay(
        Response $response,
        ?string $gpu,
        bool $waitForCapacity,
        float $start,
        float $timeout,
    ): float {
        $retryAfter = ErrorParser::getRetryAfter($response);

        if (! $waitForCapacity) {
            throw new ProvisioningException(
                "No capacity available for GPU '{$gpu}'. Server is provisioning.",
                gpu: $gpu,
                retryAfter: $retryAfter,
            );
        }

        $elapsed = $this->clock->now() - $start;

        if ($elapsed >= $timeout) {
            throw new ProvisioningException(
                sprintf("Provisioning timeout after %.1fs waiting for GPU '%s'", $elapsed, $gpu),
                gpu: $gpu,
                retryAfter: $retryAfter,
            );
        }

        $remaining = $timeout - $elapsed;

        if ($retryAfter !== null) {
            return min($retryAfter, $remaining);
        }

        return Backoff::applyJitter(min(ErrorCodes::DEFAULT_RETRY_DELAY_S, $remaining));
    }

    private function handleOomRetry(
        Response $response,
        float $start,
        int $oomRetries,
        int $maxOomRetries,
        float $timeout,
        string $model,
    ): int {
        $elapsed = $this->clock->now() - $start;

        if ($oomRetries >= $maxOomRetries || $elapsed >= $timeout) {
            throw new ResourceExhaustedException(
                "Server resource exhausted after {$oomRetries} retry attempt(s) for model '{$model}'",
                model: $model,
                retries: $oomRetries,
            );
        }

        $rawDelay = Backoff::computeOomBackoff(ErrorParser::getRetryAfter($response), $oomRetries);
        $remaining = $timeout - $elapsed;

        if ($rawDelay >= $remaining) {
            throw new ResourceExhaustedException(
                "Server resource exhausted after {$oomRetries} retry attempt(s) for model '{$model}'",
                model: $model,
                retries: $oomRetries,
            );
        }

        $this->sleeper->sleep($rawDelay);

        return $oomRetries + 1;
    }
}
