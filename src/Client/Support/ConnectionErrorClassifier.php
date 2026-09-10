<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Best-effort port of `is_transient_connect_error`: is a pre-send connect
 * failure worth retrying under `waitForCapacity`?
 *
 * Python inspects the wrapped `OSError.errno` against a fixed transient set
 * and treats SSL errors as never transient, defaulting to "retryable" when
 * unclassifiable. Guzzle's curl handler exposes a comparable `errno`: Guzzle 7
 * through `ConnectException::getHandlerContext()['errno']`, and Guzzle 8 —
 * which removed the handler context in favour of finer-grained exception
 * classes — through the `cURL error <errno>:` prefix both majors put on the
 * exception message. We mirror the same fail-open default when neither is
 * available (e.g. a non-curl handler, or a handler that doesn't populate them).
 */
final class ConnectionErrorClassifier
{
    /** CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR */
    private const array TRANSIENT_CURL_ERRNOS = [6, 7, 28, 52, 55, 56];

    /** CURLE_SSL_* range */
    private const array SSL_CURL_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 66, 77, 82, 83, 90, 91];

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
        if (! $exception instanceof NetworkExceptionInterface) {
            return null;
        }

        if (method_exists($exception, 'getHandlerContext')) {
            $context = $exception->getHandlerContext();
            $errno = is_array($context) ? ($context['errno'] ?? null) : null;

            if (is_int($errno)) {
                return $errno;
            }
        }

        return preg_match('/^cURL error (\d+):/', $exception->getMessage(), $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
