<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

use Sie\Client\Data\RequestMetadata;
use Throwable;

/** Error in the request (4xx responses). */
class RequestException extends SieException
{
    public function __construct(
        string $message,
        /** SIE wire error code (e.g. "PROVISIONING"), distinct from Exception::$code (an int). */
        public readonly ?string $errorCode = null,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
        /** Request-scoped metadata parsed from the terminal response, when the server supplied any. */
        public readonly ?RequestMetadata $request = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
