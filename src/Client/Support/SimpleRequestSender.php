<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Exceptions\SieConnectionException;

/**
 * Single-attempt sender for the handful of endpoints the Python SDK never
 * retries: `list_models`, `get_model`, `get_capacity` (`/health`). No
 * provisioning/model-loading retry loop — just connect-failure mapping and
 * the standard 4xx/5xx error envelope handling.
 */
final class SimpleRequestSender
{
    public function __construct(private readonly SieConnector $connector) {}

    public function send(Request $request): Response
    {
        try {
            $response = $this->connector->send($request);
        } catch (FatalRequestException $exception) {
            throw new SieConnectionException(
                "Failed to connect to {$this->connector->resolveBaseUrl()}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if ($response->status() >= ErrorCodes::HTTP_CLIENT_ERROR) {
            ErrorParser::handleError($response);
        }

        return $response;
    }
}
