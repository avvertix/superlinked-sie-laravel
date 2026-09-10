<?php

declare(strict_types=1);

namespace Sie;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\MultipleInstanceManager;
use RuntimeException;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Data\CapacityInfo;
use Sie\Client\Data\ModelInfo;
use Sie\Testing\FakeModel;
use Sie\Testing\FakeSie;

/**
 * Resolves named **Connections**.
 *
 * Built on `MultipleInstanceManager` — the same base `laravel/ai`'s own manager
 * uses — so a future release can add alternative drivers (fallback,
 * load-balancing) without reshaping the config.
 *
 * @method \Sie\Client\SieClient client()
 */
final class SieManager extends MultipleInstanceManager
{
    /**
     * @var string
     */
    protected $driverKey = 'driver';

    private ?FakeSie $fake = null;

    public function getDefaultInstance(): string
    {
        /** @var string $default */
        $default = $this->config()->get('superlinked-sie-laravel.connection', 'default');

        return $default;
    }

    /**
     * @param  string  $name
     */
    public function setDefaultInstance($name): void
    {
        $this->config()->set('superlinked-sie-laravel.connection', $name);
    }

    /**
     * @param  string  $name
     * @return ?array<string, mixed>
     */
    public function getInstanceConfig($name): ?array
    {
        $config = $this->config()->get("superlinked-sie-laravel.connections.{$name}");

        if (! is_array($config)) {
            return null;
        }

        // Every connection speaks to a SIE cluster unless it says otherwise, so
        // applications never have to write the driver out by hand.
        return ['driver' => 'sie', ...$config, 'name' => $name];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function createSieDriver(array $config): Connection
    {
        /** @var string $name */
        $name = $config['name'] ?? 'default';

        return new Connection($name, $config);
    }

    public function connection(?string $name = null): Connection
    {
        /** @var Connection $connection */
        $connection = $this->instance($name);

        return $connection;
    }

    /** Begin a request against a model on the default connection. */
    public function model(string $model): PendingRequest
    {
        return $this->connection()->model($model);
    }

    /**
     * @return Collection<int, ModelInfo>
     */
    public function models(): Collection
    {
        return $this->connection()->models();
    }

    public function capacity(?string $gpu = null): CapacityInfo
    {
        return $this->connection()->capacity($gpu);
    }

    public function waitForCapacity(string $gpu, ?float $timeoutS = null): CapacityInfo
    {
        return $this->connection()->waitForCapacity($gpu, $timeoutS);
    }

    public function pools(): Pools
    {
        return $this->connection()->pools();
    }

    /**
     * The underlying Saloon connector for the default connection, for requests
     * this package does not model.
     */
    public function raw(): SieConnector
    {
        return $this->connection()->raw();
    }

    /**
     * Answer every request locally instead of reaching a cluster.
     *
     * @param  array<string, FakeModel>  $models  Model id => how it should answer.
     */
    public function fake(array $models = []): FakeSie
    {
        return $this->fake = new FakeSie($models);
    }

    public function assertEncoded(string $model, ?Closure $callback = null): void
    {
        $this->faked()->assertEncoded($model, $callback);
    }

    public function assertScored(string $model, ?Closure $callback = null): void
    {
        $this->faked()->assertScored($model, $callback);
    }

    public function assertExtracted(string $model, ?Closure $callback = null): void
    {
        $this->faked()->assertExtracted($model, $callback);
    }

    public function assertGenerated(string $model, ?Closure $callback = null): void
    {
        $this->faked()->assertGenerated($model, $callback);
    }

    public function assertNothingSent(): void
    {
        $this->faked()->assertNothingSent();
    }

    public function assertSentCount(int $count): void
    {
        $this->faked()->assertSentCount($count);
    }

    private function config(): Repository
    {
        /** @var Repository $config */
        $config = $this->app->make('config');

        return $config;
    }

    private function faked(): FakeSie
    {
        if ($this->fake === null) {
            throw new RuntimeException('SIE is not faked. Call SIE::fake() before asserting against it.');
        }

        return $this->fake;
    }
}
