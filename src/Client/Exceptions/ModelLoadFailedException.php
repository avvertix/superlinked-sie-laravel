<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

use Sie\Client\Data\RequestMetadata;

/**
 * Error when the server reports a recorded model-load failure.
 *
 * Distinct from {@see ModelLoadingException} — this is raised on the first
 * response (no retry budget consumed) when the server returns HTTP
 * `502 MODEL_LOAD_FAILED`. The server uses this code for both:
 *
 * - Permanent-class failures (GATED, NOT_FOUND, DEPENDENCY, UNKNOWN) where
 *   retrying would waste time and operator intervention is required. These
 *   carry `permanent = true`.
 * - Transient classes in active cooldown (OOM, NETWORK) where the registry
 *   is suppressing retries for a finite window. These carry
 *   `permanent = false`; the failure auto-expires and a later request will
 *   trigger a fresh load attempt.
 *
 * Either way the server omits the `Retry-After` header so the SDK
 * short-circuits its MODEL_LOADING retry budget and surfaces the error
 * immediately.
 */
class ModelLoadFailedException extends ServerException
{
    public function __construct(
        string $message,
        public readonly ?string $model = null,
        public readonly ?string $errorClass = null,
        public readonly bool $permanent = true,
        public readonly int $attempts = 1,
        ?RequestMetadata $request = null,
    ) {
        parent::__construct($message, errorCode: 'MODEL_LOAD_FAILED', statusCode: 502, request: $request);
    }
}
