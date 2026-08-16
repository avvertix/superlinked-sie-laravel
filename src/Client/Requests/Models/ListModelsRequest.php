<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Models;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** `GET /v1/models`. */
final class ListModelsRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/v1/models';
    }
}
