<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\ScoreResult;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Score\ScoreRequest;
use Sie\Client\SieClient;

afterEach(fn () => MockClient::destroyGlobal());

it('scores items against a query and parses the sorted results', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'reranker',
            'query_id' => 'q-1',
            'scores' => [
                ['item_id' => 'doc-2', 'score' => 0.9, 'rank' => 0],
                ['item_id' => 'doc-1', 'score' => 0.4, 'rank' => 1],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->score('reranker', ['id' => 'q-1', 'text' => 'query'], [['id' => 'doc-1', 'text' => 'a'], ['id' => 'doc-2', 'text' => 'b']]);

    expect($result)->toBeInstanceOf(ScoreResult::class);
    expect($result->queryId)->toBe('q-1');
    expect($result->scores)->toHaveCount(2);
    expect($result->scores[0]->itemId)->toBe('doc-2');
    expect($result->scores[0]->score)->toBe(0.9);
});

it('sends query and items through the JSON body, without a LoRA retry branch', function () {
    $mock = MockClient::global([
        MockResponse::make(['error' => ['code' => 'LORA_LOADING']], 503),
    ]);

    $client = new SieClient('https://sie.test');

    // score() has no LoRA retry branch: a 503 LORA_LOADING falls to the generic 5xx path immediately.
    expect(fn () => $client->score('reranker', ['text' => 'q'], [['text' => 'd']]))
        ->toThrow(ServerException::class);

    $mock->assertSentCount(1);
    $mock->assertSent(function (ScoreRequest $request): bool {
        $body = $request->body()->all();
        expect($body['query'])->toBe(['text' => 'q']);
        expect($body['items'])->toBe([['text' => 'd']]);

        return true;
    });
});

it('parses the authoritative usage block when the server reports one', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'reranker',
            'scores' => [['item_id' => 'doc-1', 'score' => 0.9, 'rank' => 1]],
            'usage' => ['input_tokens' => 128, 'images' => 2],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->score('reranker', ['text' => 'q'], [['id' => 'doc-1', 'text' => 'd']]);

    expect($result->usage)->not->toBeNull()
        ->and($result->usage->inputTokens)->toBe(128)
        ->and($result->usage->images)->toBe(2);
});

it('leaves usage null when the server omits it', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'reranker',
            'scores' => [['item_id' => 'doc-1', 'score' => 0.9, 'rank' => 1]],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->score('reranker', ['text' => 'q'], [['id' => 'doc-1', 'text' => 'd']]);

    expect($result->usage)->toBeNull();
});
