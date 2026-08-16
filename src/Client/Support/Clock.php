<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/** Monotonic-time source, injectable so retry/backoff tests can fake elapsed time. */
interface Clock
{
    /** Seconds on a monotonic clock. Only differences between calls are meaningful. */
    public function now(): float;
}
