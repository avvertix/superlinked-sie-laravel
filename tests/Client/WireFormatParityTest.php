<?php

declare(strict_types=1);

use MessagePack\Packer;
use MessagePack\PackOptions;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\SieClient;
use Sie\Client\Support\WireFormat;

afterEach(fn () => MockClient::destroyGlobal());

/**
 * The same encode response, expressed the way each transport really sends it:
 * JSON as decimal numbers, msgpack as msgpack-numpy arrays over raw
 * little-endian buffers.
 */
function jsonEncodeResponse(): MockResponse
{
    return MockResponse::make([
        'model' => 'BAAI/bge-m3',
        'items' => [[
            'id' => 'a',
            'dense' => ['dims' => 3, 'dtype' => 'float32', 'values' => [0.5, -1.5, 2.25]],
            'sparse' => ['indices' => [5, 9], 'values' => [0.25, 0.75]],
            'multivector' => ['values' => [[1.5, 2.5], [3.5, 4.5]]],
        ]],
        'timing' => ['total_ms' => 12.5],
    ], 200, ['Content-Type' => 'application/json']);
}

function msgpackEncodeResponse(): MockResponse
{
    $nd = static fn (string $type, array $shape, string $data): array => [
        'nd' => true, 'type' => $type, 'kind' => '', 'shape' => $shape, 'data' => $data,
    ];

    $body = (new Packer(PackOptions::FORCE_STR))->pack([
        'model' => 'BAAI/bge-m3',
        'items' => [[
            'id' => 'a',
            'dense' => ['dims' => 3, 'dtype' => 'float32', 'values' => $nd('<f4', [3], pack('g3', 0.5, -1.5, 2.25))],
            'sparse' => [
                'indices' => $nd('<i4', [2], pack('V2', 5, 9)),
                'values' => $nd('<f4', [2], pack('g2', 0.25, 0.75)),
            ],
            'multivector' => ['values' => $nd('<f4', [2, 2], pack('g4', 1.5, 2.5, 3.5, 4.5))],
        ]],
        'timing' => ['total_ms' => 12.5],
    ]);

    return MockResponse::make($body, 200, ['Content-Type' => 'application/msgpack']);
}

it('produces identical results whichever format the connection speaks', function () {
    MockClient::global([jsonEncodeResponse()]);
    $viaJson = (new SieClient('https://sie.test', format: WireFormat::Json))->encode('BAAI/bge-m3', ['text' => 'hi']);
    MockClient::destroyGlobal();

    MockClient::global([msgpackEncodeResponse()]);
    $viaMsgpack = (new SieClient('https://sie.test', format: WireFormat::Msgpack))->encode('BAAI/bge-m3', ['text' => 'hi']);

    // Every value a caller can observe has to match, or the format would not be
    // a transport detail — it would be a behaviour change.
    expect($viaMsgpack->dense)->toBe($viaJson->dense)->toBe([0.5, -1.5, 2.25]);
    expect($viaMsgpack->sparse->indices)->toBe($viaJson->sparse->indices);
    expect($viaMsgpack->sparse->values)->toBe($viaJson->sparse->values);
    expect($viaMsgpack->multivector)->toBe($viaJson->multivector)->toBe([[1.5, 2.5], [3.5, 4.5]]);
    expect($viaMsgpack->id)->toBe($viaJson->id);
    expect($viaMsgpack->model)->toBe($viaJson->model);
    expect($viaMsgpack->timing)->toBe($viaJson->timing);
});

it('reads a msgpack reply even when the request went out as JSON', function () {
    // Content negotiation is per response, not per request: the server decides,
    // and we follow its Content-Type rather than what we asked for.
    MockClient::global([msgpackEncodeResponse()]);

    $result = (new SieClient('https://sie.test', format: WireFormat::Json))->encode('BAAI/bge-m3', ['text' => 'hi']);

    expect($result->dense)->toBe([0.5, -1.5, 2.25]);
});

it('reads a JSON error even when the connection speaks msgpack', function () {
    MockClient::global([
        MockResponse::make(
            ['detail' => ['code' => 'INVALID_INPUT', 'message' => "Model 'docling' does not support output types"]],
            400,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    expect(fn () => (new SieClient('https://sie.test'))->encode('docling', ['text' => 'hi']))
        ->toThrow(RequestException::class, 'does not support output types');
});

it('is more faithful than JSON for whole-numbered floats', function () {
    // A known and unavoidable asymmetry, recorded rather than hidden: JSON has
    // no float/int distinction, so a float32 of exactly 1.0 is written as `1`
    // and decodes to int(1). msgpack carries the dtype, so the same value stays
    // float(1.0). msgpack is the faithful one; real embeddings are never
    // exactly whole, which is why the fixtures above use fractional values.
    MockClient::global([MockResponse::make([
        'model' => 'm',
        'items' => [['dense' => ['values' => [1.0, 2.0]]]],
    ], 200, ['Content-Type' => 'application/json'])]);
    $viaJson = (new SieClient('https://sie.test', format: WireFormat::Json))->encode('m', ['text' => 'hi']);
    MockClient::destroyGlobal();

    $nd = ['nd' => true, 'type' => '<f4', 'kind' => '', 'shape' => [2], 'data' => pack('g2', 1.0, 2.0)];
    MockClient::global([MockResponse::make(
        (new Packer(PackOptions::FORCE_STR))->pack(['model' => 'm', 'items' => [['dense' => ['values' => $nd]]]]),
        200,
        ['Content-Type' => 'application/msgpack'],
    )]);
    $viaMsgpack = (new SieClient('https://sie.test'))->encode('m', ['text' => 'hi']);

    expect($viaJson->dense)->toBe([1, 2]);            // JSON loses the type
    expect($viaMsgpack->dense)->toBe([1.0, 2.0]);     // msgpack keeps it
    expect($viaMsgpack->dense == $viaJson->dense)->toBeTrue();
});
