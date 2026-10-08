<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Support\WireFormat;
use Sie\Exceptions\UnsupportedCapabilityException;
use Sie\Facades\SIE;
use Sie\Input;
use Sie\Tests\Client\Support\Env;

/**
 * Exercises the fluent layer (the SIE facade) against a real cluster.
 *
 * Skipped whenever SIE_ENDPOINT is absent, so a fresh clone with no credentials
 * still runs green. Embedding runs on the multivector model compose.yaml
 * preloads (see embeddingModel()); tests that need any other model skip unless
 * the cluster already has it loaded, so the suite never downloads models.
 */
beforeEach(function () {
    $endpoint = Env::get('SIE_ENDPOINT');

    if ($endpoint === null) {
        test()->markTestSkipped('SIE_ENDPOINT is not set — skipping live integration test.');
    }

    config()->set('superlinked-sie-laravel.connections.default', [
        'url' => $endpoint,
        'key' => Env::get('SIE_KEY'),
        'timeout' => 120,
    ]);
});

it('lists the model catalog with declared inputs and outputs', function () {
    $model = embeddingModel();

    $models = SIE::models();

    expect($models)->not->toBeEmpty();

    $embedder = $models->firstWhere('name', $model);

    expect($embedder)->not->toBeNull();
    expect($embedder->inputs)->toContain('text', 'image');
    expect($embedder->outputs)->toBe(['multivector']);
    expect($embedder->dims->multivector)->toBe(1024);
});

it('encodes text into a multivector', function () {
    $results = SIE::model(embeddingModel())->outputs(['multivector'])->encode('Hello world');

    expect($results)->toHaveCount(1);

    $multivector = $results->sole()->multivector;

    expect($multivector)->not->toBeEmpty();
    expect($multivector[0])->toHaveCount(1024);
});

it('encodes a batch and returns one result per input, in order', function () {
    $results = SIE::model(embeddingModel())->outputs(['multivector'])->encode(['a duck', 'a goose', 'a turbine']);

    expect($results)->toHaveCount(3);
    expect($results->multivector())->toHaveCount(3);

    // Distinct inputs must not collapse to the same vectors.
    expect($results->multivector()[0])->not->toBe($results->multivector()[2]);
});

it('encodes an image read from a disk', function () {
    Storage::fake('images');
    Storage::disk('images')->put('pixel.jpg', tinyJpeg());

    $results = SIE::model(embeddingModel())
        ->outputs(['multivector'])
        ->encode(Input::fromDisk('images', 'pixel.jpg'));

    expect($results->sole()->multivector)->not->toBeEmpty();
});

it('encodes text into a dense vector', function () {
    $model = firstModelMatching(sieClient(), ['BAAI/bge-m3']);

    $results = SIE::model($model)->encode('Hello world');

    expect($results)->toHaveCount(1);
    expect($results->sole()->dense)->toHaveCount(1024);
});

it('requests sparse and multivector outputs alongside dense', function () {
    $model = firstModelMatching(
        sieClient(),
        ['BAAI/bge-m3'],
        static fn (ModelInfo $m): bool => array_diff(['dense', 'sparse', 'multivector'], $m->outputs ?? []) === [],
    );

    $result = SIE::model($model)
        ->outputs(['dense', 'sparse', 'multivector'])
        ->encode('Hello world')
        ->sole();

    expect($result->dense)->toHaveCount(1024);
    expect($result->sparse)->not->toBeNull();
    expect($result->multivector)->not->toBeNull();
});

it('scores inputs against a query, most relevant first', function () {
    // The preloaded embedder does not score, so this needs a loaded reranker.
    $model = firstModelMatching(sieClient(), ['BAAI/bge-m3']);

    $results = SIE::model($model)->score(
        'waterfowl that swims',
        [Input::text('a duck paddles on a pond', 'duck'), Input::text('a turbine spins', 'turbine')],
    );

    expect($results)->toHaveCount(2);
    expect($results->top(1))->toBe(['duck']);
});

it('extracts named entities with a gliner model', function () {
    $results = SIE::model(firstModelMatching(sieClient(), ['urchade/gliner_multi-v2.1']))
        ->labels(['person', 'location'])
        ->extract('Ada Lovelace was born in London.');

    expect($results)->toHaveCount(1);
    expect($results->hasFailures())->toBeFalse();
    expect($results->sole()->entities)->not->toBeEmpty();
});

it('parses a document with docling, which is an extract model not an encode one', function () {
    $model = firstModelMatching(sieClient(), ['docling']);

    Storage::fake('documents');
    Storage::disk('documents')->put('note.md', "# Ada Lovelace\n\nBorn in London.\n");

    $results = SIE::model($model)->extract(Input::fromDisk('documents', 'note.md'));

    expect($results)->toHaveCount(1);
    expect($results->hasFailures())->toBeFalse();
    expect($results->sole()->data)->not->toBeNull();
});

it('addresses a model profile with colon syntax', function () {
    // info() reads the catalog and does not load the profile.
    $model = embeddingModel();

    expect(SIE::model($model)->profile('muvera')->info()->name)->toBe("{$model}:muvera");
});

it('reports a model that cannot serve the requested capability', function () {
    // The embedder declares outputs [multivector] — it cannot produce dense.
    expect(fn () => SIE::model(embeddingModel())->outputs(['dense'])->encode('Hello world'))
        ->toThrow(UnsupportedCapabilityException::class);
});

it('reports an unknown model as a plain request error, not a capability mismatch', function () {
    expect(fn () => SIE::model('no-such-model-at-all')->encode('Hello world'))
        ->toThrow(RequestException::class);
});

it('speaks msgpack by default', function () {
    expect(SIE::connection()->format())->toBe(WireFormat::Msgpack);
});

it('returns the same vectors over both transports', function () {
    $model = embeddingModel();
    $text = 'a duck paddles on a pond';

    config()->set('superlinked-sie-laravel.connections.default.format', 'msgpack');
    $viaMsgpack = SIE::connection()->client()->encode($model, ['text' => $text], outputTypes: ['multivector']);

    config()->set('superlinked-sie-laravel.connections.json.format', 'json');
    config()->set('superlinked-sie-laravel.connections.json.url', Env::get('SIE_ENDPOINT'));
    config()->set('superlinked-sie-laravel.connections.json.key', Env::get('SIE_KEY'));
    $viaJson = SIE::connection('json')->client()->encode($model, ['text' => $text], outputTypes: ['multivector']);

    expect($viaMsgpack->multivector)->not->toBeEmpty();
    expect($viaJson->multivector)->toHaveCount(count($viaMsgpack->multivector));

    // float32 reaches us as a raw buffer over msgpack and as decimal text over
    // JSON, so they agree to float32 precision rather than bit-for-bit.
    foreach ($viaMsgpack->multivector as $row => $vector) {
        foreach ($vector as $i => $value) {
            expect(abs($value - $viaJson->multivector[$row][$i]))->toBeLessThan(1e-6);
        }
    }
});

it('parses a document sent as raw bytes rather than base64', function () {
    $model = firstModelMatching(sieClient(), ['docling']);

    Storage::fake('documents');
    Storage::disk('documents')->put('note.md', "# Ada Lovelace\n\nBorn in London.\n");

    // Over msgpack the document travels as a native bin. The same bytes sent as
    // a base64 string are rejected with "Expected `bytes`, got `str`".
    $results = SIE::model($model)->extract(Input::fromDisk('documents', 'note.md'));

    expect($results->hasFailures())->toBeFalse();
    expect($results->sole()->data)->not->toBeNull();
});
