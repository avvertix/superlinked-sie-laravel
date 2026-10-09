# Laravel AI

SIE for Laravel register a `sie` driver when [`laravel/ai`](https://github.com/laravel/ai) is installed. In order to use it you still need to add the provider to `config/ai.php`:

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

## Embeddings and reranking

```php
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

Embeddings::for(['a duck paddles on a pond'])->generate('sie');
Embeddings::for(['a duck paddles on a pond'])->dimensions(1024)->generate('sie', 'BAAI/bge-m3');

Reranking::of(['a duck', 'a turbine'])->rerank('waterfowl', 'sie');
```

Set `default_for_embeddings` or `default_for_reranking` to `sie` in `config/ai.php` and you can leave out the provider argument. Vector stores and agents then use SIE without any extra setup.

When you call the provider directly, you can pass SIE options such as `instruction`, `is_query`, `profile`, `pool` and `gpu` through `providerOptions`:

```php
app(Laravel\Ai\AiManager::class)->instance('sie')->embeddings(
    ['a duck paddles on a pond'],
    dimensions: 1024,
    providerOptions: ['instruction' => 'retrieve', 'is_query' => true, 'pool' => 'eval-bench'],
);
```

Laravel AI embeddings carry a single dense vector. For sparse or multivector output, use the SIE facade directly.

> [!NOTE]
> Configure model dimensions explicitly as the bridge never guesses model dimensions.

## Classification

SIE can also answer `laravel/ai`'s typed questions, using a decision model such as `fastino/GLiNER2.5-Decide`. All the questions about one record go out in a single [`extract` call](./extract.md), and every answer comes with a probability you can act on:

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

`Str::of($message)->decide('Is this spam?', provider: 'sie')` works the same way. Set `default_for_classification` to `sie` in `config/ai.php` to leave out the provider argument.

| `laravel/ai` question | SIE type | Answer |
|---|---|---|
| `Boolean` | `noul` | `BooleanAnswer`, the probability of "true" |
| `Choice` | `choice` | `ChoiceAnswer`, the winning option plus a probability per option |
| `Score` | `score` | `ScoreAnswer`, the expected level plus a probability per level |

The default model is `fastino/GLiNER2.5-Decide`. Change it with `SIE_AI_CLASSIFICATION_MODEL`. The cluster must serve a decision model, and every question needs instructions.

### What the model reads

> [!WARNING]
> Only text-based input is currently supported for decision models.

- A string state is sent as is. An array state is sent as JSON text.
- Text attachments are appended to that text, each under its file name. That covers `Document::fromPath('email.txt')` and uploaded `text/*`, JSON, XML or YAML files: `Classification::of($state, [Document::fromPath('email.txt')])`.
- Images and non-textual files throw an `InvalidArgumentException`.

### Limits

- Token usage isn't reported, so `usage` is always empty.
- Label-group classifiers such as GLiClass aren't wired into `laravel/ai`. Call them through `SIE::model(...)->extract()` instead.
