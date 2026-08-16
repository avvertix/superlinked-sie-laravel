<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The connection used when you do not name one explicitly. A connection is
    | one SIE endpoint — a URL plus its credentials.
    |
    */

    'connection' => env('SIE_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each connection points at one SIE cluster. Pools and GPU types belong to
    | a particular cluster, so they are configured per connection rather than
    | globally. Set them to route every request from this connection by
    | default; individual requests can still override with pool() and gpu().
    |
    */

    'connections' => [
        'default' => [
            'url' => env('SIE_ENDPOINT'),
            'key' => env('SIE_KEY'),
            'timeout' => (int) env('SIE_TIMEOUT', 900),
            'pool' => env('SIE_POOL'),
            'gpu' => env('SIE_GPU'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Request Size
    |--------------------------------------------------------------------------
    |
    | Documents and images are base64-encoded into a JSON body, so a batch is
    | held in memory in full before it is sent. A request whose resolved inputs
    | exceed this many bytes throws rather than risking the memory limit. Note
    | that base64 adds roughly 37% on top of the raw file size.
    |
    */

    'max_request_bytes' => (int) env('SIE_MAX_REQUEST_BYTES', 32 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Model Catalog Cache
    |--------------------------------------------------------------------------
    |
    | The list of models a cluster serves changes when it is redeployed, not per
    | request, so it is cached. Set the ttl to 0 to always read it live, or call
    | SIE::models(fresh: true) for a single bypass.
    |
    */

    'catalog' => [
        'store' => env('SIE_CATALOG_CACHE_STORE'),
        'ttl' => (int) env('SIE_CATALOG_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel AI Bridge
    |--------------------------------------------------------------------------
    |
    | Used only when laravel/ai is installed and you have added a "sie" entry to
    | the providers array in config/ai.php. The connection is named explicitly
    | so that changing the default connection cannot silently repoint stored
    | embeddings at a different cluster.
    |
    | Dimensions must match the model: the bridge refuses to guess, because a
    | wrong width surfaces days later as poor recall rather than as an error.
    | Run SIE::models() to see the dimensions a cluster reports.
    |
    */

    'ai' => [
        'connection' => env('SIE_AI_CONNECTION', 'default'),

        'embeddings' => [
            'model' => env('SIE_AI_EMBEDDINGS_MODEL', 'BAAI/bge-m3'),
            'dimensions' => env('SIE_AI_EMBEDDINGS_DIMENSIONS'),
        ],

        'reranking' => [
            'model' => env('SIE_AI_RERANKING_MODEL', 'BAAI/bge-m3'),
        ],
    ],

];
