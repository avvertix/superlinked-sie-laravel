<?php

declare(strict_types=1);

namespace Sie;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Data\CapacityInfo;
use Sie\Client\Data\ModelInfo;
use Sie\Client\SieClient;
use Sie\Client\Support\WireFormat;

/**
 * One configured SIE endpoint — a URL plus its credentials.
 *
 * An application may define several **Connections**; one is the default. A
 * connection owns exactly one client, built lazily so that merely resolving the
 * manager never opens a socket.
 */
final class Connection
{
    private ?SieClient $client = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly string $name,
        private readonly array $config,
    ) {}

    public function client(): SieClient
    {
        return $this->client ??= new SieClient(
            baseUrl: $this->url(),
            timeoutS: (float) ($this->config['timeout'] ?? 900),
            apiKey: is_string($this->config['key'] ?? null) ? $this->config['key'] : null,
            gpu: $this->defaultRouting(),
            format: $this->format(),
        );
    }

    /**
     * The underlying Saloon connector, for requests this package does not model.
     */
    public function raw(): SieConnector
    {
        return new SieConnector(
            $this->url(),
            (float) ($this->config['timeout'] ?? 900),
            is_string($this->config['key'] ?? null) ? $this->config['key'] : null,
        );
    }

    /** Begin a request against a model on this connection. */
    public function model(string $model): PendingRequest
    {
        return new PendingRequest($this->name, $model);
    }

    /**
     * The wire format this connection speaks, msgpack unless configured
     * otherwise.
     */
    public function format(): WireFormat
    {
        $format = $this->config['format'] ?? null;

        return WireFormat::fromName(is_string($format) ? $format : null);
    }

    /**
     * Every model this cluster can serve, with its declared inputs, outputs,
     * and dimensions.
     *
     * Cached, because the catalog changes when a cluster is redeployed rather
     * than per request. Pass `fresh: true` to bypass the cache.
     *
     * @return Collection<int, ModelInfo>
     */
    public function models(bool $fresh = false): Collection
    {
        $ttl = (int) $this->packageConfig('catalog.ttl', 3600);

        if ($fresh || $ttl <= 0) {
            return new Collection($this->client()->listModels());
        }

        $store = $this->packageConfig('catalog.store', null);
        $key = "superlinked-sie:catalog:{$this->name}";

        /** @var list<ModelInfo> $models */
        $models = Cache::store(is_string($store) ? $store : null)->remember(
            $key,
            $ttl,
            fn (): array => $this->client()->listModels(),
        );

        return new Collection($models);
    }

    public function capacity(?string $gpu = null): CapacityInfo
    {
        return $this->client()->getCapacity($gpu);
    }

    /**
     * Block until capacity is available for `$gpu`.
     *
     * This waits for workers only — it does not load a model. Loading a model
     * ahead of traffic needs a probe input the model actually accepts, so it
     * lives on `SIE::model(...)->warmup($probe)` instead.
     */
    public function waitForCapacity(string $gpu, ?float $timeoutS = null): CapacityInfo
    {
        return $this->client()->waitForCapacity($gpu, timeoutS: $timeoutS);
    }

    public function pools(): Pools
    {
        return new Pools($this->client());
    }

    private function url(): string
    {
        $url = $this->config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                "The [{$this->name}] SIE connection has no url. Set SIE_ENDPOINT, or the connection's url in config/superlinked-sie-laravel.php.",
            );
        }

        return $url;
    }

    /**
     * The connection's default routing, folded into the slash-separated form
     * the client takes.
     */
    private function defaultRouting(): ?string
    {
        $pool = $this->config['pool'] ?? null;
        $gpu = $this->config['gpu'] ?? null;

        return match (true) {
            is_string($pool) && $pool !== '' && is_string($gpu) && $gpu !== '' => "{$pool}/{$gpu}",
            is_string($pool) && $pool !== '' => "{$pool}/",
            is_string($gpu) && $gpu !== '' => $gpu,
            default => null,
        };
    }

    private function packageConfig(string $key, mixed $default): mixed
    {
        /** @var Repository $config */
        $config = Container::getInstance()->make('config');

        return $config->get("superlinked-sie-laravel.{$key}", $default);
    }
}
