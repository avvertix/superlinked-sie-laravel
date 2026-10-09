# Configuration

Publish the configuration file with:

```bash
php artisan vendor:publish --tag="superlinked-sie-laravel-config"
```

Each key in `config/superlinked-sie-laravel.php` has a comment explaining it.

## Connections

A connection is one endpoint and its credentials. Define one for each cluster you use:

```php
'connections' => [
    'default' => ['url' => env('SIE_ENDPOINT'), 'key' => env('SIE_KEY')],
    'eu' => ['url' => env('SIE_EU_ENDPOINT'), 'key' => env('SIE_EU_KEY'), 'pool' => 'eu-pool'],
],
```

```php
SIE::connection('eu')->model('BAAI/bge-m3')->encode('…');
SIE::connection('eu')->models();
```

## Wire format

SIE speaks msgpack and JSON. The package uses msgpack by default for encode, score and extract. The main rationale is the size difference on the wire, for example a 1024-dimension encode (measured against a live cluster) transfer 4,313 bytes via msgpack (16,569 bytes via JSON).

Switch a connection to JSON if you want to read the bodies on the wire, or if something between you and the cluster breaks binary payloads:

```php
'connections' => [
    'default' => [
        'url' => env('SIE_ENDPOINT'),
        'key' => env('SIE_KEY'),
        'format' => 'json'
    ],
],
```
> [!NOTE]
> Some endpoints supports only JSON independently of the configured format. JSON only endpoints include `generate`, `SIE::models()`, health checks, and streaming (JSON frames over SSE).

[ADR 0006](adr/0006-msgpack-is-the-default-wire-format.md) explains why msgpack is the default.
