<?php

declare(strict_types=1);

namespace Sie\Client\Requests\Pools;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * `POST /v1/pools/{name}/renew`.
 *
 * Per the porting decision, there is no automatic background lease-renewal
 * thread (PHP has no lightweight background threads in a normal request
 * lifecycle) — `SieClient::renewPoolLease()` wraps this request for the
 * caller to invoke on their own schedule (cron, queue worker loop, etc.).
 */
final class RenewPoolLeaseRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(private readonly string $name) {}

    public function resolveEndpoint(): string
    {
        return "/v1/pools/{$this->name}/renew";
    }
}
