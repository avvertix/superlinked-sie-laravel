<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Generate;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Sie\Client\Support\ErrorCodes;
use Sie\Client\Support\SseStream;

/**
 * `POST /v1/generate/{*model}` with `stream: true`. `defaultConfig()` sets
 * Guzzle's `stream` option so the response body is not buffered, letting
 * {@see SseStream} read it incrementally.
 */
final class StreamGenerateRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $model,
        private readonly array $payload,
        private readonly ?string $gpu = null,
        private readonly ?string $poolName = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/v1/generate/'.str_replace('/', '__', $this->model);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        $headers = ['Accept' => 'text/event-stream'];

        if ($this->gpu !== null) {
            $headers[ErrorCodes::MACHINE_PROFILE_HEADER] = $this->gpu;
        }

        if ($this->poolName !== null) {
            $headers[ErrorCodes::POOL_HEADER] = $this->poolName;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return ['stream' => true];
    }
}
