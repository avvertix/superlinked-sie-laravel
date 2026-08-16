<?php

declare(strict_types=1);

namespace Sie;

use Sie\Client\Data\PoolInfo;
use Sie\Client\SieClient;

/**
 * Named, lease-held slices of cluster capacity.
 *
 * Deliberately not fluent: a **Pool** has a lifecycle (create → renew →
 * delete), not a per-request shape, and pretending otherwise would invite
 * callers to chain a pool creation onto an encode.
 *
 * There is no background lease renewal. Call {@see renew()} on your own
 * schedule — a scheduled command or queue worker — for as long as you want a
 * pool kept alive.
 */
final class Pools
{
    public function __construct(private readonly SieClient $client) {}

    /**
     * Create or update a pool. Re-posting the same name updates its readiness
     * requirements and renews the lease.
     *
     * @param  ?array<string, int>  $gpus  Machine profile requirements for readiness, e.g. `['l4' => 2]`.
     * @param  ?array<string, int>  $gpuCaps  Maximum assigned workers per machine profile.
     * @param  ?list<string>  $pinnedModels  Model ids to keep loaded on this pool's workers.
     */
    public function create(
        string $name,
        ?array $gpus = null,
        ?array $gpuCaps = null,
        ?string $bundle = null,
        ?int $minimumWorkerCount = null,
        ?array $pinnedModels = null,
        ?string $queuePool = null,
    ): void {
        $this->client->createPool($name, $gpus, $gpuCaps, $bundle, $minimumWorkerCount, $pinnedModels, $queuePool);
    }

    public function get(string $name): ?PoolInfo
    {
        return $this->client->getPool($name);
    }

    /** Returns true if the pool existed and was deleted. */
    public function delete(string $name): bool
    {
        return $this->client->deletePool($name);
    }

    public function renew(string $name): void
    {
        $this->client->renewPoolLease($name);
    }
}
