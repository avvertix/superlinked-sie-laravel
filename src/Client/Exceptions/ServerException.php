<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

use Sie\Client\Data\RequestMetadata;
use Throwable;

/** Error from the server (5xx responses). */
class ServerException extends SieException
{
    public function __construct(
        string $message,
        /** SIE wire error code (e.g. "MODEL_LOAD_FAILED"), distinct from Exception::$code (an int). */
        public readonly ?string $errorCode = null,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
        /** Request-scoped metadata parsed from the terminal response, when the server supplied any. */
        public readonly ?RequestMetadata $request = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
