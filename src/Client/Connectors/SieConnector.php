<?php

declare(strict_types=1);

namespace Sie\Client\Connectors;

use Composer\InstalledVersions;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Sie\Client\SieClient;
use Sie\Client\Support\ErrorCodes;
use Sie\Client\Support\RetryingRequestSender;
use Throwable;

/**
 * Thin Saloon connector: base URL, default JSON headers, Bearer auth, and the
 * base per-request timeout. All retry/error/version-skew logic lives outside
 * the connector (see {@see RetryingRequestSender}
 * and {@see SieClient}) — the connector itself
 * has no behaviour beyond "how to reach the server".
 */
final class SieConnector extends Connector
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutS = 30.0,
        private readonly ?string $apiKey = null,
    ) {}

    public function resolveBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function timeoutSeconds(): float
    {
        return $this->timeoutS;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            ErrorCodes::SDK_VERSION_HEADER => self::sdkVersion(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'timeout' => $this->timeoutS,
        ];
    }

    protected function defaultAuth(): ?Authenticator
    {
        return $this->apiKey !== null ? new TokenAuthenticator($this->apiKey) : null;
    }

    public static function sdkVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('avvertix/sie-client-php') ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
