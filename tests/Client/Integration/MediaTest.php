<?php

declare(strict_types=1);

use Sie\Client\Data\EncodeResult;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Exceptions\ModelLoadFailedException;

/*
 * The test that would have caught the base64 defect (Step 1).
 *
 * Binary media `data` must reach the server as a base64 string: the gateway
 * proxies the body opaquely to sie_server, which decodes with msgspec, and
 * msgspec rejects an array for a `bytes` field with
 * `400 INVALID_INPUT: Expected \`bytes\`, got \`array\` - at $.…data`.
 *
 * Dispatches real inference, so it is opt-in via SIE_RUN_BILLABLE=1.
 */

/** A minimal but structurally valid 1x1 JPEG, so the server has real bytes to decode. */
function tinyJpeg(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
        .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==',
        true,
    );
}

it('encodes an image input', function () {
    requiresBillableCalls();

    $client = sieClient();

    $model = firstModelMatching(
        $client,
        ['Marqo/marqo-fashionSigLIP', 'Qwen/Qwen3-VL-Embedding-2B'],
        static fn (ModelInfo $m): bool => in_array('image', $m->inputs ?? [], true),
    );

    $result = $client->encode($model, ['images' => [tinyJpeg()]]);

    expect($result)->toBeInstanceOf(EncodeResult::class)
        ->and($result->dense)->toBeArray()
        ->and($result->dense)->not->toBeEmpty();
})->group('billable');

it('preserves batch order and returns exactly one result per input', function () {
    requiresBillableCalls();

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
})->group('billable');

it('extracts entities from a document input', function () {
    requiresBillableCalls();

    $client = sieClient();

    $model = firstModelMatching(
        $client,
        ['docling:ocr'],
        static fn (ModelInfo $m): bool => in_array('document', $m->inputs ?? [], true),
    );

    $pdf = new SplFileInfo(__DIR__.'/../Fixtures/sample.pdf');

    // try {
    $result = $client->extract($model, ['document' => $pdf]);
    // } catch (ModelLoadFailedException $e) {
    //     // Reaching model loading already proves the point of this test: the
    //     // base64 document payload cleared the server's body validation, which
    //     // is where an integer-array `data` is rejected with 400 INVALID_INPUT.
    //     // Whether this deployment can actually load the model is not our bug.
    //     // test()->markTestSkipped("'{$model}' cannot load on this instance: {$e->getMessage()}");
    // }

    // The assertion that matters is that this did not 400 — the extracted
    // content itself depends on which OCR recogniser the instance serves.
    expect($result->entities)->toBeArray();
})->group('billable');
