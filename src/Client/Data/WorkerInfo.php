<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * Information about a single worker in the cluster.
 *
 * `getCapacity()` only populates `url`/`gpu`/`healthy`/`queueDepth`/`loadedModels`
 * (matching the Python SDK); the remaining fields exist for parity with the
 * wire type but are only ever null via this client, since `watch()`
 * (the only source of the fuller shape) is out of scope for this port.
 */
final class WorkerInfo
{
    /**
     * @param  ?list<string>  $loadedModels
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $url = null,
        public readonly ?string $gpu = null,
        public readonly ?int $gpuCount = null,
        public readonly ?bool $healthy = null,
        public readonly ?int $queueDepth = null,
        public readonly ?array $loadedModels = null,
        public readonly ?int $memoryUsedBytes = null,
        public readonly ?int $memoryTotalBytes = null,
        public readonly ?string $bundle = null,
        public readonly ?string $bundleConfigHash = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            url: $data['url'] ?? null,
            gpu: $data['gpu'] ?? null,
            gpuCount: $data['gpu_count'] ?? null,
            healthy: $data['healthy'] ?? null,
            queueDepth: $data['queue_depth'] ?? null,
            loadedModels: $data['loaded_models'] ?? null,
            memoryUsedBytes: $data['memory_used_bytes'] ?? null,
            memoryTotalBytes: $data['memory_total_bytes'] ?? null,
            bundle: $data['bundle'] ?? null,
            bundleConfigHash: $data['bundle_config_hash'] ?? null,
        );
    }
}
