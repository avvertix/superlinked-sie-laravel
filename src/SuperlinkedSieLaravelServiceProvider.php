<?php

declare(strict_types=1);

namespace Sie;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Sie\Ai\SieProvider;
use Sie\Client\Support\Clock;
use Sie\Client\Support\Sleeper;
use Sie\Client\Support\SystemClock;
use Sie\Console\Commands\ListModelsCommand;

class SuperlinkedSieLaravelServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/superlinked-sie-laravel.php', 'superlinked-sie-laravel');

        $this->app->singleton(SieManager::class, fn (Application $app): SieManager => new SieManager($app));

        // Bound rather than constructed inside the client so SIE::fake() can
        // swap in a time source that makes the retry ladder instant.
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(Sleeper::class, LaravelSleeper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAiDriver();

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ListModelsCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/superlinked-sie-laravel.php' => config_path('superlinked-sie-laravel.php'),
        ], ['superlinked-sie-laravel', 'superlinked-sie-laravel-config']);
    }

    /**
     * Teach laravel/ai about the "sie" driver, when laravel/ai is installed.
     *
     * Registering the driver is safe — it only makes the name resolvable.
     * Inventing a `providers.sie` entry in config/ai.php would not be, so
     * applications still opt in by adding one themselves.
     */
    private function registerAiDriver(): void
    {
        if (! class_exists(AiManager::class)) {
            return;
        }

        $this->callAfterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('sie', fn (Application $app, array $config): SieProvider => new SieProvider(
                $config,
                $app->make(Dispatcher::class),
            ));
        });
    }
}
