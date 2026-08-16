<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * Best-effort port of `is_transient_connect_error`: is a pre-send connect
 * failure worth retrying under `waitForCapacity`?
 *
 * Python inspects the wrapped `OSError.errno` against a fixed transient set
 * and treats SSL errors as never transient, defaulting to "retryable" when
 * unclassifiable. Guzzle's curl handler exposes a comparable `errno` via
 * `ConnectException::getHandlerContext()['errno']`; we mirror the same
 * fail-open default when that context isn't available (e.g. a non-curl
 * handler, or a handler that doesn't populate it).
 */
final class ConnectionErrorClassifier
{
    /** CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR */
    private const TRANSIENT_CURL_ERRNOS = [6, 7, 28, 52, 55, 56];

    /** CURLE_SSL_* range */
    private const SSL_CURL_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 66, 77, 82, 83, 90, 91];

    public static function isTransient(Throwable $exception): bool
    {
        $errno = self::curlErrno($exception);

        if ($errno !== null) {
            if (in_array($errno, self::SSL_CURL_ERRNOS, true)) {
                return false;
            }

            if (in_array($errno, self::TRANSIENT_CURL_ERRNOS, true)) {
                return true;
            }
        }

        if (str_contains(strtolower($exception->getMessage()), 'ssl')) {
            return false;
        }

        return true;
    }

    private static function curlErrno(Throwable $exception): ?int
    {
        if (! $exception instanceof ConnectException) {
            return null;
        }

        $errno = $exception->getHandlerContext()['errno'] ?? null;

        return is_int($errno) ? $errno : null;
    }
}
