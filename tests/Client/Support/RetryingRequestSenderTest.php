<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Exceptions\LoraLoadingException;
use Sie\Client\Exceptions\ModelLoadFailedException;
use Sie\Client\Exceptions\ModelLoadingException;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ResourceExhaustedException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\RetryPolicy;
use Sie\Tests\Client\Fixtures\FakeClock;
use Sie\Tests\Client\Fixtures\PingRequest;
use Sie\Tests\Client\Fixtures\RecordingSleeper;

function requestFactory(?FakeClock $clock = null, float $advancePerAttempt = 0.0): callable
{
    return function () use ($clock, $advancePerAttempt) {
        $clock?->advance($advancePerAttempt);

        return new PingRequest;
    };
}

it('returns the response on first success without sleeping', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([MockResponse::make(['ok' => true], 200)]);
    $connector->withMockClient($mock);

    $clock = new FakeClock;
    $sleeper = new RecordingSleeper($clock);
    $sender = new RetryingRequestSender($connector, $clock, $sleeper);

    $response = $sender->send(requestFactory(), new RetryPolicy, 'bge-m3', null, true, 30.0);

    expect($response->status())->toBe(200);
    expect($sleeper->sleeps)->toBe([]);
    $mock->assertSentCount(1);
});

it('retries a 503 PROVISIONING response honouring Retry-After, then succeeds', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([
        MockResponse::make(['error' => ['code' => 'PROVISIONING']], 503, ['Retry-After' => '3']),
        MockResponse::make(['ok' => true], 200),
    ]);
    $connector->withMockClient($mock);

    $clock = new FakeClock;
    $sleeper = new RecordingSleeper($clock);
    $sender = new RetryingRequestSender($connector, $clock, $sleeper);

    $response = $sender->send(requestFactory(), new RetryPolicy, 'bge-m3', 'l4', true, 30.0);

    expect($response->status())->toBe(200);
    expect($sleeper->sleeps)->toBe([3.0]);
    $mock->assertSentCount(2);
});

it('raises ProvisioningException immediately when waitForCapacity is false', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([MockResponse::make(['error' => ['code' => 'PROVISIONING']], 503)]);
    $connector->withMockClient($mock);

    $sender = new RetryingRequestSender($connector, new FakeClock, new RecordingSleeper);

    expect(fn () => $sender->send(requestFactory(), new RetryPolicy, 'bge-m3', 'l4', false, 30.0))
        ->toThrow(ProvisioningException::class);

    $mock->assertSentCount(1);
});

it('retries 503 LORA_LOADING only when the policy allows it, up to the retry limit', function () {
    $responses = array_fill(0, 11, MockResponse::make(['error' => ['code' => 'LORA_LOADING']], 503));
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient($responses);
    $connector->withMockClient($mock);

    $clock = new FakeClock;
    $sender = new RetryingRequestSender($connector, $clock, new RecordingSleeper($clock));
    $policy = new RetryPolicy(allowLoraRetry: true);

    expect(fn () => $sender->send(requestFactory(), $policy, 'bge-m3', null, true, 3600.0))
        ->toThrow(LoraLoadingException::class);

    $mock->assertSentCount(11); // 1 initial + 10 retries before giving up
});

it('does not retry 503 LORA_LOADING when the policy disallows it (e.g. score/extract)', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([MockResponse::make(['error' => ['code' => 'LORA_LOADING']], 503)]);
    $connector->withMockClient($mock);

    $sender = new RetryingRequestSender($connector, new FakeClock, new RecordingSleeper);

    // 503 is a server-error status, so an un-retried LORA_LOADING code still surfaces via the generic 5xx branch.
    expect(fn () => $sender->send(requestFactory(), new RetryPolicy(allowLoraRetry: false), 'bge-m3', null, true, 30.0))
        ->toThrow(ServerException::class);

    $mock->assertSentCount(1);
});

it('raises ModelLoadingException once the provision timeout budget is exhausted', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([
        MockResponse::make(['error' => ['code' => 'MODEL_LOADING']], 503),
        MockResponse::make(['error' => ['code' => 'MODEL_LOADING']], 503),
    ]);
    $connector->withMockClient($mock);

    $clock = new FakeClock;
    $sleeper = new RecordingSleeper($clock);
    $sender = new RetryingRequestSender($connector, $clock, $sleeper);

    // Each attempt "costs" 0.1s of wall-clock time, so by the second response
    // elapsed (5.2s) has cleared the 5.15s timeout with margin to spare
    // (avoiding a razor-thin float-precision boundary), forcing the branch's
    // own elapsed>=timeout check rather than the generic pre-send one.
    expect(fn () => $sender->send(requestFactory($clock, 0.1), new RetryPolicy, 'bge-m3', null, true, 5.15))
        ->toThrow(ModelLoadingException::class);

    $mock->assertSentCount(2);
});

it('short-circuits on 502 MODEL_LOAD_FAILED without consuming any retry budget', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([
        MockResponse::make(['error' => ['code' => 'MODEL_LOAD_FAILED', 'message' => 'gated', 'permanent' => true]], 502),
    ]);
    $connector->withMockClient($mock);

    $sender = new RetryingRequestSender($connector, new FakeClock, new RecordingSleeper);

    expect(fn () => $sender->send(requestFactory(), new RetryPolicy, 'bge-m3', null, true, 900.0))
        ->toThrow(ModelLoadFailedException::class, 'gated');

    $mock->assertSentCount(1);
});

it('retries 503 RESOURCE_EXHAUSTED with backoff up to maxOomRetries, then raises', function () {
    $responses = array_fill(0, 4, MockResponse::make(['error' => ['code' => 'RESOURCE_EXHAUSTED']], 503, ['Retry-After' => '0']));
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient($responses);
    $connector->withMockClient($mock);

    $clock = new FakeClock;
    $sender = new RetryingRequestSender($connector, $clock, new RecordingSleeper($clock));

    expect(fn () => $sender->send(requestFactory(), new RetryPolicy(maxOomRetries: 3), 'bge-m3', null, true, 3600.0))
        ->toThrow(ResourceExhaustedException::class);

    $mock->assertSentCount(4); // 1 initial + 3 retries before giving up
});

it('retries a 504 only when the policy allows it (encode/score/extract, not generate/chat)', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([
        MockResponse::make([], 504),
        MockResponse::make(['ok' => true], 200),
    ]);
    $connector->withMockClient($mock);

    $clock = new FakeClock;
    $sender = new RetryingRequestSender($connector, $clock, new RecordingSleeper($clock));

    $response = $sender->send(requestFactory(), new RetryPolicy(retryOn504: true), 'bge-m3', null, true, 30.0);

    expect($response->status())->toBe(200);
    $mock->assertSentCount(2);
});

it('treats a 504 as terminal when the policy disallows retrying it', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([MockResponse::make([], 504)]);
    $connector->withMockClient($mock);

    $sender = new RetryingRequestSender($connector, new FakeClock, new RecordingSleeper);

    expect(fn () => $sender->send(requestFactory(), new RetryPolicy(retryOn504: false), 'llama-3', null, true, 30.0))
        ->toThrow(ServerException::class);

    $mock->assertSentCount(1);
});

it('never retries a plain 4xx/5xx that carries none of the known retry codes', function () {
    $connector = new SieConnector('https://example.test');
    $mock = new MockClient([MockResponse::make(['detail' => ['message' => 'nope']], 404)]);
    $connector->withMockClient($mock);

    $sender = new RetryingRequestSender($connector, new FakeClock, new RecordingSleeper);

    expect(fn () => $sender->send(requestFactory(), new RetryPolicy, 'bge-m3', null, true, 30.0))
        ->toThrow(RequestException::class, 'nope');

    $mock->assertSentCount(1);
});
