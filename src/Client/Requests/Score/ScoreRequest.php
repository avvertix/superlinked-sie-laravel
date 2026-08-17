<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Score;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Sie\Client\Body\HasSieBody;
use Sie\Client\Support\ErrorCodes;

/** `POST /v1/score/{*model}` (Axum catch-all, model id sent unencoded). */
final class ScoreRequest extends Request implements HasBody
{
    use HasSieBody;

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
        return "/v1/score/{$this->model}";
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
