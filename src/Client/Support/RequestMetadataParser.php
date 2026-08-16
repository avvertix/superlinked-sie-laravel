<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Saloon\Http\Response;
use Sie\Client\Data\RequestMetadata;

/**
 * Parses request-scoped metadata from one terminal response.
 *
 * Partial port of `parse_request_metadata`, covering the fields the gateway
 * actually emits. Fields validate independently and a malformed one is simply
 * omitted — metadata is best-effort diagnostics and must never turn an
 * otherwise good response into a failure.
 */
final class RequestMetadataParser
{
    /** Generous ceiling; the gateway currently sends a 36-char UUIDv7. */
    private const MAX_REQUEST_ID_LENGTH = 256;

    /** Returns `null` when nothing valid was present, so callers can skip attaching. */
    public static function parse(Response $response): ?RequestMetadata
    {
        $metadata = new RequestMetadata(
            id: self::parseRequestId(self::stringHeader($response, ErrorCodes::REQUEST_ID_HEADER)),
        );

        return $metadata->isEmpty() ? null : $metadata;
    }

    /**
     * A request id must be non-empty printable ASCII, at most 256 characters,
     * with no surrounding whitespace.
     *
     * The strictness is deliberate: this value gets echoed into logs and
     * support tickets, so a control character or an unbounded blob from a
     * misbehaving intermediary must not travel with it.
     */
    private static function parseRequestId(?string $value): ?string
    {
        if ($value === null || $value !== trim($value)) {
            return null;
        }

        if (strlen($value) > self::MAX_REQUEST_ID_LENGTH) {
            return null;
        }

        // 0x20-0x7E: printable ASCII. Rules out control characters, and any
        // multi-byte sequence, without needing a separate encoding check.
        return preg_match('/^[\x20-\x7E]+$/', $value) === 1 ? $value : null;
    }

    private static function stringHeader(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
