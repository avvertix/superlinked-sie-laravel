# Upgrade Guide

We attempt to make changes that are backward compatible to not break your upgrade path. From time to time we need to remove things that were not reasonable in the beginning. We attempt to document every possible breaking change in this guide.

## Upgrading to v0.2.0 from v0.1.0

### The model catalog is no longer cached

**Likelihood Of Impact: Low**

`SIE::models()` used to cache the cluster's model catalog for an hour, with a
`catalog` block in the config to tune it and a `fresh` flag to bypass it. All of
it has been removed: the catalog is now read live on every call.

If you are staying on v0.1.0, we encourage to set `SIE_CATALOG_CACHE_TTL=0` as you might hit the `__PHP_Incomplete_Class` error on
Laravel 13.

#### Remove the config block

The `catalog` block in `config/superlinked-sie-laravel.php` can be safely removed.

```php
// Remove:
'catalog' => [
    'store' => env('SIE_CATALOG_CACHE_STORE'),
    'ttl' => (int) env('SIE_CATALOG_CACHE_TTL', 3600),
],
```

Leaving it in place is harmless — nothing reads it any more — but it will mislead
whoever opens the file next.

#### Remove the environment variables

`SIE_CATALOG_CACHE_STORE` and `SIE_CATALOG_CACHE_TTL` no longer do anything.
Drop them from `.env`, `.env.example`, and from any deployment or container
configuration that sets them.

#### Drop the `$fresh` argument

The parameter is gone from the method signature, so passing it is a `TypeError`
rather than a silent no-op:

```php
SIE::models(fresh: true);                       // before
SIE::models();                                  // after

SIE::connection('eu')->models(fresh: true);     // before
SIE::connection('eu')->models();                // after
```

#### Drop the `--fresh` option

`php artisan sie:models --fresh` now fails with `The "--fresh" option does not
exist.` Remove the flag from any script, alias, scheduled task or runbook that
passes it.

```bash
php artisan sie:models --fresh                  # before
php artisan sie:models                          # after
```

#### If you were relying on the cache

You should take care of the cache yourselves. 

```php
use Illuminate\Support\Facades\Cache;
use Sie\Client\Data\ModelInfo;
use Sie\Facades\SIE;

$models = Cache::remember(
    'sie:catalog',
    now()->addHour(),
    fn () => SIE::models()->map->toArray()->all(),
);

$models = collect($models)->map(ModelInfo::fromArray(...));
```

`toArray()` is new in v0.2.0 on `ModelInfo`, `ModelDims` and `ModelCapabilities`;
all three also implement `JsonSerializable`, so `json_encode(SIE::models())` and
`SIE::models()->toJson()` work without any manual conversion.

### The test double answers more, and refuses more

**Likelihood Of Impact: Medium** — only if your test suite calls `SIE::fake()`
or mocks SIE through Saloon directly.

`SIE::fake()` used to answer any route it did not recognise with
`['status' => 'ok']` and a 200. That is why a faked chat completion came back
empty, a faked stream yielded nothing, and a faked `generate()` for a model id
containing a slash returned an empty string — all without an error. The double
now serves chat, both streaming routes and `/health` properly, and raises for
anything it cannot answer.

#### `SIE::fake()` now replaces a previous fake

Saloon's global mock is a process-wide static that `MockClient::global()` never
overwrites, so before v0.2.0 only the first `SIE::fake()` in a process took
effect: later calls returned a `FakeSie` that was never wired up, serving the
first test's responses and recording none of its own.

If you worked around it, the workaround can go:

```php
// Remove:
beforeEach(function (): void {
    MockClient::destroyGlobal();
});
```

#### `SIE::fake()` refuses a global mock it did not install

Taking over a mock the application installed would silently reroute its
requests, so it now throws instead. Destroy yours first if you meant to replace
it:

```php
MockClient::global([...]);
SIE::fake();                    // before: silently ignored, now: RuntimeException

MockClient::destroyGlobal();    // after
SIE::fake();
```

#### A configured model answers only what you gave it an answer for

A model you pass to `SIE::fake()` now raises when asked for a capability it has
no answer for, rather than falling back to a default. Models you do not
configure at all still answer everything, so a bare `SIE::fake()` is unchanged.

```php
SIE::fake(['gliner' => FakeModel::entities([])]);

SIE::model('gliner')->encode('…');   // before: an 8-dimensional vector
                                      // after: RuntimeException

// Give it the missing answer:
SIE::fake(['gliner' => FakeModel::entities([])->andDense(1024)]);
```

#### Routes the double does not serve now raise

Pools and any other unrecognised route used to return a hollow 200. They now
raise, so a test that touched one through `SIE::fake()` must use Saloon's
`MockClient` for that call instead.

#### `FakeModel::$capability` is gone

A `FakeModel` carries an answer per capability now, so it has no single
capability to report. Nothing in the package read the property; if your tests
did, drop the assertion.

#### A faked generate model declares `tokens`, not `text`

`FakeModel::outputs()` reports what the cluster reports. Update any assertion
against the faked catalog:

```php
expect(SIE::models()->firstWhere('name', 'tiny-llm')->outputs)->toBe(['text']);    // before
expect(SIE::models()->firstWhere('name', 'tiny-llm')->outputs)->toBe(['tokens']);  // after
```

#### Retries no longer sleep in a faked test

`SIE::fake()` now installs a clock and sleeper the retry ladder reads, so a
faked `503` retries instantly and the provision-timeout budget is still spent.
Laravel's `Sleep` is untouched; assert waits with `SIE::assertSlept()`. Tests
that shortened the budget to keep the suite fast can drop that:

```php
SIE::model('BAAI/bge-m3')->provisionTimeout(0.1)->encode('…');   // no longer needed
```

The clock and sleeper are resolved from the container, and `SIE::fake()` forgets
memoised connections so a client built before the call cannot keep the real
clock. A client you captured in a variable *before* calling `SIE::fake()` still
holds it — resolve it after faking.

#### What you can delete

Several things that previously needed a hand-rolled Saloon mock are now part of
the double: extraction `data` and per-item `error` via `FakeModel::extracting()`,
failures via `FakeModel::failing()`, captured cluster responses via
`FakeModel::recording()`, and `SIE::assertChatted()`. See
[docs/testing.md](docs/testing.md), or ask your agent for the
`superlinked-sie-laravel-upgrade` skill.
