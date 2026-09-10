<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Requests\Encode\EncodeRequest;
use Sie\Connection;
use Sie\Facades\SIE;
use Sie\PendingRequest;
use Sie\Pools;

afterEach(fn () => MockClient::destroyGlobal());

it('resolves the default connection', function () {
    expect(SIE::connection())->toBeInstanceOf(Connection::class);
    expect(SIE::connection()->name)->toBe('default');
});

it('resolves a named connection', function () {
    config()->set('superlinked-sie-laravel.connections.eu', [
        'url' => 'https://eu.sie.test',
        'key' => 'eu-key',
    ]);

    expect(SIE::connection('eu')->name)->toBe('eu');
    expect(SIE::connection('eu')->client()->baseUrl())->toBe('https://eu.sie.test');
});

it('refuses an undefined connection', function () {
    SIE::connection('does-not-exist');
})->throws(InvalidArgumentException::class, 'Instance [does-not-exist] is not defined.');

it('refuses a connection with no url', function () {
    config()->set('superlinked-sie-laravel.connections.broken', ['key' => 'x']);

    SIE::connection('broken')->client();
})->throws(RuntimeException::class, 'The [broken] SIE connection has no url.');

it('begins a request on the default connection', function () {
    expect(SIE::model('BAAI/bge-m3'))->toBeInstanceOf(PendingRequest::class);
    expect(SIE::model('BAAI/bge-m3')->modelId())->toBe('BAAI/bge-m3');
});

it('routes a request through the connection it was built from', function () {
    config()->set('superlinked-sie-laravel.connections.eu', ['url' => 'https://eu.sie.test']);

    $mock = MockClient::global([
        MockResponse::make(['model' => 'BAAI/bge-m3', 'items' => [['dense' => ['values' => [0.1]]]]], 200),
    ]);

    SIE::connection('eu')->model('BAAI/bge-m3')->encode('Hello world');

    $mock->assertSent(function (EncodeRequest $request, $response): bool {
        expect((string) $response->getPendingRequest()->getUri())->toStartWith('https://eu.sie.test');

        return true;
    });
});

it('applies the connection default pool and gpu to requests', function () {
    config()->set('superlinked-sie-laravel.connections.default.pool', 'eval-bench');
    config()->set('superlinked-sie-laravel.connections.default.gpu', 'l4');

    $mock = MockClient::global([
        MockResponse::make(['model' => 'BAAI/bge-m3', 'items' => [['dense' => ['values' => [0.1]]]]], 200),
    ]);

    SIE::model('BAAI/bge-m3')->encode('Hello world');

    $mock->assertSent(function (EncodeRequest $request, $response): bool {
        $headers = $response->getPendingRequest()->headers();

        expect($headers->get('X-SIE-Pool'))->toBe('eval-bench');
        expect($headers->get('X-SIE-MACHINE-PROFILE'))->toBe('l4');

        return true;
    });
});

it('omits the machine profile header when only a pool is configured', function () {
    config()->set('superlinked-sie-laravel.connections.default.pool', 'eval-bench');

    $mock = MockClient::global([
        MockResponse::make(['model' => 'BAAI/bge-m3', 'items' => [['dense' => ['values' => [0.1]]]]], 200),
    ]);

    SIE::model('BAAI/bge-m3')->encode('Hello world');

    $mock->assertSent(function (EncodeRequest $request, $response): bool {
        $headers = $response->getPendingRequest()->headers();

        expect($headers->get('X-SIE-Pool'))->toBe('eval-bench');
        expect($headers->get('X-SIE-MACHINE-PROFILE'))->toBeNull();

        return true;
    });
});

it('lists the model catalog', function () {
    MockClient::global([
        MockResponse::make(['models' => [
            ['name' => 'BAAI/bge-m3', 'inputs' => ['text'], 'outputs' => ['dense'], 'dims' => ['dense' => 1024]],
            ['name' => 'docling', 'inputs' => ['image', 'document'], 'outputs' => ['json'], 'dims' => []],
        ]], 200),
    ]);

    $models = SIE::models();

    expect($models)->toHaveCount(2);
    expect($models->first())->toBeInstanceOf(ModelInfo::class);
    expect($models->pluck('name')->all())->toBe(['BAAI/bge-m3', 'docling']);
});

it('reads the model catalog live on every call', function () {
    $catalog = MockResponse::make(['models' => [['name' => 'BAAI/bge-m3']]], 200);
    $mock = MockClient::global([$catalog, $catalog]);

    SIE::models();
    SIE::models();

    $mock->assertSentCount(2);
});

it('exposes the underlying saloon connector', function () {
    expect(SIE::raw())->toBeInstanceOf(SieConnector::class);
    expect(SIE::raw()->resolveBaseUrl())->toBe('https://sie.test');
});

it('exposes pool management off the fluent surface', function () {
    expect(SIE::pools())->toBeInstanceOf(Pools::class);
});
