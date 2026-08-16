<?php

declare(strict_types=1);

use Sie\Client\Support\GpuParam;

it('splits a "pool_name/gpu_type" gpu string', function () {
    expect(GpuParam::parse('eval-bench/l4'))->toBe(['eval-bench', 'l4']);
});

it('returns a null pool for a bare gpu type', function () {
    expect(GpuParam::parse('l4'))->toBe([null, 'l4']);
});

it('returns a null gpu type when a pool is named without one', function () {
    expect(GpuParam::parse('eval-bench/'))->toBe(['eval-bench', null]);
});
