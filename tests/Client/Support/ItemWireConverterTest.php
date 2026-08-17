<?php

declare(strict_types=1);

use MessagePack\BufferUnpacker;
use Sie\Client\Body\SieBodyRepository;
use Sie\Client\Support\ItemWireConverter;
use Sie\Client\Support\WireFormat;

it('converts an item\'s images list to the wire shape', function () {
    $item = ['text' => 'hello', 'images' => ["\x01\x02"]];

    $converted = ItemWireConverter::convert($item);

    expect($converted['text'])->toBe('hello');
    expect($converted['images'][0]['data']->bytes)->toBe("\x01\x02");
    expect($converted['images'][0]['format'])->toBeNull();
});

it('converts an item\'s document to the wire shape', function () {
    $item = ['document' => '%PDF'];

    $converted = ItemWireConverter::convert($item);

    expect($converted['document']['data']->bytes)->toBe('%PDF');
    expect($converted['document']['format'])->toBeNull();
});

it('passes through items with no media untouched', function () {
    $item = ['id' => 'doc-1', 'text' => 'plain text', 'metadata' => ['k' => 'v']];

    expect(ItemWireConverter::convert($item))->toBe($item);
});

it('converts every item in a list', function () {
    $items = [['text' => 'a'], ['text' => 'b', 'images' => ["\x01"]]];

    $converted = ItemWireConverter::convertAll($items);

    expect($converted[0])->toBe(['text' => 'a']);
    expect($converted[1]['images'][0]['data']->bytes)->toBe("\x01");
});

it('encodes media as a JSON string, not an array of integers', function () {
    // Regression guard: msgspec decodes a `bytes` field from base64 text on the
    // JSON path and rejects an integer array with 400 INVALID_INPUT. The
    // encoding now happens in the body repository, so assert through it.
    $body = new SieBodyRepository(ItemWireConverter::convert(['images' => ["\xFF\xD8\xFF"]]));

    /** @var array<string, mixed> $roundTripped */
    $roundTripped = json_decode((string) $body, true);

    expect($roundTripped['images'][0]['data'])->toBeString()->toBe('/9j/')
        ->and(base64_decode($roundTripped['images'][0]['data'], true))->toBe("\xFF\xD8\xFF");
});

it('encodes media as a native bin on the msgpack path', function () {
    // The mirror of the guard above: msgspec rejects a base64 *string* here
    // with "Expected `bytes`, got `str`", so the same item must serialise
    // differently depending on the format.
    $body = (new SieBodyRepository(ItemWireConverter::convert(['images' => ["\xFF\xD8\xFF"]])))
        ->useFormat(WireFormat::Msgpack);

    $decoded = (new BufferUnpacker((string) $body))->unpack();

    expect($decoded['images'][0]['data'])->toBe("\xFF\xD8\xFF");
    expect(bin2hex((string) $body))->toContain('c403ffd8ff');
});
