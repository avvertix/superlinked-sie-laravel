<?php

declare(strict_types=1);

namespace Sie\Tests\Client\Fixtures;

use Sie\Client\Support\Sleeper;

/** Records every requested sleep instead of actually sleeping; optionally advances a {@see FakeClock}. */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly ?FakeClock $clock = null) {}

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock?->advance($seconds);
    }
}
