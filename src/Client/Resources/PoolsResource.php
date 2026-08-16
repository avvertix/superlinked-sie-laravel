<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Response;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Data\PoolInfo;
use Sie\Client\Exceptions\PoolException;
use Sie\Client\Exceptions\SieConnectionException;
use Sie\Client\Requests\Pools\CreatePoolRequest;
use Sie\Client\Requests\Pools\DeletePoolRequest;
use Sie\Client\Requests\Pools\GetPoolRequest;
use Sie\Client\Requests\Pools\RenewPoolLeaseRequest;
use Sie\Client\Support\ErrorCodes;

/**
 * No retry loop here — the Python SDK never retries pool operations, only
 * maps transport/HTTP failures onto {@see PoolException} (create/renew) or
 * {@see SieConnectionException} (get/delete, matching the asymmetry in
 * `sync.py`'s own exception choices for connect failures).
 */
final class PoolsResource
{
    public function __construct(private readonly SieConnector $connector) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(string $name, array $payload): void
    {
        try {
            $response = $this->connector->send(new CreatePoolRequest($payload));
        } catch (FatalRequestException $exception) {
            throw new PoolException("Failed to create pool '{$name}': connection error: {$exception->getMessage()}", poolName: $name, previous: $exception);
        }

        if ($response->status() >= ErrorCodes::HTTP_CLIENT_ERROR) {
            throw new PoolException("Failed to create pool '{$name}': ".self::extractMessage($response), poolName: $name);
        }
    }

    public function get(string $name): ?PoolInfo
    {
        try {
            $response = $this->connector->send(new GetPoolRequest($name));
        } catch (FatalRequestException $exception) {
            throw new SieConnectionException("Failed to get pool '{$name}': connection error: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->status() >= ErrorCodes::HTTP_CLIENT_ERROR) {
            throw new PoolException("Failed to get pool '{$name}': ".self::extractMessage($response), poolName: $name);
        }

        $data = $response->json();

        return PoolInfo::fromArray(is_array($data) ? $data : [], $name);
    }

    public function delete(string $name): bool
    {
        try {
            $response = $this->connector->send(new DeletePoolRequest($name));
        } catch (FatalRequestException $exception) {
            throw new SieConnectionException("Failed to delete pool '{$name}': connection error: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->status() === 404) {
            return false;
        }

        if ($response->status() >= ErrorCodes::HTTP_CLIENT_ERROR) {
            throw new PoolException("Failed to delete pool '{$name}': ".self::extractMessage($response), poolName: $name);
        }

        return true;
    }

    public function renew(string $name): void
    {
        try {
            $response = $this->connector->send(new RenewPoolLeaseRequest($name));
        } catch (FatalRequestException $exception) {
            throw new PoolException("Failed to renew lease for pool '{$name}': {$exception->getMessage()}", poolName: $name, previous: $exception);
        }

        if ($response->status() >= ErrorCodes::HTTP_CLIENT_ERROR) {
            throw new PoolException("Failed to renew lease for pool '{$name}': ".self::extractMessage($response), poolName: $name);
        }
    }

    private static function extractMessage(Response $response): string
    {
        $data = $response->json();

        if (is_array($data)) {
            $detail = $data['detail'] ?? null;

            if (is_array($detail)) {
                return (string) ($detail['message'] ?? json_encode($data));
            }

            if (is_string($detail)) {
                return $detail;
            }
        }

        $body = $response->body();

        return $body !== '' ? $body : "HTTP {$response->status()}";
    }
}
