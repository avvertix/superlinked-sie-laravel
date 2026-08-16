<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use DateTimeImmutable;

/**
 * Parses a `Retry-After` header value into seconds.
 *
 * Accepts either a numeric seconds value or an RFC 7231 HTTP-date. A
 * non-finite / negative numeric value or an unparseable date is treated as
 * "no usable hint" and returns `null` so callers fall back to their own
 * default delay, mirroring the Python SDK's `get_retry_after`.
 */
final class RetryAfter
{
    public static function parse(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $seconds = (float) $value;

            return is_finite($seconds) && $seconds >= 0 ? $seconds : null;
        }

        $when = self::parseHttpDate($value);

        if ($when === null) {
            return null;
        }

        return max((float) ($when->getTimestamp() - time()), 0.0);
    }

    private static function parseHttpDate(string $value): ?DateTimeImmutable
    {
        // Literal RFC 7231 format string (rather than the DateTimeInterface::RFC7231
        // constant, deprecated in PHP 8.5 since it always assumes GMT).
        $date = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value);

        if ($date !== false) {
            return $date;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp($timestamp);
    }
}
