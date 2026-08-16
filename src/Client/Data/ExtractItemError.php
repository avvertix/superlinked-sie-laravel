<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * Stable per-item extraction failure.
 *
 * Extract batches are mixed-success: an item the server could not process is
 * returned alongside the successful ones carrying this instead of results.
 * Without it a failed item is indistinguishable from one that legitimately
 * matched nothing.
 *
 * Port of `ExtractItemErrorDetail`.
 */
final class ExtractItemError
{
    /**
     * Substituted when the server sends an `error` whose `code`/`message` are
     * missing, non-string, or blank — the item still failed, so it must not be
     * silently downgraded to "no error".
     */
    public const MALFORMED_CODE = 'INTERNAL_ERROR';

    public const MALFORMED_MESSAGE = 'Malformed extraction item error';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {}

    /**
     * @param  mixed  $error  The raw `error` member; any shape the server might send.
     */
    public static function fromWire(mixed $error): self
    {
        $code = is_array($error) ? ($error['code'] ?? null) : null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        if (! is_string($code) || trim($code) === '' || ! is_string($message) || trim($message) === '') {
            return new self(self::MALFORMED_CODE, self::MALFORMED_MESSAGE);
        }

        return new self($code, $message);
    }
}
