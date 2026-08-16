<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Laravel\Ai\AiManager;
use Sie\Ai\SieProvider;
use Sie\Facades\SIE;
use Sie\SieManager;
use Sie\SuperlinkedSieLaravelServiceProvider;

it('resolves the manager as a singleton', function () {
    expect(app(SieManager::class))->toBeInstanceOf(SieManager::class);
    expect(app(SieManager::class))->toBe(app(SieManager::class));
});

it('merges the package config', function () {
    expect(Config::get('superlinked-sie-laravel.connection'))->toBe('default');
    expect(Config::get('superlinked-sie-laravel.max_request_bytes'))->toBeInt();
});

it('resolves the facade to the manager', function () {
    expect(SIE::getFacadeRoot())->toBeInstanceOf(SieManager::class);
});

it('publishes the config under both tags', function () {
    $paths = SuperlinkedSieLaravelServiceProvider::pathsToPublish(
        SuperlinkedSieLaravelServiceProvider::class,
        'superlinked-sie-laravel-config',
    );

    expect($paths)->not->toBeEmpty();
    expect(array_values($paths)[0])->toEndWith('superlinked-sie-laravel.php');
});

it('registers the sie driver with laravel/ai when it is installed', function () {
    if (! class_exists(AiManager::class)) {
        test()->markTestSkipped('laravel/ai is not installed.');
    }

    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);

    expect(app(AiManager::class)->instance('sie'))->toBeInstanceOf(SieProvider::class);
});
