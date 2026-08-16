<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

/**
 * Error when capacity is not available and provisioning timed out.
 *
 * Raised when:
 * - Server returns 503 with PROVISIONING code
 * - waitForCapacity = false (caller doesn't want to wait)
 * - Or provisioning timeout exceeded
 */
class ProvisioningException extends SieException
{
    public function __construct(
        string $message,
        public readonly ?string $gpu = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
