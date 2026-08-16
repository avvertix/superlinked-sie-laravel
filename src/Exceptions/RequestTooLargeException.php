<?php

declare(strict_types=1);

namespace Sie\Exceptions;

use Sie\Client\Exceptions\SieException;

/**
 * Raised before a request is built when its resolved inputs exceed the
 * configured `max_request_bytes`.
 *
 * Documents are buffered in memory rather than streamed (see ADR 0001), so this
 * guard exists to fail loudly instead of letting PHP hit its memory limit
 * halfway through encoding a batch. Splitting an oversized batch is the
 * caller's decision — we deliberately do not auto-chunk.
 */
final class RequestTooLargeException extends SieException
{
    public function __construct(
        public readonly int $bytes,
        public readonly int $limit,
    ) {
        parent::__construct(sprintf(
            'The request resolves to %d bytes, which exceeds the configured limit of %d. Send fewer inputs per request, or raise superlinked-sie-laravel.max_request_bytes.',
            $bytes,
            $limit,
        ));
    }
}
