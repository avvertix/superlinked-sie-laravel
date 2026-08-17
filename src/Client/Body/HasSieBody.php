<?php

declare(strict_types=1);

namespace Sie\Client\Body;

use Saloon\Http\PendingRequest;
use Saloon\Traits\Body\ChecksForHasBody;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Support\WireFormat;

/**
 * Gives a request a body that follows its connection's wire format.
 *
 * Shaped after Saloon's `HasJsonBody`, with one addition: the format is not
 * known when the request is constructed, only when it is about to be sent
 * against a particular connection. Saloon hands the `PendingRequest` to the
 * boot hook for exactly this, and `BootPlugins` runs before `MergeBody`, so the
 * repository is told its format before it is serialised.
 *
 * Headers are set on the *request*, not on the pending request:
 * `MergeRequestProperties` merges connector headers and then request headers
 * into the pending request, so anything written to the pending request during
 * boot is overwritten by the connector's `application/json` defaults, while
 * request headers win.
 *
 * @phpstan-ignore trait.unused
 */
trait HasSieBody
{
    use ChecksForHasBody;

    protected SieBodyRepository $body;

    public function bootHasSieBody(PendingRequest $pendingRequest): void
    {
        $connector = $pendingRequest->getConnector();
        $format = $connector instanceof SieConnector ? $connector->format() : WireFormat::Json;

        $this->body()->useFormat($format);

        $this->headers()->add('Content-Type', $format->contentType());
        $this->headers()->add('Accept', $format->contentType());
    }

    public function body(): SieBodyRepository
    {
        return $this->body ??= new SieBodyRepository($this->defaultBody());
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [];
    }
}
