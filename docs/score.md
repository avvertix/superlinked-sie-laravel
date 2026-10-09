# Score

Scoring ranks inputs by how relevant they are to a query. The most relevant comes first.

```php
use Sie\Facades\SIE;
use Sie\Input;

$ranked = SIE::model('BAAI/bge-m3')->score('waterfowl that swims', [
    Input::text('a duck paddles on a pond', 'duck'),
    Input::text('a turbine spins', 'turbine'),
]);

$ranked->top(1);              // ['duck']
$ranked->first()->score;      // 0.82…
```

Give each input an id, as above, and `top()` hands back the ids in ranked order.

## Response details

Some information describes the whole call rather than a single item. You read it from the collection:

```php
$ranked = SIE::model('BAAI/bge-m3')->score($query, $documents);

$ranked->model;              // the model that actually served it, after alias/profile resolution
$ranked->usage->inputTokens; // 34, the consumed tokens
$ranked->queryId;            // server-assigned, when the cluster assigns one
```

## Next

- [Inputs and files](inputs-and-files.md): ids, images and documents as inputs
- [Results](results.md): what comes back
- [Laravel AI](laravel-ai.md): reranking through `laravel/ai`
