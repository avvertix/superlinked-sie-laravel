<div align="center">
    <h1>SIE for Laravel</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/v/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://img.shields.io/packagist/php-v/avvertix/superlinked-sie-laravel.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/avvertix/superlinked-sie-laravel"><img src="https://badge.laravel.cloud/badge/avvertix/superlinked-sie-laravel?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/avvertix/superlinked-sie-laravel/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/avvertix/superlinked-sie-laravel/tests.yml?branch=main&label=Tests&style=flat-square"></a>
</p>

<p align="center">
Encode, score, extract and generate with <a href="https://superlinked.com/" target="_blank">Superlinked Inference Engine (SIE)</a> from Laravel.
</p>

<p align="center">
    <a href="#requirements">Requirements</a>
    <span> · </span>
    <a href="#installation">Installation</a>
    <span> · </span>
    <a href="#usage">Usage</a>
    <span> · </span>
    <a href="#laravel-ai-integration">Laravel AI Integration</a>
    <span> · </span>
    <a href="#configuration">Configuration</a>
    <span> · </span>
    <a href="#testing">Testing</a>
    <span> · </span>
    <a href="#upgrading">Upgrading</a>
    <span> ·· </span>
    <a href="./docs/">Guides</a>
</p>

---

SIE is an inference cluster for search and document processing. It serves [over 100 models](https://superlinked.com/models) for encoding, scoring and extraction. This package gives you a fluent API to call them from Laravel, and registers SIE as a provider for [`laravel/ai`](https://github.com/laravel/ai).

```php
use Sie\Facades\SIE;

SIE::model('BAAI/bge-m3')->encode('a duck paddles on a pond')->sole()->dense; // [0.017, -0.041, …]
```

## Requirements

- PHP 8.3 or later, Laravel 12 or 13
- A running SIE cluster, either [self-hosted](https://superlinked.com/docs/deployment) or on [Superlinked Cloud](https://superlinked.com/cloud). You need its endpoint and an API key.
- Optionally, [`laravel/ai`](https://github.com/laravel/ai) `^1.2` for the [Laravel AI integration](#laravel-ai-integration)

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

## Usage

Pick a model with `SIE::model()`, chain any options, then call one of its four capabilities: `encode`, `score`, `extract` or `generate`. If SIE's terms are new to you, [Concepts](docs/concepts.md) explains them.

### Encode

Turn inputs into vectors. Call `asQuery()` when you encode a search query rather than a stored document.

```php
SIE::model('BAAI/bge-m3')->encode('a duck paddles on a pond');
SIE::model('BAAI/bge-m3')->asQuery()->encode('waterfowl that swims');
```

More in [Encode](docs/encode.md): batches, sparse and multivector outputs, instructions.

### Score

Rank inputs by how relevant they are to a query. The most relevant comes first.

```php
use Sie\Input;

$ranked = SIE::model('BAAI/bge-m3')->score('waterfowl that swims', [
    Input::text('a duck paddles on a pond', 'duck'),
    Input::text('a turbine spins', 'turbine'),
]);

$ranked->top(1); // ['duck']
```

More in [Score](docs/score.md).

### Extract

Pull structured data out of inputs: entities, relations, classifications, parsed documents, or a schema of your own.

```php
$results = SIE::model('urchade/gliner_multi-v2.1')
    ->labels(['person', 'location'])
    ->extract('Ada Lovelace was born in London.');

$results->sole()->entities; // [Entity{text: 'Ada Lovelace', label: 'person', …}, …]
```

> [!IMPORTANT]
> A batch does not abort when one input fails. That input stays in the collection with its error, so an empty result is not proof that nothing was found. Check `$results->hasFailures()`, or call `$results->throwIfAnyFailed()`. See [Results](docs/results.md).

More in [Extract](docs/extract.md), including document parsing. To pass documents and images from any disk, see [Inputs and files](docs/inputs-and-files.md).

### Generate

Produce text from a prompt, whole or streamed.

```php
SIE::model('some-llm')->maxNewTokens(256)->generate('Summarise: …')->text;
```

More in [Generate](docs/generate.md).

## Laravel AI Integration

If [`laravel/ai`](https://github.com/laravel/ai) is installed, SIE registers a `sie` driver for embeddings, reranking and classification. Add the provider to `config/ai.php` and set the embeddings model and dimensions:

```php
'providers' => [
    'sie' => ['driver' => 'sie', 'key' => env('SIE_KEY')],
],
```

```dotenv
SIE_AI_EMBEDDINGS_MODEL="BAAI/bge-m3"
SIE_AI_EMBEDDINGS_DIMENSIONS=1024
```

```php
use Laravel\Ai\Embeddings;

Embeddings::for(['a duck paddles on a pond'])->generate('sie');
```

Classification runs on a decision model such as `fastino/GLiNER2.5-Decide`:

```php
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;

$response = Classification::of($supportRequest)
    ->questions(['urgent' => new Boolean('Does this request need an immediate response?')])
    ->classify('sie');

$response->answer('urgent')->isTrue(0.9);
```

More in [Laravel AI](docs/laravel-ai.md): reranking, provider options, `Choice` and `Score` questions.

## Configuration

Publish the configuration file with:

```bash
php artisan vendor:publish --tag="superlinked-sie-laravel-config"
```

A connection is one endpoint and its credentials. Define one for each cluster and pick it with `SIE::connection('eu')`. To see which models a cluster serves, run `php artisan sie:models`.

[Configuration](docs/configuration.md) covers connections and the wire format (msgpack or JSON). [Console](docs/console.md) covers the command's filters.

## A note on the facade

PHP identifiers are case-insensitive, so importing the facade shadows the package's own namespace prefix within that file:

```php
use Sie\Facades\SIE;

Sie\Input::text('…');   // ✗ resolves to Sie\Facades\SIE\Input
\Sie\Input::text('…');  // ✓
use Sie\Input;          // ✓ better still — just import it
```

## Testing

`SIE::fake()` answers every request locally. The same input always gets the same vector, so snapshot tests stay stable.

```php
use Sie\Testing\FakeModel;

SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

$this->post('/documents', [...]);

SIE::assertEncoded('BAAI/bge-m3');
SIE::assertSentCount(1);
```

More in [Testing](docs/testing.md): faking extraction results, failures and retries, and recording real responses.

## Upgrading

[UPGRADE.md](UPGRADE.md) lists the changes you need to make between versions. If you use [Boost](https://github.com/laravel/boost), the package also ships a `superlinked-sie-laravel-upgrade` skill your agent can follow.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Superlinked SIE for Laravel! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Alessio Vertemati](https://github.com/avvertix)
- [All Contributors](../../contributors)

## License

SIE for Laravel is open-sourced software licensed under the [MIT license](LICENSE.md).
