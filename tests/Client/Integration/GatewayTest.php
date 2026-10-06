<?php

declare(strict_types=1);

use Sie\Client\Data\CapacityInfo;

it('reads cluster capacity from /health', function () {
    $capacity = sieClient()->getCapacity();

    // Zero workers is a legitimate answer, not a failure: this cluster is
    // spot-backed and scales from zero, which is exactly the state
    // waitForCapacity() polls for. Assert we parsed a capacity response.
    expect($capacity)->toBeInstanceOf(CapacityInfo::class)
        ->and($capacity->workerCount)->toBeInt()
        ->and($capacity->workerCount)->toBeGreaterThanOrEqual(0);
})->skip('Require a SIE gateway');

it('attaches the gateway request id to a real encode result', function () {
    // Proves case-insensitive header lookup against the real wire: the gateway
    // sends `x-sie-request-id` lowercased over HTTP/1.1.
    $result = sieClient()->encode('BAAI/bge-m3', ['text' => 'metadata probe']);

    expect($result->request)->not->toBeNull()
        ->and($result->request->id)->toBeString()
        ->and($result->request->id)->not->toBeEmpty();
})->skip('Require a SIE gateway');
