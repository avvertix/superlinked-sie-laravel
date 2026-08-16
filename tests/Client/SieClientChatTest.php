<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\ChatCompletion;
use Sie\Client\Requests\Chat\ChatCompletionsRequest;
use Sie\Client\Requests\Chat\StreamChatCompletionsRequest;
use Sie\Client\SieClient;

afterEach(fn () => MockClient::destroyGlobal());

it('sends a chat completion and parses the response', function () {
    MockClient::global([
        MockResponse::make([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1700000000,
            'model' => 'llama-3',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hi!'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->chatCompletions('llama-3', [['role' => 'user', 'content' => 'hi']]);

    expect($result)->toBeInstanceOf(ChatCompletion::class);
    expect($result->choices[0]->message->content)->toBe('Hi!');
    expect($result->choices[0]->finishReason)->toBe('stop');
    expect($result->usage->totalTokens)->toBe(5);
});

it('forwards every typed chat param into the JSON body', function () {
    $mock = MockClient::global([
        MockResponse::make(['id' => '1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'm', 'choices' => []], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->chatCompletions(
        'llama-3',
        [['role' => 'user', 'content' => 'hi']],
        maxCompletionTokens: 50,
        temperature: 0.2,
        topP: 0.5,
        seed: 7,
        user: 'u-1',
        gpu: 'l4',
    );

    $mock->assertSent(function (ChatCompletionsRequest $request): bool {
        $body = $request->body()->all();
        expect($body['max_completion_tokens'])->toBe(50);
        expect($body['temperature'])->toBe(0.2);
        expect($body['top_p'])->toBe(0.5);
        expect($body['seed'])->toBe(7);
        expect($body['user'])->toBe('u-1');
        expect($body)->not->toHaveKey('stream');

        return true;
    });
});

it('still allows extra_body to carry forward-compat fields', function () {
    $mock = MockClient::global([
        MockResponse::make(['id' => '1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'm', 'choices' => []], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->chatCompletions('llama-3', [], extraBody: ['future_field' => 'value']);

    $mock->assertSent(function (ChatCompletionsRequest $request): bool {
        expect($request->body()->all()['future_field'])->toBe('value');

        return true;
    });
});

it('streams chat completion chunks, setting stream:true and stopping on [DONE]', function () {
    $sse = 'data: {"id":"1","object":"chat.completion.chunk","created":1,"model":"m","choices":[{"index":0,"delta":{"content":"Hi"}}]}'."\n\n"
        .'data: [DONE]'."\n\n";

    $mock = MockClient::global([MockResponse::make($sse, 200, ['Content-Type' => 'text/event-stream'])]);

    $client = new SieClient('https://sie.test');
    $chunks = iterator_to_array($client->streamChatCompletions('llama-3', [['role' => 'user', 'content' => 'hi']]));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->choices[0]->delta->content)->toBe('Hi');

    $mock->assertSent(function (StreamChatCompletionsRequest $request): bool {
        expect($request->body()->all()['stream'])->toBeTrue();

        return true;
    });
});
