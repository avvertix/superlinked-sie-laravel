<?php

declare(strict_types=1);

namespace Sie\Client\Http;

use MessagePack\BufferUnpacker;
use Saloon\Http\Response;
use Sie\Client\Support\Ndarray;
use Sie\Client\Support\WireFormat;

/**
 * A response that decodes whichever format the server actually sent.
 *
 * Resolved through Saloon's `resolveResponseClass()` hook on the connector, so
 * every request gets it without any of them opting in.
 *
 * Two things make this more than a `json()` wrapper:
 *
 * 1. A request sent as msgpack does not guarantee a msgpack reply. Errors are
 *    JSON on every path, and so is every GET endpoint. The `Content-Type` is
 *    the only reliable signal, and getting it wrong is silent rather than
 *    loud — `{` is a valid msgpack positive fixint, so unpacking a JSON error
 *    body returns the integer 123 instead of throwing.
 * 2. msgpack bodies carry numpy arrays as maps of raw little-endian bytes.
 *    Normalising them here, at the boundary, is what lets every `Data` class
 *    stay unaware of which transport was used.
 */
class SieResponse extends Response
{
    /** @var array<mixed>|null */
    private ?array $decoded = null;

    /**
     * The response body as a PHP array, whatever format it arrived in.
     *
     * @return array<mixed>
     */
    public function decoded(): array
    {
        return $this->decoded ??= self::decode($this);
    }

    /**
     * Decode any Saloon response by its declared content type.
     *
     * Static so the resources can call it without every one of them having to
     * narrow its type from `Response` to this class.
     *
     * @return array<mixed>
     */
    public static function decode(Response $response): array
    {
        $body = $response->body();

        if ($body === '') {
            return [];
        }

        return self::formatOf($response) === WireFormat::Msgpack
            ? Ndarray::normalize(self::unpack($body))
            : self::asArray($response->json());
    }

    /**
     * The format this response arrived in.
     */
    public function format(): WireFormat
    {
        return self::formatOf($this);
    }

    private static function formatOf(Response $response): WireFormat
    {
        // Saloon types a header as array|string|null, since a repeated header
        // is a list. A repeated Content-Type is malformed, so anything but a
        // single string falls through to JSON like every other unknown.
        $contentType = $response->header('Content-Type');

        return WireFormat::fromContentType(is_string($contentType) ? $contentType : null);
    }

    /**
     * @return array<mixed>
     */
    private static function unpack(string $body): array
    {
        return self::asArray((new BufferUnpacker($body))->unpack());
    }

    /**
     * @return array<mixed>
     */
    private static function asArray(mixed $decoded): array
    {
        return is_array($decoded) ? $decoded : [];
    }
}
