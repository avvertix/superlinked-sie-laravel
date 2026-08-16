<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Saloon\Http\Faking\MockClient;
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

afterEach(fn () => MockClient::destroyGlobal());

it('fakes an encode without touching the network', function () {
    SIE::fake();

    $results = SIE::model('BAAI/bge-m3')->encode('Hello world');

    expect($results)->toHaveCount(1);
    expect($results->sole()->dense)->toHaveCount(8);
});

it('fakes vectors at the width the caller asked for', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    expect(SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense)->toHaveCount(1024);
});

it('returns deterministic vectors so snapshots stay stable', function () {
    SIE::fake();

    $first = SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense;
    $second = SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense;
    $other = SIE::model('BAAI/bge-m3')->encode('Goodbye world')->sole()->dense;

    expect($first)->toBe($second);
    expect($first)->not->toBe($other);
});

it('returns one fake result per input', function () {
    SIE::fake();

    expect(SIE::model('BAAI/bge-m3')->encode(['a', 'b', 'c']))->toHaveCount(3);
});

it('asserts that a model was encoded', function () {
    SIE::fake();

    SIE::model('BAAI/bge-m3')->instruction('retrieve')->encode('Hello world');

    SIE::assertEncoded('BAAI/bge-m3');
    SIE::assertEncoded('BAAI/bge-m3', fn (array $body): bool => $body['params']['instruction'] === 'retrieve');
});

it('fails the assertion when a different model was encoded', function () {
    SIE::fake();

    SIE::model('BAAI/bge-m3')->encode('Hello world');

    SIE::assertEncoded('docling');
})->throws(ExpectationFailedException::class);

it('asserts that nothing was sent', function () {
    SIE::fake();

    SIE::assertNothingSent();
});

it('fakes an extraction with the entities the caller supplied', function () {
    SIE::fake(['gliner' => FakeModel::entities([
        ['text' => 'Ada', 'label' => 'PERSON', 'score' => 0.99, 'start' => 0, 'end' => 3],
    ])]);

    $results = SIE::model('gliner')->labels(['PERSON'])->extract('Ada Lovelace');

    expect($results)->toHaveCount(1);
    expect($results->sole()->entities)->toHaveCount(1);
    expect($results->sole()->entities[0]->text)->toBe('Ada');
    SIE::assertExtracted('gliner');
});

it('fakes a generation with the text the caller supplied', function () {
    SIE::fake(['tiny-llm' => FakeModel::text('a duck is a bird')]);

    expect(SIE::model('tiny-llm')->generate('what is a duck?')->text)->toBe('a duck is a bird');
    SIE::assertGenerated('tiny-llm');
});

it('fakes a score in descending relevance order', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['b' => 0.9, 'a' => 0.2])]);

    $results = SIE::model('BAAI/bge-m3')->score('duck?', ['a', 'b']);

    expect($results->top(2))->toBe(['b', 'a']);
    SIE::assertScored('BAAI/bge-m3');
});

it('fakes the model catalog from the configured models', function () {
    SIE::fake([
        'BAAI/bge-m3' => FakeModel::dense(1024),
        'gliner' => FakeModel::entities([]),
    ]);

    expect(SIE::models()->pluck('name')->all())->toBe(['BAAI/bge-m3', 'gliner']);
    expect(SIE::models()->firstWhere('name', 'BAAI/bge-m3')->outputs)->toBe(['dense']);
    expect(SIE::models()->firstWhere('name', 'gliner')->outputs)->toBe(['json']);
});

it('counts the requests it faked', function () {
    SIE::fake();

    SIE::model('BAAI/bge-m3')->encode('a');
    SIE::model('BAAI/bge-m3')->encode('b');

    SIE::assertSentCount(2);
});
