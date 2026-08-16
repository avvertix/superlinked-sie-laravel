<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Data\CapacityInfo;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\SieClient;
use Sie\Tests\Client\Fixtures\FakeClock;
use Sie\Tests\Client\Fixtures\RecordingSleeper;

afterEach(fn () => MockClient::destroyGlobal());

function gatewayHealth(array $overrides = []): array
{
    return array_replace([
        'type' => 'gateway',
        'status' => 'healthy',
        'cluster' => ['worker_count' => 2, 'gpu_count' => 4, 'models_loaded' => 1],
        'workers' => [
            ['url' => 'http://w1', 'gpu' => 'l4', 'healthy' => true, 'queue_depth' => 0, 'loaded_models' => ['bge-m3']],
            ['url' => 'http://w2', 'gpu' => 'a100-80gb', 'healthy' => true, 'queue_depth' => 1, 'loaded_models' => []],
        ],
    ], $overrides);
}

it('parses capacity info from /health', function () {
    MockClient::global([MockResponse::make(gatewayHealth(), 200)]);

    $client = new SieClient('https://sie.test');
    $capacity = $client->getCapacity();

    expect($capacity)->toBeInstanceOf(CapacityInfo::class);
    expect($capacity->workerCount)->toBe(2);
    expect($capacity->workers)->toHaveCount(2);
    expect($capacity->workers[0]->gpu)->toBe('l4');
});

it('filters workers by gpu (case-insensitive) and recomputes worker_count', function () {
    MockClient::global([MockResponse::make(gatewayHealth(), 200)]);

    $client = new SieClient('https://sie.test');
    $capacity = $client->getCapacity('L4');

    expect($capacity->workers)->toHaveCount(1);
    expect($capacity->workers[0]->gpu)->toBe('l4');
    expect($capacity->workerCount)->toBe(1);
});

it('rejects a non-gateway endpoint', function () {
    MockClient::global([MockResponse::make(['type' => 'worker', 'status' => 'healthy'], 200)]);

    $client = new SieClient('https://sie.test');

    expect(fn () => $client->getCapacity())->toThrow(RequestException::class);
});

it('waitForCapacity polls until a worker is available', function () {
    $mock = MockClient::global([
        MockResponse::make(gatewayHealth(['cluster' => ['worker_count' => 0, 'gpu_count' => 0, 'models_loaded' => 0], 'workers' => []]), 200),
        MockResponse::make(gatewayHealth(), 200),
    ]);

    $clock = new FakeClock;
    $client = new SieClient('https://sie.test', clock: $clock, sleeper: new RecordingSleeper($clock));

    $capacity = $client->waitForCapacity('l4', timeoutS: 30.0, pollIntervalS: 1.0);

    // getCapacity('l4') filters to the l4 worker only, so workerCount reflects the filtered count (1), not the raw cluster count (2).
    expect($capacity->workerCount)->toBe(1);
    $mock->assertSentCount(2);
});

it('waitForCapacity raises ProvisioningException once the timeout elapses', function () {
    $empty = gatewayHealth(['cluster' => ['worker_count' => 0, 'gpu_count' => 0, 'models_loaded' => 0], 'workers' => []]);
    $mock = MockClient::global(array_fill(0, 4, MockResponse::make($empty, 200)));

    $clock = new FakeClock;
    $client = new SieClient('https://sie.test', clock: $clock, sleeper: new RecordingSleeper($clock));

    expect(fn () => $client->waitForCapacity('l4', timeoutS: 3.0, pollIntervalS: 1.0))
        ->toThrow(ProvisioningException::class);

    $mock->assertSentCount(4);
});
