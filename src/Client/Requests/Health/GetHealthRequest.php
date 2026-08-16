<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Health;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** `GET /health`. */
final class GetHealthRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/health';
    }
}
