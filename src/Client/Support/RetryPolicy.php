<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * Per-endpoint knobs for {@see RetryingRequestSender}.
 *
 * Encode/score/extract and generate/chat share the same 503
 * PROVISIONING/MODEL_LOADING/RESOURCE_EXHAUSTED retry machinery but differ on
 * a handful of endpoint-specific rules (see `sync.py` per-method
 * docstrings) — this value object captures those deltas as configuration
 * instead of duplicating the loop per endpoint.
 */
final class RetryPolicy
{
    public function __construct(
        /** Only `encode()` retries 503 LORA_LOADING. */
        public readonly bool $allowLoraRetry = false,
        /**
         * `encode`/`score`/`extract` retry a 504 as defense-in-depth against
         * gateways that haven't yet mapped an upstream timeout to 503
         * MODEL_LOADING. `generate`/`chat` treat 504 as terminal — the
         * request may already be dispatched to a worker, and retrying a
         * non-idempotent generation could double-bill.
         */
        public readonly bool $retryOn504 = true,
        /**
         * Whether a transport error that occurs *after* the request was
         * sent (as opposed to a pre-send connect failure) is retryable.
         * False for generate/chat (non-idempotent).
         */
        public readonly bool $retryMidFlightTransportErrors = true,
        /** `extract()` short-circuits 400 INPUT_TOO_LONG before the retry switch. */
        public readonly bool $checkInputTooLong = false,
        public readonly int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ) {}
}
