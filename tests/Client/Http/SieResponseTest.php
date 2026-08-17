<?php

declare(strict_types=1);

use MessagePack\Packer;
use MessagePack\PackOptions;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Http\SieResponse;

afterEach(fn () => MockClient::destroyGlobal());

function msgpackBody(array $data): string
{
    return (new Packer(PackOptions::FORCE_STR))->pack($data);
}

it('is the response class the connector resolves', function () {
    expect(sendMocked(MockResponse::make(['ok' => true], 200)))->toBeInstanceOf(SieResponse::class);
});

it('decodes a JSON body', function () {
    $response = sendMocked(MockResponse::make(['model' => 'bge', 'items' => []], 200, ['Content-Type' => 'application/json']));

    expect($response->decoded())->toBe(['model' => 'bge', 'items' => []]);
});

it('decodes a msgpack body', function () {
    $response = sendMocked(MockResponse::make(
        msgpackBody(['model' => 'bge', 'items' => [['id' => 'a']]]),
        200,
        ['Content-Type' => 'application/msgpack'],
    ));

    expect($response->decoded())->toBe(['model' => 'bge', 'items' => [['id' => 'a']]]);
});

it('treats a missing content type as JSON', function () {
    $response = sendMocked(MockResponse::make('{"model":"bge"}', 200, ['Content-Type' => '']));

    expect($response->decoded())->toBe(['model' => 'bge']);
});

it('reads a content type that carries a charset', function () {
    $response = sendMocked(MockResponse::make('{"model":"bge"}', 200, ['Content-Type' => 'application/json; charset=utf-8']));

    expect($response->decoded())->toBe(['model' => 'bge']);
});

it('accepts the x-msgpack spelling the server also emits', function () {
    $response = sendMocked(MockResponse::make(msgpackBody(['a' => 1]), 200, ['Content-Type' => 'application/x-msgpack']));

    expect($response->decoded())->toBe(['a' => 1]);
});

it('does not decode a JSON error body as msgpack', function () {
    // The trap: SIE answers errors in JSON even when the request was msgpack,
    // and `{` is a valid msgpack positive fixint — so unpacking a JSON error
    // body yields the integer 123 instead of throwing. Only the content type
    // saves us here, which is why nothing sniffs bytes.
    $error = '{"detail":{"code":"INVALID_INPUT","message":"Model does not support output types"}}';

    $response = sendMocked(MockResponse::make($error, 400, ['Content-Type' => 'application/json']));

    expect($response->decoded())->toBe([
        'detail' => ['code' => 'INVALID_INPUT', 'message' => 'Model does not support output types'],
    ]);
});

it('normalises msgpack-numpy arrays so both formats yield the same values', function () {
    $response = sendMocked(MockResponse::make(
        msgpackBody(['items' => [['dense' => ['values' => [
            'nd' => true, 'type' => '<f4', 'kind' => '', 'shape' => [3], 'data' => pack('g3', 1.0, 2.0, 3.0),
        ]]]]]),
        200,
        ['Content-Type' => 'application/msgpack'],
    ));

    expect($response->decoded()['items'][0]['dense']['values'])->toBe([1.0, 2.0, 3.0]);
});

it('returns an empty array for an empty body', function () {
    expect(sendMocked(MockResponse::make('', 200, ['Content-Type' => 'application/msgpack']))->decoded())->toBe([]);
});
