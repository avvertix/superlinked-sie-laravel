<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Sie\Exceptions\ExtractionFailedException;
use Sie\Facades\SIE;
use Sie\Input;
use Sie\Testing\FakeModel;

afterEach(fn () => MockClient::destroyGlobal());

/*
 * The examples in README.md and in the bundled Boost skills, run against the
 * package. Documentation drifts silently otherwise: the streaming example
 * printed $chunk->text for two releases, a property GenerateChunk never had.
 */

it('runs the capability examples the README and Boost skill document', function () {
    SIE::fake([
        'BAAI/bge-m3' => FakeModel::dense(1024)->andScores(['doc-b' => 0.9, 'doc-a' => 0.2]),
        'urchade/gliner_multi-v2.1' => FakeModel::entities([
            ['text' => 'Ada', 'label' => 'person', 'score' => 0.99, 'start' => 0, 'end' => 3],
        ]),
        'docling' => FakeModel::extracting(fn (array $item): array => ['data' => ['markdown' => '# Invoice']]),
        'some-llm' => FakeModel::text('a duck is a bird'),
    ]);

    expect(SIE::model('BAAI/bge-m3')->encode('a duck paddles on a pond')->sole()->dense)->toHaveCount(1024);
    expect(SIE::model('BAAI/bge-m3')->asQuery()->encode('waterfowl that swims'))->toHaveCount(1);
    expect(SIE::model('BAAI/bge-m3')->outputs(['dense', 'sparse'])->encode(['a', 'b']))->toHaveCount(2);

    expect(SIE::model('BAAI/bge-m3')->score('waterfowl', [
        Input::text('a duck', id: 'doc-a'),
        Input::text('a goose', id: 'doc-b'),
    ])->top(3))->toBe(['doc-b', 'doc-a']);

    expect(SIE::model('urchade/gliner_multi-v2.1')->labels(['person'])->extract('Ada Lovelace')->sole()->entities)->toHaveCount(1);
    expect(SIE::model('docling')->extract('invoice.pdf')->sole()->data)->toBe(['markdown' => '# Invoice']);

    expect(SIE::model('some-llm')->maxNewTokens(256)->generate('what is a duck?')->text)->toBe('a duck is a bird');

    $streamed = '';
    SIE::model('some-llm')->stream('what is a duck?')->each(function ($chunk) use (&$streamed): void {
        $streamed .= $chunk->textDelta;
    });
    expect($streamed)->toBe('a duck is a bird');
});

it('runs the mixed-success example the README and Boost skill document', function () {
    SIE::fake(['docling' => FakeModel::extracting(fn (array $item): array => $item['text'] === 'bad'
        ? ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'tokenizer failed']]
        : ['data' => ['markdown' => '# Invoice']],
    )]);

    $results = SIE::model('docling')->extract(['good.pdf', 'bad']);

    expect($results->hasFailures())->toBeTrue();
    expect($results->failed()->count())->toBe(1);

    $seen = 0;
    $results->succeeded()->each(function () use (&$seen): void {
        $seen++;
    });
    expect($seen)->toBe(1);

    expect(fn () => $results->throwIfAnyFailed())->toThrow(ExtractionFailedException::class);
});
