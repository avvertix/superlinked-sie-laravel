<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Sie\Client\Exceptions\RequestException;
use Sie\Exceptions\UnsupportedCapabilityException;
use Sie\Facades\SIE;
use Sie\Input;
use Sie\Tests\Client\Support\Env;

/**
 * Exercises the fluent layer against a real cluster.
 *
 * Skipped whenever SIE_ENDPOINT is absent, so a fresh clone with no credentials
 * still runs green.
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

    config()->set('superlinked-sie-laravel.catalog.ttl', 0);
});

it('lists the model catalog with declared inputs and outputs', function () {
    $models = SIE::models();

    expect($models)->not->toBeEmpty();

    $bge = $models->firstWhere('name', 'BAAI/bge-m3');

    expect($bge)->not->toBeNull();
    expect($bge->outputs)->toContain('dense');
    expect($bge->dims->dense)->toBe(1024);
});

it('encodes text into a dense vector', function () {
    $results = SIE::model('BAAI/bge-m3')->encode('Hello world');

    expect($results)->toHaveCount(1);
    expect($results->sole()->dense)->toHaveCount(1024);
});

it('encodes a batch and returns one result per input, in order', function () {
    $results = SIE::model('BAAI/bge-m3')->encode(['a duck', 'a goose', 'a turbine']);

    expect($results)->toHaveCount(3);
    expect($results->dense())->toHaveCount(3);

    // Distinct inputs must not collapse to the same vector.
    expect($results->dense()[0])->not->toBe($results->dense()[2]);
});

it('requests sparse and multivector outputs alongside dense', function () {
    $result = SIE::model('BAAI/bge-m3')
        ->outputs(['dense', 'sparse', 'multivector'])
        ->encode('Hello world')
        ->sole();

    expect($result->dense)->toHaveCount(1024);
    expect($result->sparse)->not->toBeNull();
    expect($result->multivector)->not->toBeNull();
});

it('scores inputs against a query, most relevant first', function () {
    $results = SIE::model('BAAI/bge-m3')->score(
        'waterfowl that swims',
        [Input::text('a duck paddles on a pond', 'duck'), Input::text('a turbine spins', 'turbine')],
    );

    expect($results)->toHaveCount(2);
    expect($results->top(1))->toBe(['duck']);
});

it('extracts named entities with a gliner model', function () {
    $results = SIE::model('urchade/gliner_multi-v2.1')
        ->labels(['person', 'location'])
        ->extract('Ada Lovelace was born in London.');

    expect($results)->toHaveCount(1);
    expect($results->hasFailures())->toBeFalse();
    expect($results->sole()->entities)->not->toBeEmpty();
});

it('parses a document with docling, which is an extract model not an encode one', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('note.md', "# Ada Lovelace\n\nBorn in London.\n");

    $results = SIE::model('docling')->extract(Input::fromDisk('documents', 'note.md'));

    expect($results)->toHaveCount(1);
    expect($results->hasFailures())->toBeFalse();
    expect($results->sole()->data)->not->toBeNull();
});

it('addresses a model profile with colon syntax', function () {
    expect(SIE::model('docling')->profile('ocr')->info()->name)->toBe('docling:ocr');
});

it('reports a model that cannot serve the requested capability', function () {
    // docling declares inputs [image, document] and outputs [json] — it cannot encode.
    expect(fn () => SIE::model('docling')->encode('Hello world'))
        ->toThrow(UnsupportedCapabilityException::class);
});

it('reports an unknown model as a plain request error, not a capability mismatch', function () {
    expect(fn () => SIE::model('no-such-model-at-all')->encode('Hello world'))
        ->toThrow(RequestException::class);
});
