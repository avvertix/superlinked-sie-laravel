<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Pool specification as echoed back by the gateway. */
final class PoolSpecResponse
{
    /**
     * @param  ?array<string, int>  $gpus
     * @param  ?array<string, int>  $gpuCaps
     * @param  ?list<string>  $pinnedModels
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $queuePool = null,
        public readonly ?array $gpus = null,
        public readonly ?array $gpuCaps = null,
        public readonly ?string $bundle = null,
        public readonly ?int $minimumWorkerCount = null,
        public readonly ?array $pinnedModels = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            queuePool: $data['queue_pool'] ?? null,
            gpus: $data['gpus'] ?? null,
            gpuCaps: $data['gpu_caps'] ?? null,
            bundle: $data['bundle'] ?? null,
            minimumWorkerCount: $data['minimum_worker_count'] ?? null,
            pinnedModels: $data['pinned_models'] ?? null,
        );
    }
}
