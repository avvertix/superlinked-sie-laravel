<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/** Sleep abstraction, injectable so retry tests don't have to wait in real time. */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
