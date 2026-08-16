<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Generator;
use Psr\Http\Message\StreamInterface;

/**
 * Parses the gateway's simplified SSE emission — one single-line `data:
 * <json>` per event, plus a literal `data: [DONE]` terminator — into a
 * stream of raw JSON payload strings. Direct port of `_sse.py`'s
 * `iter_sse_payloads`.
 *
 * Not a general SSE parser: it does not handle `event:`/`id:`/`retry:`
 * fields or multi-line `data:` continuations, because the gateway never
 * emits them (matches the Python SDK's own scope).
 */
final class SseStream
{
    /**
     * @return Generator<int, string>
     */
    public static function payloads(StreamInterface $stream): Generator
    {
        foreach (self::lines($stream) as $line) {
            $payload = self::extractDataPayload($line);

            if ($payload === null) {
                continue;
            }

            if ($payload === '[DONE]') {
                return;
            }

            yield $payload;
        }
    }

    /**
     * @return Generator<int, string>
     */
    private static function lines(StreamInterface $stream): Generator
    {
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                yield rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
            }
        }

        if ($buffer !== '') {
            yield rtrim($buffer, "\r");
        }
    }

    private static function extractDataPayload(string $line): ?string
    {
        if ($line === '' || str_starts_with($line, ':')) {
            return null;
        }

        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $payload = substr($line, 5);

        if (str_starts_with($payload, ' ')) {
            $payload = substr($payload, 1);
        }

        return $payload;
    }
}
