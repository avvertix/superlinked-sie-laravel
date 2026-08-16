<div align="center">
    <h1>SIE for Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/v/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/php-v/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://badge.laravel.cloud/badge/avvertix/superlinked-sie-laravel?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/avvertix/superlinked-sie-laravel/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/avvertix/superlinked-sie-laravel/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/dt/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="Total Downloads"></a>
</p>


SIE for Laravel provides access to [Superlinked Inference Engine (SIE)](https://superlinked.com/blog/launch), a multi-model inference cluster for search and document processing. It allows to use [100+ models](https://superlinked.com/models) across encoding, scoring, and extraction deployed via a SIE server or gateway.

```php
use Sie\Facades\SIE;

$vectors = SIE::model('BAAI/bge-m3')
    ->instruction('Represent this sentence for retrieval')
    ->when($isSearchQuery, fn ($request) => $request->asQuery())
    ->encode('a duck paddles on a pond');

$vectors->sole()->dense; // [0.017, -0.041, …]
```

## Installation

You can install the package via Composer:

```bash
composer require avvertix/superlinked-sie-laravel
```

Then point it at your cluster:

```dotenv
SIE_ENDPOINT=https://sie.example.com
SIE_KEY=your-api-key
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="superlinked-sie-laravel"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="superlinked-sie-laravel-config"
```

## Concepts

The package uses SIE's own vocabulary. [`CONTEXT.md`](CONTEXT.md) is the full glossary; these are the terms you meet first:

| Term | Meaning |
| --- | --- |
| **Capability** | One of the four things a model can do: **encode**, **score**, **extract**, **generate**. Which ones a model supports is declared by the cluster, and is many-to-many — `BAAI/bge-m3` both encodes and scores, `docling` does neither. |
| **Profile** | A named variant of a model, addressed as `model:profile` — `docling:ocr`, `BAAI/bge-m3:multivector`. |
| **Output** | A representation a model produces: `dense`, `sparse`, `multivector`, `score`, `json`. |
| **Input** | One thing handed to a model: text, images, or a document. |
| **Pool** / **GPU type** | Where the work runs. A pool is a lease-held slice of capacity; a GPU type is a hardware profile. They are independent. |

Run `SIE::models()` to see what your cluster actually serves, including each model's inputs, outputs, and dimensions.

## Usage

A fluent API to bring you from a model to **encode**, **score**, **extract**, **generate**. Using the Fluent API you can chain options.

### Encode

Turn inputs into vectors.

```php
use Sie\Facades\SIE;

SIE::model('BAAI/bge-m3')->encode('a duck paddles on a pond');

// A batch returns one result per input, in order.
SIE::model('BAAI/bge-m3')->encode(['a duck', 'a goose', 'a turbine']);

// Ask for more than the cluster's default `dense`.
$result = SIE::model('BAAI/bge-m3')
    ->outputs(['dense', 'sparse', 'multivector'])
    ->encode('a duck paddles on a pond')
    ->sole();

$result->dense;        // list<float>
$result->sparse;       // SparseResult
$result->multivector;  // list<list<float>>
```

Encoding a search query is not the same as encoding a stored document; models that care about the difference read `asQuery()`:

```php
SIE::model('BAAI/bge-m3')->asQuery()->encode('waterfowl that swims');
```

### Score

Rank inputs by relevance to a query. Results come back sorted, most relevant first.

```php
use Sie\Input;

$ranked = SIE::model('BAAI/bge-m3')->score('waterfowl that swims', [
    Input::text('a duck paddles on a pond', 'duck'),
    Input::text('a turbine spins', 'turbine'),
]);

$ranked->top(1);              // ['duck']
$ranked->first()->score;      // 0.82…
```

### Extract

Pull structured data out of inputs — entities, relations, classifications, or a schema of your own.

```php
$results = SIE::model('urchade/gliner_multi-v2.1')
    ->labels(['person', 'location'])
    ->extract('Ada Lovelace was born in London.');

$results->sole()->entities; // [Entity{text: 'Ada Lovelace', label: 'person', …}, …]
```

Document parsing is an extraction too:

```php
use Sie\File;

$parsed = SIE::model('docling')
    ->extract(Input::document(File::disk('documents', 'invoices/march.pdf')));

$parsed->sole()->data; // the parsed document structure
```

### Generate

Produce text from a prompt.

```php
SIE::model('some-llm')->maxNewTokens(256)->temperature(0.2)->generate('Summarise: …')->text;

// Streaming returns a single-pass LazyCollection of chunks.
SIE::model('some-llm')->stream('Summarise: …')->each(function ($chunk) {
    echo $chunk->text;
});
```

## Inputs and files

A bare string is text. Anything richer uses `Input`:

```php
use Sie\File;
use Sie\Input;

Input::text('a duck paddles on a pond');
Input::text('…', id: 'doc-1');                       // ids come back on the result
Input::image($uploadedFile);                          // UploadedFile or SplFileInfo
Input::document(File::disk('s3', 'invoices/x.pdf'));  // any filesystem disk
Input::document(File::make('local/path.pdf'));        // the default disk

Input::text('caption')->withImage($a, $b);            // one input, several parts
```

Files are read lazily — nothing touches the disk until the request is sent, so a half-built chain can be handed straight to a queued job:

```php
class EmbedDocument implements ShouldQueue
{
    public function __construct(private Sie\PendingRequest $request) {}

    public function handle(): void
    {
        $this->request->encode(Input::document(File::disk('s3', $this->path)));
    }
}

dispatch(new EmbedDocument(SIE::model('BAAI/bge-m3')->pool('eval-bench')));
```

> **Memory:** SIE's wire format is JSON with base64-encoded file contents, so a batch is buffered in full before it is sent — roughly 1.37× the raw bytes. Requests over `max_request_bytes` (32 MB by default) throw `RequestTooLargeException` instead of risking the memory limit. Split large batches yourself; the package will not silently split them for you.

## Results

Every capability returns a `Collection`, one result per input, in the order they were sent — even for a single input. Use `sole()` or `first()` to unwrap.

Extraction batches are **mixed-success**: an input the cluster could not process stays in the collection carrying its error rather than aborting the batch. An empty result therefore means "nothing found" only once you have ruled that out:

```php
$results = SIE::model('docling')->extract($documents);

$results->hasFailures();     // bool
$results->failed();          // the ones that errored
$results->succeeded();       // the ones that did not
$results->throwIfAnyFailed(); // opt into strictness
```

A score response also carries per-request information that belongs to the whole call rather than to any one item. It sits on the collection, and survives filtering and sorting:

```php
$ranked = SIE::model('BAAI/bge-m3')->score($query, $documents);

$ranked->model;              // the model that actually served it, after alias/profile resolution
$ranked->usage->inputTokens; // 34 — the billable number
$ranked->queryId;            // server-assigned, when the cluster assigns one

$ranked->filter(fn ($entry) => $entry->score > 0.6)->usage->inputTokens; // still 34
```

## Profiles, pools and GPUs

A **profile** selects which variant of a model runs. An **output** selects which representations come back. These are different things, which matters because `BAAI/bge-m3` has profiles literally named `dense`, `sparse` and `multivector`:

```php
SIE::model('docling')->profile('ocr')->extract($document);   // → docling:ocr
SIE::model('docling:ocr')->extract($document);               // the same thing
```

Pool and GPU type are independent, and either may be omitted:

```php
SIE::model('BAAI/bge-m3')->pool('eval-bench')->gpu('l4')->encode('…');
SIE::model('BAAI/bge-m3')->pool('eval-bench')->encode('…');  // any GPU in that pool
```

By default a request waits while the cluster provisions capacity. To fail fast instead:

```php
SIE::model('BAAI/bge-m3')->withoutWaitingForCapacity()->encode('…');
```

Loading a model ahead of real traffic needs an input that model accepts — there is no probe that works for every model, since `docling` rejects text outright:

```php
SIE::model('BAAI/bge-m3')->warmup('warm');
SIE::model('docling')->warmup(Input::document(File::make('fixtures/stub.pdf')));
```

## Connections

A connection is one endpoint plus its credentials. Define as many as you have clusters:

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

## Pools

Pools have a lifecycle rather than a per-request shape, so they sit on their own non-fluent API. There is no background lease renewal — renew on your own schedule for as long as you want a pool kept alive.

```php
SIE::pools()->create('eval-bench', gpus: ['l4' => 2], pinnedModels: ['BAAI/bge-m3']);
SIE::pools()->get('eval-bench');
SIE::pools()->renew('eval-bench');
SIE::pools()->delete('eval-bench');
```

## Testing

`SIE::fake()` answers every request locally. Vectors are deterministic — the same input always embeds identically — so snapshot tests stay stable.

```php
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

SIE::fake([
    'BAAI/bge-m3' => FakeModel::dense(1024),
    'urchade/gliner_multi-v2.1' => FakeModel::entities([
        ['text' => 'Ada', 'label' => 'person', 'score' => 0.99, 'start' => 0, 'end' => 3],
    ]),
]);

$this->post('/documents', [...]);

SIE::assertEncoded('BAAI/bge-m3');
SIE::assertEncoded('BAAI/bge-m3', fn (array $body) => $body['params']['instruction'] === 'retrieve');
SIE::assertSentCount(1);
SIE::assertNothingSent();
```

Models you do not configure still answer, with 8-dimensional vectors. For tests that care about the exact wire format, drop down to Saloon's `MockClient` with fixtures.

## Laravel AI

When [`laravel/ai`](https://github.com/laravel/ai) is installed, SIE registers itself as a `sie` driver. Add the provider to `config/ai.php` yourself:

```php
'providers' => [
    'sie' => [
        'driver' => 'sie',
        'key' => env('SIE_KEY'),
    ],
],
```

```dotenv
SIE_AI_EMBEDDINGS_MODEL="BAAI/bge-m3"
SIE_AI_EMBEDDINGS_DIMENSIONS=1024
```

```php
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

Embeddings::for(['a duck paddles on a pond'])->generate('sie');
Embeddings::for(['a duck paddles on a pond'])->dimensions(1024)->generate('sie', 'BAAI/bge-m3');

Reranking::of(['a duck', 'a turbine'])->rerank('waterfowl', 'sie');
```

Setting `default_for_embeddings` (or `default_for_reranking`) to `sie` in `config/ai.php` lets you drop the provider argument entirely, which is what makes vector stores and agents pick SIE up without further wiring.

The bridge is **dense-only**: `EmbeddingGateway` has room for one representation and one width, so sparse and multivector output stays on the native `SIE::` surface. SIE's own options — `instruction`, `is_query`, `profile`, `pool`, `gpu` — travel through `providerOptions` when you call a provider directly:

```php
app(Laravel\Ai\AiManager::class)->instance('sie')->embeddings(
    ['a duck paddles on a pond'],
    dimensions: 1024,
    providerOptions: ['instruction' => 'retrieve', 'is_query' => true, 'pool' => 'eval-bench'],
);
```

Dimensions are never guessed — a wrong width does not error, it silently mismatches your vector column and surfaces later as poor recall. Configure them explicitly, or the bridge throws.

## Accessing the underlying client

For anything this package does not model — OpenAI-compatible chat completions, for instance:

```php
SIE::raw();                    // Saloon connector for the default connection
SIE::connection('eu')->raw();  // …and for a named one
SIE::connection()->client();   // the lower-level SieClient
```

## A note on the facade

PHP identifiers are case-insensitive, so importing the facade shadows the package's own namespace prefix within that file:

```php
use Sie\Facades\SIE;

Sie\Input::text('…');   // ✗ resolves to Sie\Facades\SIE\Input
\Sie\Input::text('…');  // ✓
use Sie\Input;          // ✓ better still — just import it
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Superlinked Sie Laravel! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Alessio Vertemati](https://github.com/avvertix)
- [All Contributors](../../contributors)

## License

Superlinked Sie Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).
