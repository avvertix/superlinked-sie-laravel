<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Cluster capacity information, returned by `SieClient::getCapacity()`. */
final class CapacityInfo
{
    /**
     * @param  ?list<string>  $configuredGpuTypes
     * @param  ?list<string>  $liveGpuTypes
     * @param  list<WorkerInfo>  $workers
     */
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?int $workerCount = null,
        public readonly ?int $gpuCount = null,
        public readonly ?int $modelsLoaded = null,
        public readonly ?array $configuredGpuTypes = null,
        public readonly ?array $liveGpuTypes = null,
        public readonly array $workers = [],
    ) {}
}
