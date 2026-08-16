<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/** Full pool response from the gateway: `{name, spec, status}`. */
final class PoolInfo
{
    public function __construct(
        public readonly string $name,
        public readonly PoolSpecResponse $spec,
        public readonly PoolStatusInfo $status,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $fallbackName): self
    {
        return new self(
            name: (string) ($data['name'] ?? $fallbackName),
            spec: PoolSpecResponse::fromArray($data['spec'] ?? []),
            status: PoolStatusInfo::fromArray($data['status'] ?? []),
        );
    }
}
