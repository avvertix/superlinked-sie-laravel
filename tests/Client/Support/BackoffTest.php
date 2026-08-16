<?php

declare(strict_types=1);

use Sie\Client\Support\Backoff;

it('does not jitter a non-positive delay', function () {
    expect(Backoff::applyJitter(0.0))->toBe(0.0);
    expect(Backoff::applyJitter(-5.0))->toBe(0.0);
});

it('jitters downward only, within [delay * 0.75, delay]', function () {
    $delay = Backoff::applyJitter(10.0, fn (float $low, float $high) => ($low + $high) / 2);

    expect($delay)->toBe(8.75); // (7.5 + 10) / 2
});

it('honours a first-attempt Retry-After verbatim, capped at max delay', function () {
    expect(Backoff::computeOomBackoff(retryAfter: 10.0, attempt: 0, maxDelay: 30.0))->toBe(10.0);
    expect(Backoff::computeOomBackoff(retryAfter: 100.0, attempt: 0, maxDelay: 30.0))->toBe(30.0);
});

it('applies exponential backoff with a non-decreasing schedule on later attempts', function () {
    $identity = fn (float $low, float $high) => $high; // no jitter, easier to assert exact values

    expect(Backoff::computeOomBackoff(null, 1, uniform: $identity))->toBe(10.0); // 5 * 2**1
    expect(Backoff::computeOomBackoff(null, 2, uniform: $identity))->toBe(20.0); // 5 * 2**2
    expect(Backoff::computeOomBackoff(null, 3, uniform: $identity))->toBe(30.0); // 5 * 2**3 capped at 30
});

it('uses the larger of base delay and a non-first-attempt Retry-After hint', function () {
    $identity = fn (float $low, float $high) => $high;

    // Retry-After: 20 on attempt 1 would produce 10 with base_delay alone (non-monotonic); max() fixes that.
    expect(Backoff::computeOomBackoff(20.0, 1, uniform: $identity))->toBe(30.0); // max(5,20)*2 = 40, capped 30
});

it('returns null once the elapsed time exceeds the timeout budget', function () {
    expect(Backoff::transientRetryDelay(elapsed: 10.0, timeout: 10.0))->toBeNull();
    expect(Backoff::transientRetryDelay(elapsed: 11.0, timeout: 10.0))->toBeNull();
});

it('caps the transient retry delay to the remaining budget', function () {
    $identity = fn (float $low, float $high) => $high;

    expect(Backoff::transientRetryDelay(elapsed: 8.0, timeout: 10.0, uniform: $identity))->toBe(2.0);
    expect(Backoff::transientRetryDelay(elapsed: 0.0, timeout: 10.0, uniform: $identity))->toBe(5.0);
});
