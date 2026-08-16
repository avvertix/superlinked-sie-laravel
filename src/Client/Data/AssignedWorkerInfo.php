<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** A worker assigned to a pool. */
final class AssignedWorkerInfo
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $gpu,
        public readonly string $bundle,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            gpu: (string) ($data['gpu'] ?? ''),
            bundle: (string) ($data['bundle'] ?? ''),
        );
    }
}
