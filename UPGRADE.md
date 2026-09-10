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
