# Testing

SIE for Laravel provide testing facilities. `SIE::fake()` answers every request locally. Vectors are deterministic: the same input always gets the same embedding, so snapshot tests stay stable.

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

`assertScored()`, `assertExtracted()`, `assertGenerated()` and `assertChatted()` work the same way.

## One fake model, several answers

A real model often serves more than one capability (`BAAI/bge-m3` encodes and scores), so a `FakeModel` holds one answer per capability. Start with a static constructor and chain `and…()` methods for the rest. Each call returns a new instance, which means you can share a fake between tests.

```php
SIE::fake([
    'BAAI/bge-m3' => FakeModel::dense(1024)->andScores(['doc-b' => 0.9, 'doc-a' => 0.2]),
]);
```

The fake catalog lists the outputs those answers imply, here `['dense', 'score']`. If the real model declares an output your fake doesn't answer, add it with `->declaring(['dense', 'sparse'])`.

A configured model only answers what you gave it. Ask a faked extractor to encode and it throws instead of inventing a vector. Models you didn't configure answer everything with 8-dimensional vectors, so a bare `SIE::fake()` works without a catalog.

The fake doesn't serve every route. Pools, for example, throw; use Saloon's `MockClient` for those.

[ADR 0007](adr/0007-a-fake-holds-a-models-answers.md) explains the design.

## Extraction beyond entities

`FakeModel::entities()` gives every input the same answer. When the answer depends on the input, or isn't entities at all, use `FakeModel::extracting()`. Its callback returns the extract item itself, so you can set `data`, `error`, `relations`, `classifications` or `objects`:

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

The callback receives one input item in its wire shape, and its `id` is copied onto the answer for you. This is how you test mixed-success batches: return `error` for one item and results for the rest, then check how your code handles `hasFailures()`, `failed()` and `throwIfAnyFailed()`.

## Failures and retries

A faked failure is a real HTTP status with an error body. It goes through the package's normal error handling, so your code gets the same typed exception the cluster would cause:

```php
SIE::fake(['BAAI/bge-m3' => FakeModel::failing(400, 'INVALID_INPUT', 'items must not be empty')]);
```

To test a retry, fail a fixed number of times and then answer normally:

```php
SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)->andFailingTimes(1, 503, 'PROVISIONING')]);

SIE::model('BAAI/bge-m3')->encode('Hello world');   // waits, retries, succeeds

SIE::assertSlept(1);      // the ladder waited once
SIE::assertSentCount(2);  // the rejected attempt and the retry
```

Retries don't actually wait. The fake acts as the clock and the sleeper for the retry logic, so the test runs instantly while the provisioning timeout budget is still counted. Laravel's `Sleep` is untouched, so if your own code uses `Sleep::fake()`, its sequence is unaffected.

To fail the next request whatever model it targets, use `SIE::fake([...])->failNext(503, 'PROVISIONING')`.

## Recording a real response

Some answers aren't worth writing by hand, such as a parsed document or any payload whose shape comes from the model. Record those instead:

```php
SIE::fake(['docling' => FakeModel::recording('docling-pdf-simple')]);
```

The first run sends a real request, and Saloon saves the response under `tests/Fixtures/Saloon`. Later runs replay that file. Before you record:

- The recording run needs a reachable `SIE_ENDPOINT` and valid credentials.
- Set the connection's `format` to `json` for that run. Otherwise the fixture holds base64-encoded msgpack, which is unreadable in a diff.
- To refresh a recording, delete the file and run again.

If a test depends on the exact wire format, use Saloon's `MockClient` with fixtures directly. If you install your own global mock, call `MockClient::destroyGlobal()` before `SIE::fake()`, because `SIE::fake()` refuses to replace an existing global mock.
