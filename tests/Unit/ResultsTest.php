<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Sie\Client\Data\EncodeResult;
use Sie\Client\Data\ExtractItemError;
use Sie\Client\Data\ExtractResult;
use Sie\Client\Data\ScoreEntry;
use Sie\Client\Data\ScoreResult;
use Sie\Exceptions\ExtractionFailedException;
use Sie\Results\EncodeResults;
use Sie\Results\ExtractResults;
use Sie\Results\ScoreResults;

it('wraps encode results in a collection', function () {
    $results = new EncodeResults([
        new EncodeResult(dense: [0.1, 0.2]),
        new EncodeResult(dense: [0.3, 0.4]),
    ]);

    expect($results)->toBeInstanceOf(Collection::class)->toHaveCount(2);
    expect($results->first()->dense)->toBe([0.1, 0.2]);
});

it('exposes the dense vectors of an encode batch', function () {
    $results = new EncodeResults([
        new EncodeResult(dense: [0.1, 0.2]),
        new EncodeResult(dense: [0.3, 0.4]),
    ]);

    expect($results->dense())->toBe([[0.1, 0.2], [0.3, 0.4]]);
});

it('returns a single encode result for a single input', function () {
    $results = new EncodeResults([new EncodeResult(dense: [0.1])]);

    expect($results->sole()->dense)->toBe([0.1]);
});

it('keeps score entries in relevance order', function () {
    $results = new ScoreResults([
        new ScoreEntry('b', 0.9, 0),
        new ScoreEntry('a', 0.2, 1),
    ]);

    expect($results->first()->itemId)->toBe('b');
    expect($results->pluck('itemId')->all())->toBe(['b', 'a']);
});

it('keeps failed extractions in the collection rather than throwing', function () {
    $results = new ExtractResults([
        new ExtractResult(entities: [], id: 'ok'),
        new ExtractResult(id: 'bad', error: new ExtractItemError('INVALID_INPUT', 'Unreadable PDF')),
    ]);

    expect($results)->toHaveCount(2);
    expect($results->failed())->toHaveCount(1);
    expect($results->succeeded())->toHaveCount(1);
    expect($results->failed()->first()->id)->toBe('bad');
});

it('reports whether any extraction partially failed', function () {
    $clean = new ExtractResults([new ExtractResult(id: 'ok')]);
    $dirty = new ExtractResults([new ExtractResult(id: 'bad', error: new ExtractItemError('X', 'boom'))]);

    expect($clean->hasFailures())->toBeFalse();
    expect($dirty->hasFailures())->toBeTrue();
});

it('throws on demand when any extraction failed', function () {
    $results = new ExtractResults([
        new ExtractResult(id: 'ok'),
        new ExtractResult(id: 'bad', error: new ExtractItemError('INVALID_INPUT', 'Unreadable PDF')),
    ]);

    $results->throwIfAnyFailed();
})->throws(ExtractionFailedException::class, '1 of 2 inputs failed to extract: [INVALID_INPUT] Unreadable PDF');

it('does not throw when every extraction succeeded', function () {
    $results = new ExtractResults([new ExtractResult(id: 'ok')]);

    expect($results->throwIfAnyFailed())->toBe($results);
});

it('carries the score envelope onto every derived collection', function () {
    $results = ScoreResults::fromResult(ScoreResult::fromArray([
        'model' => 'BAAI/bge-m3',
        'query_id' => 'q-1',
        'scores' => [
            ['item_id' => 'duck', 'score' => 0.65, 'rank' => 0],
            ['item_id' => 'turbine', 'score' => 0.52, 'rank' => 1],
        ],
        'usage' => ['input_tokens' => 34],
    ]));

    expect($results->model)->toBe('BAAI/bge-m3');
    expect($results->usage->inputTokens)->toBe(34);
    expect($results->queryId)->toBe('q-1');

    // Every derivation path goes through newInstance(), so the envelope survives.
    foreach ([
        'filter' => $results->filter(fn (ScoreEntry $e): bool => $e->score > 0.6),
        'sortBy' => $results->sortByDesc('score'),
        'take' => $results->take(1),
        'values' => $results->values(),
        'reject' => $results->reject(fn (): bool => false),
        'slice' => $results->slice(0, 1),
    ] as $method => $derived) {
        expect($derived)->toBeInstanceOf(ScoreResults::class);
        expect($derived->model)->toBe('BAAI/bge-m3', "envelope lost by {$method}()");
        expect($derived->usage->inputTokens)->toBe(34, "usage lost by {$method}()");
        expect($derived->queryId)->toBe('q-1', "query id lost by {$method}()");
    }
});

it('reports a query id the cluster did not assign as null', function () {
    $results = ScoreResults::fromResult(ScoreResult::fromArray([
        'model' => 'BAAI/bge-m3',
        'query_id' => null,
        'scores' => [['item_id' => 'duck', 'score' => 0.65, 'rank' => 0]],
        'usage' => ['input_tokens' => 34],
    ]));

    expect($results->queryId)->toBeNull();
    expect($results->filter(fn (): bool => true)->queryId)->toBeNull();
});

it('starts a hand-built score collection with an empty envelope', function () {
    $results = new ScoreResults([new ScoreEntry('a', 1.0, 0)]);

    expect($results->model)->toBeNull();
    expect($results->usage)->toBeNull();
});
