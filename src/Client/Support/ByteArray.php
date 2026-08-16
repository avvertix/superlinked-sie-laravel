<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * Encodes raw binary (image/document `data` fields) for JSON transport.
 *
 * JSON has no binary type. The SIE gateway proxies request bodies opaquely to
 * `sie_server`, which decodes them with msgspec — and msgspec represents a
 * `bytes` field as a **standard-base64 string** on the JSON path, exactly as it
 * does on the msgpack path. An array of 0-255 integers is rejected outright:
 *
 *     ValidationError: Expected `bytes`, got `array` - at `$.data`
 *
 * which `sie_server`'s ingress turns into `400 INVALID_INPUT`.
 *
 * Do not be misled by the gateway's OpenAPI schema, which renders these fields
 * as a `Vec<u8>` integer array: those `ToSchema` structs are used for schema
 * generation only and never deserialize a request. The server-side pydantic
 * model (and the generated `build/Dto/ImageInputModel.php`) agree with msgspec
 * that the wire type is a string.
 */
final class ByteArray
{
    public static function fromBinary(string $bytes): string
    {
        return base64_encode($bytes);
    }
}
