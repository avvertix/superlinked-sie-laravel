<?php

declare(strict_types=1);

namespace Sie\Exceptions;

use Sie\Client\Exceptions\SieException;
use Throwable;

/**
 * Raised when the cluster rejects a request because the model cannot serve the
 * requested capability — asking `docling` to encode, for instance.
 *
 * We do not preflight this locally even though `/v1/models` would tell us: the
 * cluster is authoritative over what a request may attempt (see ADR 0003). The
 * rejection the cluster actually sent — with its wire code, status and request
 * metadata — is kept as `getPrevious()`.
 *
 * The cluster answers this in two shapes, a `400 INVALID_INPUT` naming the
 * unsupported output types and a terminal `503 QUEUE_UNAVAILABLE` reporting no
 * rate for the (model, profile, operation, region) tuple. The second says the
 * tuple is unroutable rather than that the model is incapable, so read this as
 * "this deployment will not run that capability for that model" — check
 * `getPrevious()` for the reason the cluster gave.
 */
final class UnsupportedCapabilityException extends SieException
{
    public function __construct(
        string $message,
        public readonly string $model,
        public readonly string $capability,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
