<?php

declare(strict_types=1);

use Sie\Client\Data\ModelCapabilities;
use Sie\Client\Data\ModelDims;
use Sie\Client\Data\ModelInfo;

/** Every field populated, in the gateway's own wire shape. */
function fullCatalogEntry(): array
{
    return [
        'name' => 'BAAI/bge-m3',
        'loaded' => true,
        'inputs' => ['text'],
        'outputs' => ['dense', 'sparse'],
        'dims' => ['dense' => 1024, 'sparse' => 250_002, 'multivector' => null],
        'max_sequence_length' => 8192,
        'capabilities' => [
            'grammar' => ['json'],
            'tools' => true,
            'lora_adapters' => ['a'],
            'profile_lora_adapters' => ['fast' => ['a']],
            'code' => false,
            'sql' => null,
            'guard' => true,
        ],
    ];
}

it('round-trips a catalog entry through toArray and back', function () {
    $entry = fullCatalogEntry();

    $model = ModelInfo::fromArray($entry);

    expect($model->toArray())->toBe($entry);
    expect(ModelInfo::fromArray($model->toArray()))->toEqual($model);
});

it('flattens nested dims and capabilities into arrays', function () {
    // The point of toArray(): nothing below the top level stays an object,
    // or the payload is no more storable than the object it came from.
    $array = ModelInfo::fromArray(fullCatalogEntry())->toArray();

    expect($array['dims'])->toBeArray()
        ->and($array['capabilities'])->toBeArray();
});

it('keeps absent dims and capabilities null rather than inventing empty ones', function () {
    $model = ModelInfo::fromArray(['name' => 'docling', 'inputs' => ['document']]);

    expect($model->toArray())->toBe([
        'name' => 'docling',
        'loaded' => null,
        'inputs' => ['document'],
        'outputs' => null,
        'dims' => null,
        'max_sequence_length' => null,
        'capabilities' => null,
    ]);
});

it('survives a store that refuses to unserialize objects', function () {
    // Laravel 13 reads every cache entry through
    // unserialize($value, ['allowed_classes' => $this->serializableClasses]),
    // and a default app sets cache.serializable_classes to false. An array
    // payload is unaffected; the objects come back __PHP_Incomplete_Class.
    $models = array_map(ModelInfo::fromArray(...), [fullCatalogEntry()]);

    $asArrays = array_map(static fn (ModelInfo $m): array => $m->toArray(), $models);

    $restored = unserialize(serialize($asArrays), ['allowed_classes' => false]);

    expect($restored)->toBe($asArrays);
    expect(ModelInfo::fromArray($restored[0])->dims->dense)->toBe(1024);

    $brokenIfCachedAsObjects = unserialize(serialize($models), ['allowed_classes' => false]);

    expect($brokenIfCachedAsObjects[0])->toBeInstanceOf(__PHP_Incomplete_Class::class);
});

it('json encodes without any manual conversion', function () {
    $model = ModelInfo::fromArray(fullCatalogEntry());

    expect(json_decode(json_encode($model), true))->toBe(fullCatalogEntry());
});

it('json encodes a whole catalog collection', function () {
    $models = collect([
        ModelInfo::fromArray(['name' => 'a']),
        ModelInfo::fromArray(['name' => 'b']),
    ]);

    expect(json_decode($models->toJson(), true))->toHaveCount(2)
        ->and(json_decode($models->toJson(), true)[0]['name'])->toBe('a');
});

it('round-trips dims on their own', function () {
    $dims = ['dense' => 1024, 'sparse' => null, 'multivector' => 16];

    expect(ModelDims::fromArray($dims)->toArray())->toBe($dims);
});

it('round-trips capabilities on their own, keeping the wire snake_case', function () {
    $capabilities = [
        'grammar' => ['json', 'regex'],
        'tools' => true,
        'lora_adapters' => ['adapter-a'],
        'profile_lora_adapters' => ['fast' => ['adapter-a']],
        'code' => true,
        'sql' => false,
        'guard' => null,
    ];

    expect(ModelCapabilities::fromArray($capabilities)->toArray())->toBe($capabilities);
});
