<?php

declare(strict_types=1);

namespace Sie\Client\Resources;

use Sie\Client\Data\CapacityInfo;
use Sie\Client\Data\WorkerInfo;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Requests\Health\GetHealthRequest;
use Sie\Client\Support\SimpleRequestSender;
use Sie\Client\Support\WireList;

final class CapacityResource
{
    public function __construct(private readonly SimpleRequestSender $sender) {}

    public function getCapacity(?string $gpu): CapacityInfo
    {
        $response = $this->sender->send(new GetHealthRequest);
        $data = $response->json();

        if (($data['type'] ?? null) !== 'gateway') {
            throw new RequestException(
                "get_capacity() requires a gateway endpoint (got type={$this->describeType($data)})",
                errorCode: 'not_gateway',
                statusCode: 400,
            );
        }

        $workers = array_map(
            static fn (array $worker): WorkerInfo => new WorkerInfo(
                url: $worker['url'] ?? null,
                gpu: $worker['gpu'] ?? null,
                healthy: $worker['healthy'] ?? null,
                queueDepth: $worker['queue_depth'] ?? null,
                loadedModels: $worker['loaded_models'] ?? null,
            ),
            WireList::of($data['workers'] ?? null, 'workers'),
        );

        if ($gpu !== null) {
            $needle = strtolower($gpu);
            $workers = array_values(array_filter(
                $workers,
                static fn (WorkerInfo $worker): bool => $worker->gpu !== null && strtolower($worker->gpu) === $needle,
            ));
        }

        $cluster = $data['cluster'] ?? [];

        return new CapacityInfo(
            status: $data['status'] ?? null,
            workerCount: $gpu !== null ? count($workers) : ($cluster['worker_count'] ?? null),
            gpuCount: $cluster['gpu_count'] ?? null,
            modelsLoaded: $cluster['models_loaded'] ?? null,
            configuredGpuTypes: $data['configured_gpu_types'] ?? null,
            liveGpuTypes: $data['live_gpu_types'] ?? null,
            workers: $workers,
        );
    }

    private function describeType(mixed $data): string
    {
        $type = is_array($data) ? ($data['type'] ?? null) : null;

        return is_string($type) ? $type : 'unknown';
    }
}
