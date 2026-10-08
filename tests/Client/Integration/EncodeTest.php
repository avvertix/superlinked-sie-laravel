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

it('encodes an image input', function () {
    $model = embeddingModel();

    $result = sieClient()->encode($model, ['images' => [tinyJpeg()]], outputTypes: ['multivector']);

    expect($result)->toBeInstanceOf(EncodeResult::class)
        ->and($result->multivector)->toBeArray()
        ->and($result->multivector)->not->toBeEmpty();
});

it('encodes text with a dense model', function () {
    $client = sieClient();

    $model = firstModelMatching(
        $client,
        ['BAAI/bge-m3'],
        static fn (ModelInfo $m): bool => in_array('dense', $m->outputs ?? [], true),
    );

    $result = $client->encode($model, ['text' => 'Hello world']);

    expect($result->dense)->toBeArray()
        ->and($result->dense)->not->toBeEmpty();
});

it('preserves batch order and returns exactly one result per input', function () {
    // Guards the Step 2 contract from the client side: encode is positional, so
    // a dropped item must never silently shift the remaining results.
    $results = sieClient()->encode(embeddingModel(), [
        ['id' => 'a', 'text' => 'first'],
        ['id' => 'b', 'text' => 'second'],
        ['id' => 'c', 'text' => 'third'],
    ], outputTypes: ['multivector']);

    expect($results)->toHaveCount(3)
        ->and(array_map(static fn (EncodeResult $r): ?string => $r->id, $results))
        ->toBe(['a', 'b', 'c']);
});
