<?php

declare(strict_types=1);

use Sie\Client\Data\EncodeResult;
use Sie\Client\Data\ModelInfo;

/*
 * The test that would have caught the base64 defect (Step 1).
 *
 * Binary media `data` must reach the server as a base64 string: the gateway
 * proxies the body opaquely to sie_server, which decodes with msgspec, and
 * msgspec rejects an array for a `bytes` field with
 * `400 INVALID_INPUT: Expected \`bytes\`, got \`array\` - at $.…data`.
 */

/**
 * A minimal 8x8 RGB JPEG, so the server has real bytes to decode. It must have
 * three colour components: image processors normalise with a 3-value RGB mean,
 * and a grayscale JPEG fails with "mean must have 1 elements if it is an
 * iterable, got 3".
 */
function tinyJpeg(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK'
        .'/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU'
        .'/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5KiiivxQ/qg//9k=',
        true,
    );
}

it('encodes an image input', function () {
    $client = sieClient();

    $model = firstModelMatching(
        $client,
        ['Marqo/marqo-fashionSigLIP', 'Qwen/Qwen3-VL-Embedding-2B', 'google/siglip-so400m-patch14-384'],
        static fn (ModelInfo $m): bool => in_array('image', $m->inputs ?? [], true),
    );

    $result = $client->encode($model, ['images' => [tinyJpeg()]]);

    expect($result)->toBeInstanceOf(EncodeResult::class)
        ->and($result->dense)->toBeArray()
        ->and($result->dense)->not->toBeEmpty();
});

it('preserves batch order and returns exactly one result per input', function () {
    // Guards the Step 2 contract from the client side: encode is positional, so
    // a dropped item must never silently shift the remaining results.
    $results = sieClient()->encode('BAAI/bge-m3', [
        ['id' => 'a', 'text' => 'first'],
        ['id' => 'b', 'text' => 'second'],
        ['id' => 'c', 'text' => 'third'],
    ]);

    expect($results)->toHaveCount(3)
        ->and(array_map(static fn (EncodeResult $r): ?string => $r->id, $results))
        ->toBe(['a', 'b', 'c']);
});

it('extracts entities from a document input', function () {
    $client = sieClient();

    $model = firstModelMatching(
        $client,
        ['docling:ocr', 'docling'],
        static fn (ModelInfo $m): bool => in_array('document', $m->inputs ?? [], true),
    );

    $pdf = new SplFileInfo(__DIR__.'/../Fixtures/sample.pdf');

    $result = $client->extract($model, ['document' => $pdf]);

    // The assertion that matters is that this did not 400 — the extracted
    // content itself depends on which OCR recogniser the instance serves.
    expect($result->entities)->toBeArray();
});
