<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
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

it('runs the classification example the README and Boost skill document', function () {
    if (! class_exists(Classification::class)) {
        test()->markTestSkipped('laravel/ai classification is not available.');
    }

    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);

    SIE::fake(['fastino/GLiNER2.5-Decide' => FakeModel::extracting(fn (array $item): array => ['data' => [
        'urgent' => ['type' => 'noul', 'noul' => 0.95, 'answer' => true],
        'department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 0.9, 'technical' => 0.1]],
        'frustration' => ['type' => 'score', 'score' => 1.4, 'legend' => ['Calm', 'Frustrated', 'Very angry'], 'probabilities' => [0.1, 0.4, 0.5]],
        'decision' => ['type' => 'noul', 'noul' => 0.95, 'answer' => true],
    ]])]);

    $supportRequest = 'I was charged twice and nobody answers my emails!';

    $response = Classification::of($supportRequest)
        ->questions([
            'urgent' => new Boolean('Does this request need an immediate response?'),
            'department' => new Choice('Which team should handle this request?', [
                'billing' => 'Payments, invoices, and refunds',
                'technical' => 'Bugs, outages, and integrations',
            ]),
            'frustration' => new Score('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']),
        ])
        ->classify('sie');

    expect($response->answer('urgent')->isTrue(0.9))->toBeTrue();
    expect($response->answer('department')->choice)->toBe('billing');
    expect($response->answer('frustration')->score)->toBe(1.4);

    expect(Str::of('WIN a FREE cruise')->decide('Is this spam?', provider: 'sie'))->toBeTrue();

    // "Set default_for_classification to sie to drop the provider argument."
    Config::set('ai.default_for_classification', 'sie');

    expect(Str::of('WIN a FREE cruise')->decide('Is this spam?'))->toBeTrue();
});
