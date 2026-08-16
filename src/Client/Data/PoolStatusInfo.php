<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Pool status information ("pending" | "active" | "expired"). */
final class PoolStatusInfo
{
    /**
     * @param  list<AssignedWorkerInfo>  $assignedWorkers
     */
    public function __construct(
        public readonly ?string $state = null,
        public readonly array $assignedWorkers = [],
        public readonly ?float $createdAt = null,
        public readonly ?float $lastRenewed = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            state: $data['state'] ?? null,
            assignedWorkers: array_map(AssignedWorkerInfo::fromArray(...), $data['assigned_workers'] ?? []),
            createdAt: $data['created_at'] ?? null,
            lastRenewed: $data['last_renewed'] ?? null,
        );
    }
}
