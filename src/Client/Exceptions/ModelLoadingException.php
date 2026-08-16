<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

/**
 * Error when a model is loading and the retry limit (provision timeout) is exceeded.
 *
 * Raised when:
 * - Server returns 503 with MODEL_LOADING code
 * - The provision timeout budget is exhausted
 */
class ModelLoadingException extends SieException
{
    public function __construct(
        string $message,
        public readonly ?string $model = null,
    ) {
        parent::__construct($message);
    }
}
