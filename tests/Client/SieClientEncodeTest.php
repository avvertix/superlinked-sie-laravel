<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Sie\Client\Data\EncodeResult;
use Sie\Client\Exceptions\LoraLoadingException;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Encode\EncodeRequest;
use Sie\Client\SieClient;
use Sie\Client\Support\WireFormat;
use Sie\Tests\Client\Fixtures\FakeClock;
use Sie\Tests\Client\Fixtures\RecordingSleeper;

afterEach(fn () => MockClient::destroyGlobal());

it('encodes a single item and returns a single EncodeResult, unwrapping the batch', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'bge-m3',
            'items' => [
                ['dense' => ['values' => [0.1, 0.2, 0.3]]],
            ],
            'timing' => ['total_ms' => 12.5],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->encode('bge-m3', ['text' => 'Hello world']);

    expect($result)->toBeInstanceOf(EncodeResult::class);
    expect($result->dense)->toBe([0.1, 0.2, 0.3]);
    expect($result->timing)->toBe(['total_ms' => 12.5]);
});

it('encodes a batch of items and returns a list of EncodeResult', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'bge-m3',
            'items' => [
                ['dense' => ['values' => [0.1]]],
                ['dense' => ['values' => [0.2]]],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $results = $client->encode('bge-m3', [['text' => 'a'], ['text' => 'b']]);

    expect($results)->toBeArray()->toHaveCount(2);
    expect($results[0]->dense)->toBe([0.1]);
    expect($results[1]->dense)->toBe([0.2]);
});

it('parses sparse and multivector outputs', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'bge-m3',
            'items' => [[
                'sparse' => ['indices' => [1, 5], 'values' => [0.5, 0.9]],
                'multivector' => ['values' => [[0.1, 0.2], [0.3, 0.4]]],
            ]],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->encode('colbert', ['text' => 'x']);

    expect($result->sparse->indices)->toBe([1, 5]);
    expect($result->sparse->values)->toBe([0.5, 0.9]);
    expect($result->multivector)->toBe([[0.1, 0.2], [0.3, 0.4]]);
});

it('sends the model/gpu/pool/params/options exactly as built, over JSON', function () {
    $mock = MockClient::global([
        MockResponse::make(['model' => 'bge-m3', 'items' => [['dense' => ['values' => [1.0]]]]], 200),
    ]);

    $client = new SieClient('https://sie.test', gpu: 'default-gpu');
    $client->encode(
        'bge-m3',
        ['text' => 'hi'],
        outputTypes: ['dense', 'sparse'],
        instruction: 'retrieve',
        isQuery: true,
        options: ['normalize' => true],
        gpu: 'eval-bench/l4',
    );

    $mock->assertSent(function (EncodeRequest $request, Response $response): bool {
        expect($request->resolveEndpoint())->toBe('/v1/encode/bge-m3');
        // Content-Type/Accept come from the connector's defaults, only visible on the merged PendingRequest.
        $mergedHeaders = $response->getPendingRequest()->headers();
        expect($mergedHeaders->get('Content-Type'))->toBe('application/msgpack');
        expect($mergedHeaders->get('X-SIE-MACHINE-PROFILE'))->toBe('l4');
        expect($mergedHeaders->get('X-SIE-Pool'))->toBe('eval-bench');

        $body = $request->body()->all();
        expect($body['items'])->toBe([['text' => 'hi']]);
        expect($body['params']['output_types'])->toBe(['dense', 'sparse']);
        expect($body['params']['instruction'])->toBe('retrieve');
        expect($body['params']['options'])->toBe(['normalize' => true, 'is_query' => true]);

        return true;
    });
});

it('sends image items as a native msgpack bin', function () {
    $mock = MockClient::global([
        MockResponse::make(['model' => 'clip', 'items' => [['dense' => ['values' => [1.0]]]]], 200),
    ]);

    (new SieClient('https://sie.test'))->encode('clip', ['images' => ["\x01\x02"]]);

    $mock->assertSent(function (EncodeRequest $request): bool {
        // 0xc4 0x02 is bin8 of length two. A base64 string here is a 400.
        expect(bin2hex((string) $request->body()))->toContain('c4020102');

        return true;
    });
});

it('sends image items as base64 when the connection speaks JSON', function () {
    $mock = MockClient::global([
        MockResponse::make(['model' => 'clip', 'items' => [['dense' => ['values' => [1.0]]]]], 200),
    ]);

    (new SieClient('https://sie.test', format: WireFormat::Json))->encode('clip', ['images' => ["\x01\x02"]]);

    $mock->assertSent(function (EncodeRequest $request, Response $response): bool {
        expect($response->getPendingRequest()->headers()->get('Content-Type'))->toBe('application/json');
        expect((string) $request->body())->toContain('"data":"'.base64_encode("\x01\x02").'"');

        return true;
    });
});

it('retries 503 LORA_LOADING for encode (the one endpoint that supports it)', function () {
    $responses = array_fill(0, 11, MockResponse::make(['error' => ['code' => 'LORA_LOADING']], 503));
    $mock = MockClient::global($responses);

    $clock = new FakeClock;
    $client = new SieClient('https://sie.test', clock: $clock, sleeper: new RecordingSleeper($clock));

    expect(fn () => $client->encode('bge-m3', ['text' => 'x'], provisionTimeoutS: 3600.0))
        ->toThrow(LoraLoadingException::class);

    $mock->assertSentCount(11);
});

it('raises a typed error when the server drops an item from a batch', function () {
    // The gateway returns mixed-success batches as 200 carrying only the
    // successful items, so a per-item failure (e.g. an input over the model's
    // max_sequence_length) is silently absent rather than an error envelope.
    MockClient::global([
        MockResponse::make([
            'model' => 'bge-m3',
            'items' => [
                ['dense' => ['values' => [0.1]]],
                ['dense' => ['values' => [0.2]]],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->encode('bge-m3', [['text' => 'a'], ['text' => 'b'], ['text' => 'c']]))
        ->toThrow(
            ServerException::class,
            "Encode response desync for model 'bge-m3': server returned 2 embedding(s) for 3 input item(s)",
        );
});

it('tags the desync error with ENCODE_RESULT_COUNT_MISMATCH', function () {
    MockClient::global([
        MockResponse::make(['model' => 'bge-m3', 'items' => []], 200),
    ]);

    $client = new SieClient('https://sie.test');

    try {
        $client->encode('bge-m3', ['text' => 'a']);
        expect()->fail('Expected a ServerException.');
    } catch (ServerException $e) {
        expect($e->errorCode)->toBe('ENCODE_RESULT_COUNT_MISMATCH');
    }
});

it('raises rather than returning extra results when the server over-returns', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'bge-m3',
            'items' => [
                ['dense' => ['values' => [0.1]]],
                ['dense' => ['values' => [0.2]]],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->encode('bge-m3', ['text' => 'only one']))
        ->toThrow(ServerException::class, 'server returned 2 embedding(s) for 1 input item(s)');
});

it('accepts a matching count for both single and batch requests', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'bge-m3',
            'items' => [
                ['id' => 'a', 'dense' => ['values' => [0.1]]],
                ['id' => 'b', 'dense' => ['values' => [0.2]]],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $results = $client->encode('bge-m3', [['text' => 'a'], ['text' => 'b']]);

    expect($results)->toHaveCount(2)
        ->and(array_map(static fn (EncodeResult $r): ?string => $r->id, $results))->toBe(['a', 'b']);
});

it('injects the envelope model into every result in the batch', function () {
    // The envelope model is the id that actually served the request, which can
    // differ from the one requested via alias/profile resolution.
    MockClient::global([
        MockResponse::make([
            'model' => 'BAAI/bge-m3',
            'items' => [
                ['dense' => ['values' => [0.1]]],
                ['dense' => ['values' => [0.2]]],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $results = $client->encode('fast', [['text' => 'a'], ['text' => 'b']]);

    expect(array_map(static fn (EncodeResult $r): ?string => $r->model, $results))
        ->toBe(['BAAI/bge-m3', 'BAAI/bge-m3']);
});

it('leaves the result model null when the envelope omits it', function () {
    MockClient::global([
        MockResponse::make(['items' => [['dense' => ['values' => [0.1]]]]], 200),
    ]);

    $client = new SieClient('https://sie.test');

    expect($client->encode('bge-m3', ['text' => 'a'])->model)->toBeNull();
});

it('attaches request metadata to every result in the batch', function () {
    MockClient::global([
        MockResponse::make(
            ['model' => 'bge-m3', 'items' => [['dense' => ['values' => [0.1]]], ['dense' => ['values' => [0.2]]]]],
            200,
            ['X-SIE-Request-ID' => 'req-42'],
        ),
    ]);

    $client = new SieClient('https://sie.test');
    $results = $client->encode('bge-m3', [['text' => 'a'], ['text' => 'b']]);

    // Request-scoped: the same metadata rides every item, describing the whole
    // HTTP request rather than one item.
    expect($results[0]->request?->id)->toBe('req-42')
        ->and($results[1]->request?->id)->toBe('req-42');
});

it('attaches request metadata to a raised error', function () {
    MockClient::global([
        MockResponse::make(
            ['error' => ['code' => 'INVALID_INPUT', 'message' => 'bad']],
            400,
            ['X-SIE-Request-ID' => 'req-err'],
        ),
    ]);

    $client = new SieClient('https://sie.test');

    try {
        $client->encode('bge-m3', ['text' => 'a']);
        expect()->fail('Expected a RequestException.');
    } catch (RequestException $e) {
        expect($e->request?->id)->toBe('req-err');
    }
});
