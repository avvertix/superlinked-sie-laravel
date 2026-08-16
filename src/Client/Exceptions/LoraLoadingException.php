<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

/**
 * Error when a LoRA adapter is loading and the retry limit is exceeded.
 *
 * Raised when:
 * - Server returns 503 with LORA_LOADING code
 * - Retry limit is exceeded
 */
class LoraLoadingException extends SieException
{
    public function __construct(
        string $message,
        public readonly ?string $lora = null,
        public readonly ?string $model = null,
    ) {
        parent::__construct($message);
    }
}
