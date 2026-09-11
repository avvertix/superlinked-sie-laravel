<?php

declare(strict_types=1);

namespace Sie\Testing;

use Sie\Client\Support\Clock;
use Sie\Client\Support\Sleeper;

/**
 * The clock and sleeper a **Fake** runs the retry ladder on.
 *
 * One object for both, because they have to agree: a faked `503 PROVISIONING`
 * is retried until the provision-timeout budget is spent, and a sleeper that
 * returns instantly without moving the clock would never spend it. Sleeping
 * here advances the time the ladder reads, so a retry test finishes in
 * microseconds and still terminates.
 *
 * Nothing is handed to Laravel's `Sleep`: this object *is* the sleeper the
 * ladder was given, so no sleep has to happen for it to be skipped, and faking
 * a framework global on the application's behalf would reset any sequence and
 * callbacks the application had registered there itself. The durations are
 * recorded here instead, for `SIE::assertSlept()`.
 */
final class FakeTime implements Clock, Sleeper
{
    /** @var list<float> */
    private array $slept = [];

    private float $elapsed = 0.0;

    public function now(): float
    {
        return $this->elapsed;
    }

    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $this->slept[] = $seconds;

        // The ladder reads this clock to decide whether its budget is spent, so
        // a sleep that moved nothing would leave it looping forever.
        $this->elapsed += $seconds;
    }

    /** @return list<float> */
    public function slept(): array
    {
        return $this->slept;
    }
}
