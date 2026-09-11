---
name: superlinked-sie-laravel-development
description: >
  Configure and apply the Superlinked Sie Laravel package in Laravel applications.
license: MIT
metadata:
  author: Alessio Vertemati
---

# Superlinked Sie Laravel

Use this skill when a Laravel application needs to integrate the Superlinked Sie Laravel package.

## Primary Goal

- apply the `avvertix/superlinked-sie-laravel` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project with `avvertix/superlinked-sie-laravel` installed
- check `config/superlinked-sie-laravel.php` and `.env` for a configured connection
- inspect the target code paths where the package should be applied

### 2. Configure a connection

A **connection** is one SIE endpoint: a URL plus its credentials. `SIE_ENDPOINT`
is the only required value.

```dotenv
SIE_ENDPOINT=https://sie.example.com
SIE_KEY=your-api-key
```

Publish the config only when the app needs more than the defaults — extra
connections, a default pool or GPU type, a different wire format, or a larger
request ceiling:

```bash
php artisan vendor:publish --tag="superlinked-sie-laravel-config"
```

Run `php artisan sie:models` to see what the cluster actually serves. Never
hardcode a model's dimensions or capabilities from memory: read them from the
catalog, because they differ per deployment.

```bash
php artisan sie:models                       # every model
php artisan sie:models bge --loaded          # filter by name, loaded only
php artisan sie:models --connection=default
```

### 3. Apply the package's public API

Everything starts at `SIE::model()` and ends on the verb that does the work.
Options in between are chainable and may be conditional (`->when()`).

```php
use Sie\Facades\SIE;

// Encode: inputs to vectors. A batch returns one result per input, in order.
SIE::model('BAAI/bge-m3')->encode('a duck paddles on a pond')->sole()->dense;
SIE::model('BAAI/bge-m3')->asQuery()->encode('waterfowl that swims');
SIE::model('BAAI/bge-m3')->outputs(['dense', 'sparse'])->encode($texts);

// Score: rank inputs against a query, most relevant first.
SIE::model('BAAI/bge-m3')->score('waterfowl', $inputs)->top(3);

// Extract: entities, or any structured output.
SIE::model('urchade/gliner_multi-v2.1')->labels(['person'])->extract($text)->sole()->entities;
SIE::model('docling')->extract($file)->sole()->data;

// Generate: text, buffered or streamed.
SIE::model('some-llm')->maxNewTokens(256)->generate($prompt)->text;
SIE::model('some-llm')->stream($prompt)->each(fn ($chunk) => print($chunk->textDelta));
```

Routing options apply to every verb: `->profile('ocr')`, `->pool('eval-bench')`,
`->gpu('l4')`, `->provisionTimeout(120)`, `->withoutWaitingForCapacity()`.
Options that do not apply to the verb you call raise `InvalidArgumentException`
rather than being ignored, so pass only what the capability accepts.

Wrap anything richer than a string in `Input`, and read files through `File` so
nothing touches the disk until the request is sent:

```php
use Sie\File;
use Sie\Input;

Input::text('a duck', id: 'doc-1');              // ids come back on the result
Input::fromDisk('s3', 'invoices/march.pdf');     // extension picks document vs image
SIE::model('docling')->extract($request->file('upload'));
```

A half-built chain is serializable, so can be executed in a queued job if relevant.

### 4. Handle the failures that matter

Extract batches are **mixed-success**: a failed input stays in the collection
carrying its error. An empty result means "nothing found" only after ruling that
out.

```php
$results = SIE::model('docling')->extract($files);

if ($results->hasFailures()) {
    report(new RuntimeException($results->failed()->count().' inputs failed'));
}

$results->succeeded()->each(fn ($result) => /* … */);
$results->throwIfAnyFailed();   // or opt into strictness
```

Catch `Sie\Exceptions\UnsupportedCapabilityException` when a model cannot serve
the verb, `Sie\Client\Exceptions\ProvisioningException` when the cluster ran out
of waiting room, and `Sie\Client\Exceptions\SieException` as the catch-all. The
package already retries provisioning, model loading and capacity exhaustion
within the provision-timeout budget, so do not add a retry loop of your own.

### 5. Test without a cluster

```php
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

$this->post('/documents', [...]);

SIE::assertEncoded('BAAI/bge-m3');
SIE::assertSentCount(1);
```

Vectors are deterministic, so snapshots stay stable. A model may answer several
capabilities (`FakeModel::dense(1024)->andScores([...])`), extraction answers
whole items (`FakeModel::extracting(fn ($item) => ['data' => [...]])`), failures
are answered at the wire (`FakeModel::failing(503, 'PROVISIONING')`), and
retries are instant. See the `superlinked-sie-laravel-upgrade` skill when moving
an existing suite off hand-rolled Saloon mocks.

### 6. Optional: the laravel/ai bridge

When `laravel/ai` is installed, the package registers a `sie` driver. The app
opts in by adding the provider itself:

```php
// config/ai.php
'providers' => [
    'sie' => ['driver' => 'sie', 'key' => env('SIE_KEY')],
],
```

```dotenv
SIE_AI_EMBEDDINGS_MODEL="BAAI/bge-m3"
SIE_AI_EMBEDDINGS_DIMENSIONS=1024
```

Dimensions are required and never guessed: a wrong width surfaces days later as
poor recall rather than as an error.

## Rules, References, and Templates

Read before executing:

- `vendor/avvertix/superlinked-sie-laravel/README.md` — the full public API
- `vendor/avvertix/superlinked-sie-laravel/CONTEXT.md` — the vocabulary to reuse in app code
- `vendor/avvertix/superlinked-sie-laravel/config/superlinked-sie-laravel.php` — every config key with its rationale

## Examples

- Embedding uploaded documents: build the chain in the controller, pass it to a
  queued job with the file path, and call `->encode()` inside `handle()`.
- Reranking search hits: `SIE::model(…)->score($query, $inputs)->top(10)`, using
  `Input::text($body, id: $model->getKey())` so the ids come back on the result.
- Parsing PDFs: `SIE::model('docling')->extract(Input::fromDisk('s3', $path))`,
  reading `->data` and checking `hasFailures()` before treating a batch as done.

## Anti-patterns

- Hardcoding a model's dimensions or capabilities instead of reading
  `SIE::models()` or `php artisan sie:models`; both differ per deployment.
- Treating an empty `ExtractResults` as "nothing found" without checking
  `hasFailures()` first.
- Adding a retry loop around a capability call — the package already retries
  provisioning, model loading and resource exhaustion within its budget.
- Reaching for `SIE::raw()` or a Saloon `MockClient` for something the fluent API
  or `SIE::fake()` already covers.
- Calling a capability on a model that does not declare it and catching the
  generic exception, instead of choosing the right model for the verb.
- Writing a qualified `Sie\Exceptions\…` name in a file that imports
  `Sie\Facades\SIE`. PHP matches import aliases case-insensitively, so the
  leading `Sie` binds to the `SIE` alias and the name silently resolves to
  `Sie\Facades\SIE\Exceptions\…`. Import the exception, or lead with a
  backslash.
