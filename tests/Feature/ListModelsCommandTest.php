<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

afterEach(fn () => MockClient::destroyGlobal());

it('lists the models a cluster serves', function () {
    SIE::fake([
        'BAAI/bge-m3' => FakeModel::dense(1024),
        'urchade/gliner_multi-v2.1' => FakeModel::entities([]),
    ]);

    $this->artisan('sie:models')
        ->expectsOutputToContain('BAAI/bge-m3')
        ->expectsOutputToContain('urchade/gliner_multi-v2.1')
        ->assertSuccessful();
});

it('shows what each model accepts, produces and how wide it is', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    expect(Artisan::call('sie:models'))->toBe(0);

    // Which capabilities a model supports is the cluster's declaration, not the
    // caller's choice, so inputs and outputs are the point of the command.
    expect(Artisan::output())
        ->toContain('BAAI/bge-m3')
        ->toContain('text')          // inputs
        ->toContain('dense')         // outputs
        ->toContain('dense: 1024')   // dimensions
        ->toContain('yes');          // loaded
});

it('sorts models by name so the output is stable', function () {
    SIE::fake([
        'zeta' => FakeModel::dense(8),
        'alpha' => FakeModel::dense(8),
        'middle' => FakeModel::dense(8),
    ]);

    expect(Artisan::call('sie:models'))->toBe(0);

    $output = Artisan::output();

    expect(strpos($output, 'alpha'))->toBeLessThan(strpos($output, 'middle'));
    expect(strpos($output, 'middle'))->toBeLessThan(strpos($output, 'zeta'));
});

it('reports the connection it read', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    $this->artisan('sie:models')
        ->expectsOutputToContain('default')
        ->assertSuccessful();
});

it('reads a named connection', function () {
    config()->set('superlinked-sie-laravel.connections.eu', ['url' => 'https://eu.sie.test']);

    $mock = MockClient::global([
        MockResponse::make(['models' => [['name' => 'eu-only-model', 'inputs' => ['text'], 'outputs' => ['dense']]]], 200),
    ]);

    $this->artisan('sie:models', ['--connection' => 'eu'])
        ->expectsOutputToContain('eu-only-model')
        ->assertSuccessful();

    $mock->assertSent(fn ($request, $response): bool => str_starts_with(
        (string) $response->getPendingRequest()->getUri(),
        'https://eu.sie.test',
    ));
});

it('fails cleanly when the connection is not defined', function () {
    expect(Artisan::call('sie:models', ['--connection' => 'nope']))->toBe(1);

    // Not one of ours, so it is named: a bug in the command must not be able to
    // read as though the cluster were unreachable.
    expect(Artisan::output())
        ->toContain('InvalidArgumentException')
        ->toContain('nope');
});

it('fails cleanly when the cluster cannot be reached', function () {
    MockClient::global([MockResponse::make(['detail' => 'boom'], 500)]);

    expect(Artisan::call('sie:models'))->toBe(1);

    // A SIE error speaks for itself and is reported without a class name.
    expect(Artisan::output())
        ->toContain('boom')
        ->not->toContain('Exception:');
});

it('says so when the cluster serves nothing', function () {
    SIE::fake();

    MockClient::global([MockResponse::make(['models' => []], 200)]);

    $this->artisan('sie:models')
        ->expectsOutputToContain('No models')
        ->assertSuccessful();
});

it('filters models by name', function () {
    // A real cluster serves ~150 models, so finding one is the point.
    SIE::fake([
        'BAAI/bge-m3' => FakeModel::dense(1024),
        'BAAI/bge-reranker-v2-m3' => FakeModel::scores([]),
        'urchade/gliner_multi-v2.1' => FakeModel::entities([]),
    ]);

    expect(Artisan::call('sie:models', ['filter' => 'gliner']))->toBe(0);

    expect(Artisan::output())
        ->toContain('urchade/gliner_multi-v2.1')
        ->not->toContain('BAAI/bge-m3');
});

it('matches the filter regardless of case', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    expect(Artisan::call('sie:models', ['filter' => 'BGE']))->toBe(0);
    expect(Artisan::output())->toContain('BAAI/bge-m3');
});

it('says so when nothing matches the filter', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    expect(Artisan::call('sie:models', ['filter' => 'nothing-like-this']))->toBe(0);
    expect(Artisan::output())->toContain('nothing-like-this');
});

/** A catalog with a mix of loaded, unloaded and unknown-state models. */
function mixedCatalog(): MockResponse
{
    return MockResponse::make(['models' => [
        ['name' => 'BAAI/bge-m3', 'inputs' => ['text'], 'outputs' => ['dense'], 'loaded' => true],
        ['name' => 'BAAI/bge-reranker-v2-m3', 'inputs' => ['text'], 'outputs' => ['score'], 'loaded' => false],
        ['name' => 'docling', 'inputs' => ['document'], 'outputs' => ['json'], 'loaded' => true],
        ['name' => 'mystery-model', 'inputs' => ['text'], 'outputs' => ['dense']],
    ]], 200);
}

it('shows only loaded models when asked', function () {
    MockClient::global([mixedCatalog()]);

    expect(Artisan::call('sie:models', ['--loaded' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('BAAI/bge-m3')
        ->toContain('docling')
        ->not->toContain('BAAI/bge-reranker-v2-m3');
});

it('excludes models whose loaded state the cluster did not report', function () {
    MockClient::global([mixedCatalog()]);

    // --loaded asks for what is known to be loaded. A model with no reported
    // state is not that, and guessing either way would be worse than omitting.
    expect(Artisan::call('sie:models', ['--loaded' => true]))->toBe(0);
    expect(Artisan::output())->not->toContain('mystery-model');
});

it('still lists unloaded models without the option', function () {
    MockClient::global([mixedCatalog()]);

    expect(Artisan::call('sie:models'))->toBe(0);

    expect(Artisan::output())
        ->toContain('BAAI/bge-reranker-v2-m3')
        ->toContain('mystery-model');
});

it('combines the loaded option with the name filter', function () {
    MockClient::global([mixedCatalog()]);

    expect(Artisan::call('sie:models', ['filter' => 'bge', '--loaded' => true]))->toBe(0);

    expect(Artisan::output())
        ->toContain('BAAI/bge-m3')
        ->not->toContain('docling')                  // loaded, but not matching
        ->not->toContain('BAAI/bge-reranker-v2-m3'); // matching, but not loaded
});

it('says so when nothing is loaded', function () {
    MockClient::global([MockResponse::make(['models' => [
        ['name' => 'BAAI/bge-m3', 'inputs' => ['text'], 'outputs' => ['dense'], 'loaded' => false],
    ]], 200)]);

    expect(Artisan::call('sie:models', ['--loaded' => true]))->toBe(0);
    expect(Artisan::output())->toContain('No models');
});
