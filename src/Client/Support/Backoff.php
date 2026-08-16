<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * Retry backoff math shared across encode/score/extract/generate/chat.
 *
 * Direct port of `_shared.py`'s `apply_jitter` / `compute_oom_backoff` /
 * `compute_retry_delay`. `$uniform` is an injectable `(float $low, float
 * $high): float` sampler so tests can make jitter deterministic; it defaults
 * to a `random_int`-based uniform draw.
 */
final class Backoff
{
    /**
     * Apply bounded downward jitter to a backoff delay.
     *
     * Returns a value drawn uniformly from
     * `[delay * (1 - RETRY_JITTER_FRACTION), delay]` (clamped to `>= 0`).
     * Jittering down-only guarantees the result never exceeds the input, so
     * callers' existing caps and provision-timeout budgets remain valid.
     */
    public static function applyJitter(float $delay, ?callable $uniform = null): float
    {
        if ($delay <= 0) {
            return max($delay, 0.0);
        }

        $uniform ??= self::defaultUniform();
        $low = $delay * (1.0 - ErrorCodes::RETRY_JITTER_FRACTION);

        return max(0.0, $uniform($low, $delay));
    }

    /**
     * Compute the next sleep interval for a RESOURCE_EXHAUSTED retry.
     *
     * Honours a server-supplied `Retry-After` (when present) verbatim on the
     * first attempt, then applies bounded exponential backoff
     * (`base * 2**attempt`, capped at `$maxDelay`) with downward jitter.
     */
    public static function computeOomBackoff(
        ?float $retryAfter,
        int $attempt,
        float $baseDelay = ErrorCodes::RESOURCE_EXHAUSTED_DEFAULT_DELAY_S,
        float $maxDelay = ErrorCodes::RESOURCE_EXHAUSTED_MAX_DELAY_S,
        ?callable $uniform = null,
    ): float {
        $safeRetryAfter = $retryAfter !== null ? max($retryAfter, 0.0) : null;

        if ($safeRetryAfter !== null && $attempt === 0) {
            return min($safeRetryAfter, $maxDelay);
        }

        $base = $safeRetryAfter !== null ? max($baseDelay, $safeRetryAfter) : $baseDelay;
        $capped = max(0.0, min($base * (2 ** $attempt), $maxDelay));

        return self::applyJitter($capped, $uniform);
    }

    /**
     * Sleep duration for the next transport-error retry, or `null` if the
     * provision-timeout budget is exhausted (caller must re-raise).
     */
    public static function transientRetryDelay(float $elapsed, float $timeout, ?callable $uniform = null): ?float
    {
        if ($elapsed >= $timeout) {
            return null;
        }

        return self::applyJitter(min(ErrorCodes::MODEL_LOADING_DEFAULT_DELAY_S, $timeout - $elapsed), $uniform);
    }

    /**
     * A uniform draw over `[$low, $high]`.
     *
     * Uses `random_int` rather than `mt_rand`: jitter has no cryptographic
     * requirement, but a CSPRNG costs nothing at the handful of draws a retry
     * loop makes, and it keeps the security preset honest instead of carrying
     * an exemption for a function nobody needs here.
     */
    private static function defaultUniform(): callable
    {
        return static fn (float $low, float $high): float => $low + (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) * ($high - $low);
    }
}
