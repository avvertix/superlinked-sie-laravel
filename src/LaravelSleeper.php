<?php

declare(strict_types=1);

namespace Sie;

use Illuminate\Support\Sleep;
use Sie\Client\Support\Sleeper;
use Sie\Testing\FakeTime;

/**
 * The retry ladder's sleeper, routed through Laravel's `Sleep`.
 *
 * Delegating rather than calling `usleep()` means an application that has
 * called `Sleep::fake()` gets instant retries and can assert on them with
 * `Sleep::assertSlept()`, without this package writing that global state
 * itself.
 *
 * This is the production sleeper. Under `SIE::fake()` the container holds a
 * {@see FakeTime} instead, which never sleeps at all and records
 * the waits for `SIE::assertSlept()`.
 *
 * Lives here rather than in `Sie\Client`, which imports no Illuminate code so
 * that the client stays usable as a plain PHP SDK (pinned by an arch test).
 */
final class LaravelSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        // Microseconds, not seconds: Sleep::for() hands the value to Carbon,
        // which rejects a float small enough to reach it in scientific
        // notation — and the ladder's last delay before a budget runs out is
        // exactly that small.
        $microseconds = (int) round($seconds * 1_000_000);

        if ($microseconds <= 0) {
            return;
        }

        Sleep::usleep($microseconds);
    }
}
