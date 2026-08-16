<?php

declare(strict_types=1);

use Sie\Client\Support\ChatBodyBuilder;

it('builds the minimal body with only model/messages, omitting unset optional fields', function () {
    $body = ChatBodyBuilder::build('llama-3', [['role' => 'user', 'content' => 'hi']], stream: false);

    expect($body)->toBe(['model' => 'llama-3', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
});

it('sets stream:true only when streaming', function () {
    $body = ChatBodyBuilder::build('llama-3', [], stream: true);

    expect($body['stream'])->toBeTrue();
});

it('includes every typed optional field the caller set', function () {
    $body = ChatBodyBuilder::build(
        'llama-3',
        [],
        stream: false,
        maxCompletionTokens: 100,
        temperature: 0.7,
        topP: 0.9,
        stop: ['\n'],
        tools: [['type' => 'function']],
        seed: 42,
        user: 'u-1',
    );

    expect($body['max_completion_tokens'])->toBe(100);
    expect($body['temperature'])->toBe(0.7);
    expect($body['top_p'])->toBe(0.9);
    expect($body['stop'])->toBe(['\n']);
    expect($body['tools'])->toBe([['type' => 'function']]);
    expect($body['seed'])->toBe(42);
    expect($body['user'])->toBe('u-1');
});

it('merges extra_body last, overriding a typed field with the same key', function () {
    $body = ChatBodyBuilder::build('llama-3', [], stream: false, temperature: 0.5, extraBody: ['temperature' => 0.9, 'new_field' => 'x']);

    expect($body['temperature'])->toBe(0.9);
    expect($body['new_field'])->toBe('x');
});
