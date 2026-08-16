<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Requests\Models\GetModelRequest;
use Sie\Client\SieClient;

afterEach(fn () => MockClient::destroyGlobal());

it('lists models with capabilities', function () {
    MockClient::global([
        MockResponse::make([
            'models' => [
                ['name' => 'bge-m3', 'loaded' => true, 'inputs' => ['text'], 'outputs' => ['dense', 'sparse'], 'dims' => ['dense' => 1024]],
            ],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $models = $client->listModels();

    expect($models)->toHaveCount(1);
    expect($models[0])->toBeInstanceOf(ModelInfo::class);
    expect($models[0]->name)->toBe('bge-m3');
    expect($models[0]->dims->dense)->toBe(1024);
});

it('gets a single model, unencoded in the path', function () {
    $mock = MockClient::global([
        MockResponse::make(['name' => 'BAAI/bge-m3', 'dims' => ['dense' => 1024]], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $model = $client->getModel('BAAI/bge-m3');

    expect($model->dims->dense)->toBe(1024);
    $mock->assertSent(function (GetModelRequest $request): bool {
        expect($request->resolveEndpoint())->toBe('/v1/models/BAAI/bge-m3');

        return true;
    });
});

it('raises a RequestException on a 404', function () {
    MockClient::global([MockResponse::make(['detail' => ['message' => 'not found']], 404)]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->getModel('missing'))->toThrow(RequestException::class, 'not found');
});
