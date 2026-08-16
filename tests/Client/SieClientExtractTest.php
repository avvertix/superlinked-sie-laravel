<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\ExtractItemError;
use Sie\Client\Data\ExtractResult;
use Sie\Client\Exceptions\InputTooLongException;
use Sie\Client\Requests\Extract\ExtractRequest;
use Sie\Client\SieClient;

afterEach(fn () => MockClient::destroyGlobal());

it('extracts entities/relations/classifications for a single item, unwrapping the batch', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'ner-model',
            'items' => [[
                'id' => 'doc-1',
                'entities' => [['text' => 'Paris', 'label' => 'LOCATION', 'score' => 0.99]],
                'relations' => [['head' => 'Paris', 'tail' => 'France', 'relation' => 'capital_of', 'score' => 0.8]],
                'classifications' => [['label' => 'geography', 'score' => 0.7]],
            ]],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->extract('ner-model', ['id' => 'doc-1', 'text' => 'Paris is the capital of France.']);

    expect($result)->toBeInstanceOf(ExtractResult::class);
    expect($result->id)->toBe('doc-1');
    expect($result->entities[0]->text)->toBe('Paris');
    expect($result->relations[0]->relation)->toBe('capital_of');
    expect($result->classifications[0]->label)->toBe('geography');
});

it('short-circuits on 400 INPUT_TOO_LONG (extract-only behaviour)', function () {
    $mock = MockClient::global([
        MockResponse::make(['error' => ['code' => 'INPUT_TOO_LONG', 'message' => 'exceeds max tokens']], 400),
    ]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->extract('ner-model', ['text' => str_repeat('x', 100000)]))
        ->toThrow(InputTooLongException::class, 'exceeds max tokens');

    $mock->assertSentCount(1);
});

it('sends labels/output_schema/instruction as extract params', function () {
    $mock = MockClient::global([
        MockResponse::make(['model' => 'ner-model', 'items' => [['id' => '1']]], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->extract('ner-model', ['text' => 'x'], labels: ['PERSON', 'ORG'], instruction: 'find people');

    $mock->assertSent(function (ExtractRequest $request): bool {
        $body = $request->body()->all();
        expect($body['params']['labels'])->toBe(['PERSON', 'ORG']);
        expect($body['params']['instruction'])->toBe('find people');

        return true;
    });
});

it('surfaces a per-item error from a mixed-success batch', function () {
    // Extract batches are mixed-success: a failed item comes back alongside the
    // successful ones. Without $error it is indistinguishable from an item that
    // legitimately matched nothing.
    MockClient::global([
        MockResponse::make([
            'model' => 'gliner',
            'items' => [
                ['id' => 'ok', 'entities' => [['text' => 'Paris', 'label' => 'city', 'score' => 0.98]]],
                ['id' => 'bad', 'error' => ['code' => 'INPUT_TOO_LONG', 'message' => 'too long']],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $results = $client->extract('gliner', [['text' => 'a'], ['text' => 'b']]);

    expect($results[0]->error)->toBeNull()
        ->and($results[0]->entities)->toHaveCount(1)
        ->and($results[1]->error)->not->toBeNull()
        ->and($results[1]->error->code)->toBe('INPUT_TOO_LONG')
        ->and($results[1]->error->message)->toBe('too long')
        ->and($results[1]->entities)->toBe([]);
});

it('falls back to a stable code when a per-item error is malformed', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'gliner',
            'items' => [['id' => 'bad', 'error' => ['code' => '']]],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $result = $client->extract('gliner', ['text' => 'a']);

    expect($result->error->code)->toBe(ExtractItemError::MALFORMED_CODE)
        ->and($result->error->message)->toBe(ExtractItemError::MALFORMED_MESSAGE);
});
