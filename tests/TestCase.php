<?php

declare(strict_types=1);

namespace Sie\Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Sie\SuperlinkedSieLaravelServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            // The bridge is optional, so laravel/ai is only booted when present.
            class_exists(AiServiceProvider::class) ? AiServiceProvider::class : null,
            SuperlinkedSieLaravelServiceProvider::class,
        ]));
    }

    /**
     * Point the default connection at a host no test may actually reach, so a
     * missing mock surfaces as a connection error rather than as real traffic.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('superlinked-sie-laravel.connections.default', [
            'url' => 'https://sie.test',
            'key' => 'test-key',
            'timeout' => 30,
        ]);

        $app['config']->set('superlinked-sie-laravel.catalog.ttl', 0);

        // The model catalog is cached, and a file-backed store would keep that
        // entry between test runs — making any test that counts catalog
        // requests pass once and then fail for the rest of the ttl.
        $app['config']->set('cache.default', 'array');
    }
}
