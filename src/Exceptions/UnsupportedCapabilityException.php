<?php

declare(strict_types=1);

namespace Sie\Exceptions;

use Sie\Client\Exceptions\SieException;

/**
 * Raised when the cluster rejects a request because the model cannot serve the
 * requested capability — asking `docling` to encode, for instance.
 *
 * We do not preflight this locally even though `/v1/models` would tell us: the
 * cluster is authoritative over what a request may attempt (see ADR 0003).
 */
final class UnsupportedCapabilityException extends SieException
{
    public function __construct(
        string $message,
        public readonly string $model,
        public readonly string $capability,
    ) {
        parent::__construct($message);
    }
}
