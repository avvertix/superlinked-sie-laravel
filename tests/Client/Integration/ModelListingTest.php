<?php

declare(strict_types=1);

use Sie\Client\Data\ModelInfo;

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
