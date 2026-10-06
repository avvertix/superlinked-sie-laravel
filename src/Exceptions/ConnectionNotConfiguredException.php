<?php

declare(strict_types=1);

namespace Sie\Exceptions;

use Sie\Client\Exceptions\SieException;
use Sie\Connection;

/**
 * Raised when a request is attempted on a connection that has no url.
 *
 * A {@see SieException} rather than a bare RuntimeException, so code that already
 * degrades on SIE failures — an outage, a provisioning timeout — degrades the same
 * way on a host that never configured SIE at all. Check
 * {@see Connection::isConfigured()} to skip the attempt entirely.
 */
final class ConnectionNotConfiguredException extends SieException
{
    public function __construct(
        public readonly string $connection,
    ) {
        parent::__construct(
            "The [{$connection}] SIE connection has no url. Set SIE_ENDPOINT, or the connection's url in config/superlinked-sie-laravel.php.",
        );
    }
}
