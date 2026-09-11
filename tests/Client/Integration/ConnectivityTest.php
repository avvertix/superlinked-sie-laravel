<?php

declare(strict_types=1);

use Sie\Client\Data\CapacityInfo;
use Sie\Client\Data\ModelInfo;

/*
 * Read-only checks against the live instance. Free — no inference is dispatched,
 * so these run whenever SIE_ENDPOINT is set.
 */

it('reads cluster capacity from /health', function () {
    $capacity = sieClient()->getCapacity();

    // Zero workers is a legitimate answer, not a failure: this cluster is
    // spot-backed and scales from zero, which is exactly the state
    // waitForCapacity() polls for. Assert we parsed a capacity response.
    expect($capacity)->toBeInstanceOf(CapacityInfo::class)
        ->and($capacity->workerCount)->toBeInt()
        ->and($capacity->workerCount)->toBeGreaterThanOrEqual(0);
})->skip('Require a SIE gateway');

it('fetches a single model in the native shape', function () {
    // /v1/models/{model} returns the native envelope: name, dims, inputs,
    // outputs, loaded, capabilities.
    $client = sieClient();

    $name = firstModelMatching(
        $client,
        ['NeuML/gliner-bert-tiny', 'urchade/gliner_multi-v2.1'],
        static fn (ModelInfo $m): bool => in_array('text', $m->inputs ?? [], true) && in_array('json', $m->outputs ?? [], true),
    );

    $model = $client->getModel($name);

    expect($model)->toBeInstanceOf(ModelInfo::class)
        ->and($model->name)->toBe($name)
        ->and($model->inputs)->toContain('text')
        ->and($model->outputs)->toContain('json');
});

it('lists models', function () {
    // /v1/models carries both envelopes — `data` (OpenAI shape) and `models`
    // (native, with `name`). ModelsResource reads `models`, so this pins the
    // key the client actually depends on rather than the one that happens to
    // serialize first.
    $models = sieClient()->listModels();

    expect($models)->not->toBeEmpty()
        ->and($models)->each->toBeInstanceOf(ModelInfo::class);

    $names = array_map(static fn (ModelInfo $m): string => $m->name, $models);

    expect($names)->toContain('BAAI/bge-m3');
});

it('attaches the gateway request id to a real encode result', function () {
    requiresBillableCalls();

    // Proves case-insensitive header lookup against the real wire: the gateway
    // sends `x-sie-request-id` lowercased over HTTP/1.1.
    $result = sieClient()->encode('BAAI/bge-m3', ['text' => 'metadata probe']);

    expect($result->request)->not->toBeNull()
        ->and($result->request->id)->toBeString()
        ->and($result->request->id)->not->toBeEmpty();
})->group('billable')->skip('Require a SIE gateway');
