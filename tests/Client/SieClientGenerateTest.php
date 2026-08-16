<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\GenerateResult;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Generate\GenerateRequest;
use Sie\Client\Requests\Generate\StreamGenerateRequest;
use Sie\Client\SieClient;

afterEach(fn () => MockClient::destroyGlobal());

it('generates text and parses the result', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'llama-3',
            'text' => 'Hello there',
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7],
            'attempt_id' => 'a-1',
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->generate('meta/llama-3', 'Say hi', maxNewTokens: 16);

    expect($result)->toBeInstanceOf(GenerateResult::class);
    expect($result->text)->toBe('Hello there');
    expect($result->finishReason)->toBe('stop');
    expect($result->usage->totalTokens)->toBe(7);
    expect($result->attemptId)->toBe('a-1');
});

it('mangles the model path to the SIE-safe double-underscore form', function () {
    $mock = MockClient::global([
        MockResponse::make(['model' => 'meta/llama-3', 'text' => 'x', 'finish_reason' => 'stop', 'attempt_id' => 'a'], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->generate('meta/llama-3', 'hi', maxNewTokens: 4);

    $mock->assertSent(function (GenerateRequest $request): bool {
        expect($request->resolveEndpoint())->toBe('/v1/generate/meta__llama-3');

        return true;
    });
});

it('raises a RequestException when the response is missing a string model/text field', function () {
    MockClient::global([MockResponse::make(['model' => 'llama-3'], 200)]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->generate('llama-3', 'hi', maxNewTokens: 4))
        ->toThrow(RequestException::class, "missing string 'text' field");
});

it('treats a 504 as terminal with a non-idempotent-specific message (no retry)', function () {
    $mock = MockClient::global([MockResponse::make([], 504)]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->generate('llama-3', 'hi', maxNewTokens: 4))
        ->toThrow(ServerException::class, 'non-idempotent');

    $mock->assertSentCount(1);
});

it('streams generate chunks and stops on [DONE]', function () {
    $sse = 'data: {"request_id":"r-1","seq":0,"text_delta":"Hel"}'."\n\n"
        .'data: {"request_id":"r-1","seq":1,"text_delta":"lo","done":true,"finish_reason":"stop"}'."\n\n"
        .'data: [DONE]'."\n\n";

    MockClient::global([MockResponse::make($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $client = new SieClient('https://sie.test');
    $chunks = iterator_to_array($client->streamGenerate('llama-3', 'hi', maxNewTokens: 4));

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]->textDelta)->toBe('Hel');
    expect($chunks[1]->done)->toBeTrue();
    expect($chunks[1]->finishReason)->toBe('stop');
});

it('raises a ServerException on a mid-stream error chunk', function () {
    $sse = 'data: {"error":{"code":"WORKER_CRASHED","message":"worker died"}}'."\n\n";

    MockClient::global([MockResponse::make($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $client = new SieClient('https://sie.test');

    expect(fn () => iterator_to_array($client->streamGenerate('llama-3', 'hi', maxNewTokens: 4)))
        ->toThrow(ServerException::class, 'worker died');
});

it('omits temperature and top_p when the caller sets neither', function () {
    // The gateway applies the selected model profile's own sampling defaults
    // for anything absent; sending a hardcoded 1.0 silently overrode them.
    $mock = MockClient::global([
        MockResponse::make(['model' => 'llama-3', 'text' => 'x', 'finish_reason' => 'stop'], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->generate('llama-3', 'hi', maxNewTokens: 4);

    $mock->assertSent(function (GenerateRequest $request): bool {
        $body = $request->body()->all();

        expect($body)->not->toHaveKey('temperature')
            ->and($body)->not->toHaveKey('top_p')
            ->and($body['prompt'])->toBe('hi')
            ->and($body['max_new_tokens'])->toBe(4);

        return true;
    });
});

it('sends temperature and top_p when the caller sets them', function () {
    $mock = MockClient::global([
        MockResponse::make(['model' => 'llama-3', 'text' => 'x', 'finish_reason' => 'stop'], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->generate('llama-3', 'hi', maxNewTokens: 4, temperature: 0.7, topP: 0.9);

    $mock->assertSent(function (GenerateRequest $request): bool {
        $body = $request->body()->all();

        expect($body['temperature'])->toBe(0.7)->and($body['top_p'])->toBe(0.9);

        return true;
    });
});

it('preserves an explicit zero temperature', function () {
    // 0.0 is meaningful (greedy decoding) and must survive the omit-if-unset
    // filter, which keys off null rather than falsiness.
    $mock = MockClient::global([
        MockResponse::make(['model' => 'llama-3', 'text' => 'x', 'finish_reason' => 'stop'], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->generate('llama-3', 'hi', maxNewTokens: 4, temperature: 0.0, topP: 0.0);

    $mock->assertSent(function (GenerateRequest $request): bool {
        $body = $request->body()->all();

        expect($body['temperature'])->toBe(0.0)->and($body['top_p'])->toBe(0.0);

        return true;
    });
});

it('omits unset sampling params on the streaming path too', function () {
    $mock = MockClient::global([
        MockResponse::make("data: {\"text_delta\":\"hi\"}\n\ndata: [DONE]\n\n", 200),
    ]);

    $client = new SieClient('https://sie.test');
    iterator_to_array($client->streamGenerate('llama-3', 'hi', maxNewTokens: 4));

    $mock->assertSent(function (StreamGenerateRequest $request): bool {
        $body = $request->body()->all();

        expect($body)->not->toHaveKey('temperature')
            ->and($body)->not->toHaveKey('top_p')
            ->and($body['stream'])->toBeTrue();

        return true;
    });
});

it('sends sampling params on the streaming path when set', function () {
    $mock = MockClient::global([
        MockResponse::make("data: {\"text_delta\":\"hi\"}\n\ndata: [DONE]\n\n", 200),
    ]);

    $client = new SieClient('https://sie.test');
    iterator_to_array($client->streamGenerate('llama-3', 'hi', maxNewTokens: 4, temperature: 0.2, topP: 0.5));

    $mock->assertSent(function (StreamGenerateRequest $request): bool {
        $body = $request->body()->all();

        expect($body['temperature'])->toBe(0.2)->and($body['top_p'])->toBe(0.5);

        return true;
    });
});
