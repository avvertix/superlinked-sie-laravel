<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Pools;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** `GET /v1/pools/{name}`. */
final class GetPoolRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $name) {}

    public function resolveEndpoint(): string
    {
        return "/v1/pools/{$this->name}";
    }
}
