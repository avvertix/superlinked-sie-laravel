<?php

declare(strict_types=1);

namespace Sie\Tests\Client\Fixtures;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/** Minimal Request used to drive retry-engine tests without a real endpoint. */
final class PingRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/ping';
    }
}
