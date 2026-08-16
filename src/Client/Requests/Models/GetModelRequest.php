<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Models;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** `GET /v1/models/{*model}` (Axum catch-all, model id sent unencoded). */
final class GetModelRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $model) {}

    public function resolveEndpoint(): string
    {
        return "/v1/models/{$this->model}";
    }
}
