<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

use Throwable;

/**
 * Error related to resource pool operations.
 *
 * Raised when:
 * - Pool creation fails (e.g., insufficient capacity)
 * - Pool not found
 * - Pool in invalid state (e.g., expired)
 * - Pool lease renewal fails
 */
class PoolException extends SieException
{
    public function __construct(
        string $message,
        public readonly ?string $poolName = null,
        public readonly ?string $state = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
