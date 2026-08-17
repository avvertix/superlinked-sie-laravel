<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Stringable;

/**
 * Raw bytes destined for a `bytes` field on the wire.
 *
 * The marker exists because the two formats disagree about how such a field
 * travels, and the difference is not detectable from the bytes themselves:
 *
 *   - JSON wants standard base64 (`data: "JVBERg=="`)
 *   - msgpack wants a native bin (`data: <bin8 …>`)
 *
 * and msgspec rejects the other one outright — a base64 string on the msgpack
 * path fails with ``Expected `bytes`, got `str` ``. Sniffing cannot decide it
 * either: a markdown or CSV document is valid UTF-8, so a "is it text?" check
 * would pack it as a string and the request would be rejected.
 *
 * So the input layer marks bytes as bytes and the body repository, which is the
 * only thing that knows the format, encodes them accordingly.
 */
final class Binary implements Stringable
{
    public function __construct(public readonly string $bytes) {}

    public function __toString(): string
    {
        return $this->bytes;
    }
}
