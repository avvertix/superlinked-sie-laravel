<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * The serialization format for a request body and a response body.
 *
 * SIE speaks both. msgpack is what it prefers — a POST to an inference endpoint
 * with no `Accept` header answers in msgpack, and encode payloads are several
 * times smaller for it because vectors travel as raw little-endian buffers
 * instead of decimal text. JSON stays available for debugging and for
 * intermediaries that mangle binary bodies.
 */
enum WireFormat: string
{
    case Json = 'json';

    case Msgpack = 'msgpack';

    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Msgpack => 'application/msgpack',
        };
    }

    /**
     * The format a response is in, from its `Content-Type`.
     *
     * Anything unrecognised — including a missing header — is JSON. That is
     * not a guess: errors and every GET endpoint answer in JSON whatever the
     * request asked for, and decoding JSON as msgpack fails silently rather
     * than loudly (`{` is a valid msgpack positive fixint, so an error body
     * decodes to the integer 123).
     */
    public static function fromContentType(?string $contentType): self
    {
        if ($contentType === null) {
            return self::Json;
        }

        $normalized = strtolower($contentType);

        // The server accepts and emits both spellings.
        return str_contains($normalized, 'application/msgpack') || str_contains($normalized, 'application/x-msgpack')
            ? self::Msgpack
            : self::Json;
    }

    /**
     * The format named in configuration, defaulting to msgpack.
     */
    public static function fromName(?string $name): self
    {
        return self::tryFrom(strtolower((string) $name)) ?? self::Msgpack;
    }
}
