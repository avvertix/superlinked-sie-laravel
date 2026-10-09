# Results

Every capability returns a `Collection` with one result per input, in the order you sent them. That holds for a single input too, so use `sole()` or `first()` to unwrap it.

## Mixed-success extraction

An extraction batch can partly succeed. If the cluster can't process one input, that input stays in the collection with its error, and the rest of the batch still comes back. So before you read an empty result as "nothing found", check that nothing failed:

```php
$results = SIE::model('docling')->extract($documents);

$results->hasFailures();     // bool
$results->failed();          // the ones that errored
$results->succeeded();       // the ones that did not
$results->throwIfAnyFailed(); // opt into strictness
```

[ADR 0004](adr/0004-partial-failure-is-silent-by-default.md) explains why partial failure is silent by default.

## Response details

A score response carries information about the whole call, not about any one item. It lives on the collection and is kept when you filter or sort:

```php
$ranked = SIE::model('BAAI/bge-m3')->score($query, $documents);

$ranked->model;              // the model that actually served it, after alias/profile resolution
$ranked->usage->inputTokens; // 34, the consumed tokens
$ranked->queryId;            // server-assigned, when the cluster assigns one
```
