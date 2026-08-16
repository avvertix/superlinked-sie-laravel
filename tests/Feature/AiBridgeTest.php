<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Ai\SieProvider;
use Sie\Client\Requests\Encode\EncodeRequest;
use Sie\Exceptions\MissingEmbeddingDimensionsException;
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

// The bridge is optional, so the suite has to stay green without laravel/ai.
beforeEach(function () {
    if (! class_exists(AiManager::class)) {
        test()->markTestSkipped('laravel/ai is not installed.');
    }
});

afterEach(fn () => MockClient::destroyGlobal());

function sieProvider(array $config = []): SieProvider
{
    return new SieProvider(
        ['driver' => 'sie', 'name' => 'sie', ...$config],
        app('events'),
    );
}

it('is both an embedding and a reranking provider', function () {
    expect(sieProvider())
        ->toBeInstanceOf(EmbeddingProvider::class)
        ->toBeInstanceOf(RerankingProvider::class);
});

it('generates dense embeddings through the bridge', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', 1024);

    $response = sieProvider()->embeddings(['Hello world', 'Goodbye world']);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)->toHaveCount(2);
    expect($response->first())->toHaveCount(1024);
    expect($response->meta->model)->toBe('BAAI/bge-m3');

    SIE::assertEncoded('BAAI/bge-m3');
});

it('refuses to guess the embedding width when it is not configured', function () {
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', null);

    sieProvider()->defaultEmbeddingsDimensions();
})->throws(MissingEmbeddingDimensionsException::class);

it('passes provider options through to SIE', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)]);

    sieProvider()->embeddings(
        ['Hello world'],
        dimensions: 8,
        providerOptions: ['instruction' => 'retrieve', 'is_query' => true, 'pool' => 'eval-bench'],
    );

    SIE::assertEncoded('BAAI/bge-m3', function (array $body): bool {
        expect($body['params']['instruction'])->toBe('retrieve');
        expect($body['params']['options']['is_query'])->toBeTrue();

        return true;
    });
});

it('reranks documents and maps them back to their original index', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['1' => 0.9, '0' => 0.2])]);

    $response = sieProvider()->rerank(['a duck', 'a goose'], 'waterfowl');

    expect($response)->toBeInstanceOf(RerankingResponse::class)->toHaveCount(2);
    expect($response->first()->document)->toBe('a goose');
    expect($response->first()->index)->toBe(1);
    expect($response->first()->score)->toBe(0.9);
    expect($response->documents()->all())->toBe(['a goose', 'a duck']);
});

it('limits reranked results when asked', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['1' => 0.9, '0' => 0.2])]);

    expect(sieProvider()->rerank(['a duck', 'a goose'], 'waterfowl', limit: 1))->toHaveCount(1);
});

it('only accepts text inputs, matching its dense-only mapping', function () {
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', 8);

    sieProvider()->embeddings([Image::fromPath(__FILE__)]);
})->throws(InvalidArgumentException::class, 'only supports text embeddings inputs');

it('uses the connection named in config rather than the default one', function () {
    config()->set('superlinked-sie-laravel.connections.eu', ['url' => 'https://eu.sie.test']);
    config()->set('superlinked-sie-laravel.ai.connection', 'eu');
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', 8);

    $mock = MockClient::global([
        MockResponse::make(['model' => 'BAAI/bge-m3', 'items' => [['dense' => ['values' => [0.1]]]]], 200),
    ]);

    sieProvider()->embeddings(['Hello world']);

    $mock->assertSent(function (EncodeRequest $request, $response): bool {
        expect((string) $response->getPendingRequest()->getUri())->toStartWith('https://eu.sie.test');

        return true;
    });
});

it('generates embeddings through the Embeddings entry point', function () {
    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);
    Config::set('superlinked-sie-laravel.ai.embeddings.dimensions', 1024);
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    $response = Embeddings::for(['a duck paddles on a pond'])->generate('sie');

    expect($response->first())->toHaveCount(1024);
    SIE::assertEncoded('BAAI/bge-m3');
});

it('reranks through the Reranking entry point', function () {
    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['1' => 0.9, '0' => 0.2])]);

    $response = Reranking::of(['a duck', 'a goose'])->rerank('waterfowl', 'sie');

    expect($response->documents()->all())->toBe(['a goose', 'a duck']);
    SIE::assertScored('BAAI/bge-m3');
});
