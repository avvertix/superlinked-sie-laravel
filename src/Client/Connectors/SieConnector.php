<?php

declare(strict_types=1);

namespace Sie\Client\Connectors;

use Composer\InstalledVersions;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Sie\Client\Http\SieResponse;
use Sie\Client\SieClient;
use Sie\Client\Support\ErrorCodes;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\WireFormat;
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
    /**
     * Every response is decoded by {@see SieResponse}, which negotiates the
     * format from the `Content-Type` rather than assuming the one we asked for.
     */
    protected ?string $response = SieResponse::class;

    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutS = 30.0,
        private readonly ?string $apiKey = null,
        private readonly WireFormat $format = WireFormat::Msgpack,
    ) {}

    /**
     * The wire format this connection speaks.
     *
     * Only the inference POSTs negotiate — `/v1/models`, health and the pool
     * endpoints answer JSON whatever is asked of them, and generate has no
     * msgpack support at all on the server side.
     */
    public function format(): WireFormat
    {
        return $this->format;
    }

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
