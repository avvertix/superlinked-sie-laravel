<?php

declare(strict_types=1);

use Sie\Client\Support\RetryAfter;

it('parses a numeric seconds value', function () {
    expect(RetryAfter::parse('5'))->toBe(5.0);
    expect(RetryAfter::parse('0'))->toBe(0.0);
    expect(RetryAfter::parse('2.5'))->toBe(2.5);
});

it('returns null for missing, negative, or non-finite values', function () {
    expect(RetryAfter::parse(null))->toBeNull();
    expect(RetryAfter::parse(''))->toBeNull();
    expect(RetryAfter::parse('-5'))->toBeNull();
});

it('parses an RFC 7231 HTTP-date into a non-negative delta', function () {
    $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 120);

    $delay = RetryAfter::parse($future);

    expect($delay)->toBeGreaterThan(100.0)->toBeLessThanOrEqual(120.0);
});

it('clamps a past HTTP-date to zero', function () {
    $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 120);

    expect(RetryAfter::parse($past))->toBe(0.0);
});

it('returns null for an unparseable value', function () {
    expect(RetryAfter::parse('not-a-date-or-number'))->toBeNull();
});
