<div align="center">
    <h1>SIE for Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/v/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/php-v/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://badge.laravel.cloud/badge/avvertix/superlinked-sie-laravel?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/avvertix/superlinked-sie-laravel/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/avvertix/superlinked-sie-laravel/tests.yml?branch=main&label=Tests&style=flat-square"></a>
</p>


SIE for Laravel provides access to [Superlinked Inference Engine (SIE)](https://superlinked.com/blog/launch), deployed models. SIE is a multi-model inference cluster for search and document processing. It allows to use [100+ models](https://superlinked.com/models) across encoding, scoring, and extraction.

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
SIE_ENDPOINT=https://sie.localhost
SIE_KEY=your-api-key
```

You may publish the configuration file via:

```bash
php artisan vendor:publish --tag="superlinked-sie-laravel-config"
```

## Concepts

The package uses SIE's own vocabulary, these are the terms you meet first: 

| Term | Meaning |
| --- | --- |
| **Capability** | One of the four things a model can do: **encode**, **score**, **extract**, **generate**. Which ones a model supports is declared by the cluster, and is many-to-many — `BAAI/bge-m3` both encodes and scores, `docling` does neither. |
| **Profile** | A named variant of a model, addressed as `model:profile` — `docling:ocr`, `BAAI/bge-m3:multivector`. |
| **Output** | A representation a model produces: `dense`, `sparse`, `multivector`, `score`, `json`. |
| **Input** | One thing handed to a model: text, images, or a document. |
| **Pool** / **GPU type** | Where the work runs. A pool is a lease-held slice of capacity; a GPU type is a hardware profile. They are independent. |

Run `SIE::models()`, or `php artisan sie:models`, to see what your cluster actually serves, including each model's inputs, outputs, and dimensions.

Head to [`CONTEXT.md`](CONTEXT.md) for the full glossary.

## Usage

A fluent API bring you from a model to **encode**, **score**, **extract**, **generate**. Options can be chained, supporting conditionals.

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

Encoding a search query is not the same as encoding a stored document; models that care about the difference can be instructed chaining `asQuery()`:

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
    echo $chunk->textDelta;
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

For files, the shorthand skips the wrapper. The extension decides whether it lands in the document field or the image field:

```php
Input::fromDisk('s3', 'invoices/march.pdf');   // → document
Input::fromDisk('s3', 'cover.jpg');            // → image
Input::fromDisk('s3', 'march.pdf', 'doc-1');   // …with an id

// A file is an input on its own, so the capability methods take one directly.
SIE::model('docling')->extract(File::disk('s3', 'invoices/march.pdf'));
SIE::model('docling')->extract($request->file('upload'));

$paths = ['a.pdf', 'b.pdf', 'c.pdf'];
SIE::model('docling')->extract(array_map(fn ($p) => Input::fromDisk('s3', $p), $paths));
```

In case the type, document or image, cannot be identified automatically you can `documentFromDisk` and `imageFromDisk` respectively.

```php
Input::fromDisk('s3', 'archive.bin');
// InvalidArgumentException: Cannot tell whether [archive.bin] is a document or
// an image. Use Input::documentFromDisk() or Input::imageFromDisk() to say which.

Input::documentFromDisk('s3', 'archive.bin');
Input::imageFromDisk('scans', 'page-1');
```

Files are read lazily. Nothing touches the disk until the request is sent, so a half-built chain can be handed straight to a queued job:

```php
class EmbedDocument implements ShouldQueue
{
    public function __construct(private Sie\PendingRequest $request, private string $path) {}

    public function handle(): void
    {
        $this->request->encode(Input::document(File::disk('s3', $this->path)));
    }
}

dispatch(new EmbedDocument(SIE::model('BAAI/bge-m3')->pool('eval-bench'), 'document.pdf'));
```

> **Memory:** a batch is serialized in full before it is sent, so it is held in memory at once. Over msgpack that is the raw size of your files; over JSON add ~37% for base64. Requests over `max_request_bytes` (32 MB by default, counted on raw bytes) throw `RequestTooLargeException` instead of risking the memory limit. Split large batches yourself; the package will not silently split them for you.

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
$ranked->usage->inputTokens; // 34, the consumed tokens
$ranked->queryId;            // server-assigned, when the cluster assigns one
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

## Wire format

SIE speaks **msgpack** and **JSON**. The package defaults to msgpack and negotiate the most appropriate content type when needed. For example the same 1024-dimension encode, measured against a live cluster:

| | msgpack | JSON |
| --- | --- | --- |
| Response size | **4,313 bytes** | 16,569 bytes |

You can switch a connection to JSON when you want a readable body on the wire, or when something between you and the cluster mangles binary payloads:

```php
'connections' => [
    'default' => [
        'url' => env('SIE_ENDPOINT'),
        'key' => env('SIE_KEY'),
        'format' => 'json'
    ],
],
```

Worth note that `generate` always use JSON, `SIE::models()` and health always answer JSON, and streaming is JSON frames over SSE.

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

## Console

The package adds a `sie:models` Artisan command to access the catalog of available models.

```bash
php artisan sie:models
```

A sample response can be:

```
 INFO  Models served by the [default] connection (msgpack).

+-----------------+-----------------+------------+------------------+--------+
| Model           | Inputs          | Outputs    | Dimensions       | Loaded |
+-----------------+-----------------+------------+------------------+--------+
| BAAI/bge-m3     | text            | dense, ... | dense: 1024, ... | yes    |
| docling         | image, document | json       | -                | yes    |
+-----------------+-----------------+------------+------------------+--------+
```

A cluster can serve well over a hundred models, so pass a filter to find one:

```bash
php artisan sie:models bge                 # only names containing "bge"
php artisan sie:models --loaded            # only what is loaded on a worker right now
php artisan sie:models bge --loaded        # both
php artisan sie:models --connection=eu     # read a named connection
```

## Pools

Pools have a lifecycle rather than a per-request shape, so they sit on their own. There is no background lease renewal — renew on your own schedule for as long as you want a pool kept alive.

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

`assertScored()`, `assertExtracted()`, `assertGenerated()` and `assertChatted()` work the same way. Generate and chat share one answer but keep separate assertions, so a test cannot pass against the route it did not mean.

A `FakeModel` holds an answer per capability, because a real model serves more than one — `BAAI/bge-m3` both encodes and scores. Start with a static constructor and chain `and…()` for the rest; each link returns a new instance, so a fake can be shared between tests.

```php
SIE::fake([
    'BAAI/bge-m3' => FakeModel::dense(1024)->andScores(['doc-b' => 0.9, 'doc-a' => 0.2]),
]);
```

The faked catalog reports the outputs those answers imply — `['dense', 'score']` here. Use `->declaring(['dense', 'sparse'])` when the model really declares an output the fake does not answer.

A model you configure answers only what you gave it an answer for: asking a faked extractor to encode raises rather than returning a plausible vector. Models you do not configure at all still answer everything, with 8-dimensional vectors — so a bare `SIE::fake()` needs no catalog. Routes the fake does not serve, such as pools, raise too; reach for Saloon's `MockClient` there.

### Extraction beyond entities

`FakeModel::entities()` gives every input the same answer. When the answer varies per input — or is not entities at all — use `FakeModel::extracting()`, which returns the extract item itself, so `data`, `error`, `relations`, `classifications` and `objects` are all reachable:

```php
SIE::fake([
    'docling' => FakeModel::extracting(fn (array $item) => [
        'data' => ['markdown' => '# Invoice', 'text' => 'Invoice'],
    ]),

    'urchade/gliner_multi-v2.1' => FakeModel::extracting(fn (array $item) => $item['text'] === ''
        ? ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'tokenizer failed']]
        : ['entities' => [['text' => 'Ada', 'label' => 'person', 'score' => 0.99, 'start' => 0, 'end' => 3]]]),
]);
```

The callback receives one input item in its wire shape and its `id` is echoed back for you. That makes the mixed-success branch testable: a batch where one item carries `error` and the rest carry results is what `hasFailures()`, `failed()` and `throwIfAnyFailed()` exist for.

### Failures and retries

A faked failure is answered at the wire — a status and an error envelope — so your code meets the same typed exception the cluster would produce, through the package's own error handling:

```php
SIE::fake(['BAAI/bge-m3' => FakeModel::failing(400, 'INVALID_INPUT', 'items must not be empty')]);
```

To exercise a retry, fail a fixed number of times and then answer normally. Retries are instant: the double is itself the clock and the sleeper the retry ladder was given, so nothing waits and the provision-timeout budget is still spent. Laravel's `Sleep` is left alone, so a `Sleep::fake()` your own code depends on keeps its sequence.

```php
SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)->andFailingTimes(1, 503, 'PROVISIONING')]);

SIE::model('BAAI/bge-m3')->encode('Hello world');   // waits, retries, succeeds

SIE::assertSlept(1);      // the ladder waited once
SIE::assertSentCount(2);  // the rejected attempt and the retry
```

`SIE::fake([...])->failNext(503, 'PROVISIONING')` does the same for the next request whatever model it is for.

### Recording a real response

Some answers are not worth writing by hand — a parsed document, or any payload whose shape belongs to the model rather than to this package. Record one instead:

```php
SIE::fake(['docling' => FakeModel::recording('docling-pdf-simple')]);
```

The first run sends the request for real and Saloon stores the response under `tests/Fixtures/Saloon`; every run after that replays the file. Three things to know: the recording run needs a reachable `SIE_ENDPOINT` and credentials, so record deliberately; set the connection's `format` to `json` for that run, or the fixture stores base64-encoded msgpack that nobody can read in a diff; and refreshing a recording means deleting the file.

For tests that care about the exact wire format, drop down to Saloon's `MockClient` with fixtures directly — but call `MockClient::destroyGlobal()` before `SIE::fake()` if you have installed a global mock of your own, since `SIE::fake()` refuses to take one over.

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


You can pass also options such as`instruction`, `is_query`, `profile`, `pool`, `gpu` through `providerOptions` when you call a provider directly:

```php
app(Laravel\Ai\AiManager::class)->instance('sie')->embeddings(
    ['a duck paddles on a pond'],
    dimensions: 1024,
    providerOptions: ['instruction' => 'retrieve', 'is_query' => true, 'pool' => 'eval-bench'],
);
```

Laravel AI embeddings support only one vector representation. If you care about multivector or sparse use the SIE facade directly. 

Dimensions are never guessed — a wrong width does not error, it silently mismatches your vector column and surfaces later as poor recall. Configure them explicitly, or the bridge throws.

### Classification

SIE also answers `laravel/ai`'s typed questions, backed by a decision model such as `fastino/GLiNER2.5-Decide`. All questions about one record go out in a single `extract` call, and every answer carries a probability you can branch on:

```php
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;

$response = Classification::of($supportRequest)
    ->questions([
        'urgent' => new Boolean('Does this request need an immediate response?'),
        'department' => new Choice('Which team should handle this request?', [
            'billing' => 'Payments, invoices, and refunds',
            'technical' => 'Bugs, outages, and integrations',
        ]),
        'frustration' => new Score('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']),
    ])
    ->classify('sie');

$response->answer('urgent')->isTrue(0.9);
$response->answer('department')->choice;
$response->answer('frustration')->score;
```

`Str::of($message)->decide('Is this spam?', provider: 'sie')` works the same way. Set `default_for_classification` to `sie` in `config/ai.php` to drop the provider argument.

| `laravel/ai` question | SIE type | Answer |
|---|---|---|
| `Boolean` | `noul` | `BooleanAnswer`, the probability of "true" |
| `Choice` | `choice` | `ChoiceAnswer`, the winning option plus a probability per option |
| `Score` | `score` | `ScoreAnswer`, the expected level plus a probability per level |

The model defaults to `fastino/GLiNER2.5-Decide`; change it with `SIE_AI_CLASSIFICATION_MODEL`. An array passed as the state is sent as JSON text. Text attachments (`Classification::of($state, [Document::fromPath('email.txt')])`, or an uploaded `text/*`, JSON, XML or YAML file) are read into the same text, each under its file name; images and files without inline content throw an `InvalidArgumentException`, because the decision models read text only. Token usage is not reported, so `usage` is always empty. Every question needs instructions, and the cluster must serve a decision model. Label-group classifiers such as GLiClass stay on the native `SIE::` surface.

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

If you are moving between versions, [UPGRADE](UPGRADE.md) lists the changes that need an edit on your side.

## Contributing

Thank you for considering contributing to Superlinked Sie Laravel! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Alessio Vertemati](https://github.com/avvertix)
- [All Contributors](../../contributors)

## License

SIE for Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).

