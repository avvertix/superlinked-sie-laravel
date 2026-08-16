<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/**
 * Splits a `gpu` argument into its pool-routing and GPU-type parts.
 *
 * A `gpu` value of "pool_name/gpu_type" routes to a named resource pool;
 * a bare "gpu_type" has no pool. Direct port of `parse_gpu_param`.
 */
final class GpuParam
{
    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function parse(string $gpu): array
    {
        if (str_contains($gpu, '/')) {
            [$pool, $type] = explode('/', $gpu, 2);

            // "pool/" routes to a pool without constraining the GPU type. An
            // empty type must become null, or the request would carry an empty
            // machine-profile header rather than omitting it.
            return [$pool, $type === '' ? null : $type];
        }

        return [null, $gpu];
    }
}
