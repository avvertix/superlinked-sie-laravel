<?php

declare(strict_types=1);

use Sie\Client\Support\ItemWireConverter;

it('converts an item\'s images list to the wire shape', function () {
    $item = ['text' => 'hello', 'images' => ["\x01\x02"]];

    $converted = ItemWireConverter::convert($item);

    expect($converted['text'])->toBe('hello');
    expect($converted['images'])->toBe([['data' => base64_encode("\x01\x02"), 'format' => null]]);
});

it('converts an item\'s document to the wire shape', function () {
    $item = ['document' => '%PDF'];

    $converted = ItemWireConverter::convert($item);

    expect($converted['document'])->toBe(['data' => base64_encode('%PDF'), 'format' => null]);
});

it('passes through items with no media untouched', function () {
    $item = ['id' => 'doc-1', 'text' => 'plain text', 'metadata' => ['k' => 'v']];

    expect(ItemWireConverter::convert($item))->toBe($item);
});

it('converts every item in a list', function () {
    $items = [['text' => 'a'], ['text' => 'b', 'images' => ["\x01"]]];

    $converted = ItemWireConverter::convertAll($items);

    expect($converted[0])->toBe(['text' => 'a']);
    expect($converted[1]['images'])->toBe([['data' => base64_encode("\x01"), 'format' => null]]);
});

it('encodes media as a JSON string, not an array of integers', function () {
    // Regression guard: msgspec decodes a `bytes` field from base64 text and
    // rejects an integer array with 400 INVALID_INPUT. Assert on the decoded
    // round-trip — PHP escapes `/` as `\/`, which is valid JSON either way.
    $roundTripped = json_decode(
        json_encode(ItemWireConverter::convert(['images' => ["\xFF\xD8\xFF"]])),
        true,
    );

    expect($roundTripped['images'][0]['data'])->toBeString()->toBe('/9j/')
        ->and(base64_decode($roundTripped['images'][0]['data'], true))->toBe("\xFF\xD8\xFF");
});
