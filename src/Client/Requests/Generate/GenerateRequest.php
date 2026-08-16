<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Generate;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;
use Sie\Client\Support\ErrorCodes;

/**
 * `POST /v1/generate/{*model}`. The model id is sent in the "SIE-safe" form
 * (`/` replaced with `__`), matching the Python SDK — the gateway resolves
 * both this and the raw slash form, but the SDK has always sent the
 * safe form, so this port preserves that exact wire behaviour.
 */
final class GenerateRequest extends Request implements HasBody
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
        $headers = [];

        if ($this->gpu !== null) {
            $headers[ErrorCodes::MACHINE_PROFILE_HEADER] = $this->gpu;
        }

        if ($this->poolName !== null) {
            $headers[ErrorCodes::POOL_HEADER] = $this->poolName;
        }

        return $headers;
    }
}
