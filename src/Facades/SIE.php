<?php

declare(strict_types=1);

namespace Sie\Facades;

use Illuminate\Support\Facades\Facade;
use Sie\SieManager;

/**
 * Note for contributors: inside this package's own `Sie\` namespace, writing
 * `SIE::` resolves to `Sie\SIE` rather than to the global alias, because PHP
 * identifiers are case-insensitive. Always import this class explicitly.
 *
 * @method static \Sie\PendingRequest model(string $model)
 * @method static \Sie\Connection connection(?string $name = null)
 * @method static \Illuminate\Support\Collection<int, \Sie\Client\Data\ModelInfo> models()
 * @method static \Sie\Client\Data\CapacityInfo capacity(?string $gpu = null)
 * @method static \Sie\Client\Data\CapacityInfo waitForCapacity(string $gpu, ?float $timeoutS = null)
 * @method static \Sie\Pools pools()
 * @method static \Sie\Client\Connectors\SieConnector raw()
 * @method static \Sie\Testing\FakeSie fake(array<string, \Sie\Testing\FakeModel> $models = [])
 * @method static void assertEncoded(string $model, ?\Closure $callback = null)
 * @method static void assertScored(string $model, ?\Closure $callback = null)
 * @method static void assertExtracted(string $model, ?\Closure $callback = null)
 * @method static void assertGenerated(string $model, ?\Closure $callback = null)
 * @method static void assertChatted(string $model, ?\Closure $callback = null)
 * @method static void assertSlept(int $times = 1)
 * @method static void assertNothingSent()
 * @method static void assertSentCount(int $count)
 *
 * @see SieManager
 */
final class SIE extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SieManager::class;
    }
}
