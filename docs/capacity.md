# Profiles, pools and GPUs

## Profiles

A profile picks which variant of a model runs. An output picks which representations come back. Keep the two apart: `BAAI/bge-m3` has profiles that are literally named `dense`, `sparse` and `multivector`.

```php
SIE::model('docling')->profile('ocr')->extract($document);   // → docling:ocr
SIE::model('docling:ocr')->extract($document);               // the same thing
```

## Pools and GPU types

You can set a pool, a GPU type, both, or neither:

```php
SIE::model('BAAI/bge-m3')->pool('eval-bench')->gpu('l4')->encode('…');
SIE::model('BAAI/bge-m3')->pool('eval-bench')->encode('…');  // any GPU in that pool
```

## Waiting for capacity

By default a request waits while the cluster provisions capacity. If you would rather fail fast:

```php
SIE::model('BAAI/bge-m3')->withoutWaitingForCapacity()->encode('…');
```

## Warming up a model

To load a model before real traffic arrives, send it an input it accepts. No single probe works for every model; `docling`, for one, rejects text.

```php
SIE::model('BAAI/bge-m3')->warmup('warm');
SIE::model('docling')->warmup(Input::document(File::make('fixtures/stub.pdf')));
```

## Managing pools

Pools live longer than a single request, so they have their own API. Nothing renews a lease in the background. Call `renew()` on your own schedule for as long as you need the pool.

```php
SIE::pools()->create('eval-bench', gpus: ['l4' => 2], pinnedModels: ['BAAI/bge-m3']);
SIE::pools()->get('eval-bench');
SIE::pools()->renew('eval-bench');
SIE::pools()->delete('eval-bench');
```
