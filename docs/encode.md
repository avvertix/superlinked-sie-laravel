# Encode

Encoding turns inputs into vectors.

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

## Queries and documents

Some models encode a search query differently from a stored document. Call `asQuery()` when you encode a query:

```php
SIE::model('BAAI/bge-m3')->asQuery()->encode('waterfowl that swims');
```

## Instructions and conditional options

Options chain, and `when()` applies one only if a condition holds:

```php
$vectors = SIE::model('BAAI/bge-m3')
    ->instruction('Represent this sentence for retrieval')
    ->when($isSearchQuery, fn ($request) => $request->asQuery())
    ->encode('a duck paddles on a pond');

$vectors->sole()->dense; // [0.017, -0.041, …]
```

## Next

- [Inputs and files](inputs-and-files.md): images, documents and files on any disk
- [Results](results.md): what comes back
- [Profiles, pools and GPUs](capacity.md): profiles such as `BAAI/bge-m3:multivector`
