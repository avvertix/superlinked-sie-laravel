<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\PoolInfo;
use Sie\Client\Exceptions\PoolException;
use Sie\Client\Requests\Pools\CreatePoolRequest;
use Sie\Client\Requests\Pools\DeletePoolRequest;
use Sie\Client\Requests\Pools\GetPoolRequest;
use Sie\Client\Requests\Pools\RenewPoolLeaseRequest;
use Sie\Client\SieClient;

afterEach(fn () => MockClient::destroyGlobal());

it('creates a pool, sending only the fields that were set', function () {
    $mock = MockClient::global([
        MockResponse::make(['name' => 'eval', 'status' => ['state' => 'pending']], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $client->createPool('eval', gpus: ['l4' => 2], bundle: 'default', minimumWorkerCount: 1);

    $mock->assertSent(function (CreatePoolRequest $request): bool {
        expect($request->resolveEndpoint())->toBe('/v1/pools');
        $body = $request->body()->all();
        expect($body)->toBe(['name' => 'eval', 'gpus' => ['l4' => 2], 'bundle' => 'default', 'minimum_worker_count' => 1]);

        return true;
    });
});

it('rejects a negative minimumWorkerCount before sending anything', function () {
    $mock = MockClient::global([]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->createPool('eval', minimumWorkerCount: -1))
        ->toThrow(InvalidArgumentException::class);

    $mock->assertNothingSent();
});

it('raises a PoolException with the parsed server message on create failure', function () {
    MockClient::global([MockResponse::make(['detail' => ['message' => 'invalid machine profile']], 400)]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->createPool('eval', gpus: ['bogus-gpu' => 1]))
        ->toThrow(PoolException::class, 'invalid machine profile');
});

it('gets a pool and parses its nested spec/status', function () {
    MockClient::global([
        MockResponse::make([
            'name' => 'eval',
            'spec' => ['gpus' => ['l4' => 2]],
            'status' => ['state' => 'active', 'assigned_workers' => [['name' => 'w1', 'url' => 'http://w1', 'gpu' => 'l4', 'bundle' => 'default']]],
        ], 200),
    ]);

    $client = new SieClient('https://sie.test');
    $pool = $client->getPool('eval');

    expect($pool)->toBeInstanceOf(PoolInfo::class);
    expect($pool->spec->gpus)->toBe(['l4' => 2]);
    expect($pool->status->state)->toBe('active');
    expect($pool->status->assignedWorkers[0]->name)->toBe('w1');
});

it('returns null when the pool does not exist', function () {
    MockClient::global([MockResponse::make([], 404)]);

    $client = new SieClient('https://sie.test');

    expect($client->getPool('missing'))->toBeNull();
});

it('deletes a pool, returning true/false based on existence', function () {
    $mock = MockClient::global([
        MockResponse::make([], 200),
        MockResponse::make([], 404),
    ]);

    $client = new SieClient('https://sie.test');

    expect($client->deletePool('eval'))->toBeTrue();
    expect($client->deletePool('eval'))->toBeFalse();

    $mock->assertSent(DeletePoolRequest::class);
});

it('renews a pool lease via an explicit call (no background thread)', function () {
    $mock = MockClient::global([MockResponse::make([], 200)]);

    $client = new SieClient('https://sie.test');
    $client->renewPoolLease('eval');

    $mock->assertSent(function (RenewPoolLeaseRequest $request): bool {
        expect($request->resolveEndpoint())->toBe('/v1/pools/eval/renew');

        return true;
    });
});

it('raises a PoolException when lease renewal fails', function () {
    MockClient::global([MockResponse::make(['detail' => 'pool expired'], 410)]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->renewPoolLease('eval'))->toThrow(PoolException::class, 'pool expired');
});

it('gets a pool without connecting, purely via GetPoolRequest path', function () {
    $mock = MockClient::global([MockResponse::make(['name' => 'eval', 'spec' => [], 'status' => []], 200)]);

    $client = new SieClient('https://sie.test');
    $client->getPool('eval');

    $mock->assertSent(function (GetPoolRequest $request): bool {
        expect($request->resolveEndpoint())->toBe('/v1/pools/eval');

        return true;
    });
});
