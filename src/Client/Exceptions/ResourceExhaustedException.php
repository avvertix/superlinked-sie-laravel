<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

use Sie\Client\Data\RequestMetadata;

/**
 * Error when the server has exhausted its OOM-recovery strategies.
 *
 * Raised when:
 * - Server returns 503 with RESOURCE_EXHAUSTED code
 * - SDK retry limit (`maxOomRetries`) is exceeded
 *
 * Subclass of {@see ServerException} so callers that already catch it
 * continue to behave correctly; new code can catch this exception
 * specifically to react to sustained GPU pressure.
 */
class ResourceExhaustedException extends ServerException
{
    public function __construct(
        string $message,
        public readonly ?string $model = null,
        public readonly int $retries = 0,
        ?RequestMetadata $request = null,
    ) {
        parent::__construct($message, errorCode: 'RESOURCE_EXHAUSTED', statusCode: 503, request: $request);
    }
}
