---
name: superlinked-sie-laravel-upgrade
description: >
  Migrate a Laravel application's tests to the Superlinked Sie Laravel v0.2.0
  test double: SIE::fake(), FakeModel answers, faked failures and recordings.
license: MIT
metadata:
  author: Alessio Vertemati
---

# Upgrading Superlinked Sie Laravel tests

Use this skill when upgrading an application from `avvertix/superlinked-sie-laravel`
v0.1.0 to v0.2.0, or when a test suite still mocks SIE at the Saloon wire level.

## Primary Goal

- move every SIE test off hand-rolled `MockClient` mocks and onto `SIE::fake()`
- fix the calls v0.2.0 now rejects, before the suite is run

## Workflow

### 1. Find the tests that touch SIE

```bash
grep -rln "SIE::fake\|MockClient::global\|Sie\\Testing" tests/
```

Read `vendor/avvertix/superlinked-sie-laravel/UPGRADE.md` for the full list of
changes. The steps below are the ones that need code edits.

### 2. Delete the global-mock workaround

`SIE::fake()` now replaces a previous fake, so the teardown that used to be
required is dead code:

```php
// Remove:
beforeEach(fn () => MockClient::destroyGlobal());
```

Keep `MockClient::destroyGlobal()` only where the application installs its own
global mock *before* calling `SIE::fake()` — that combination now throws.

### 3. Replace wire-level mocks with answers

A hand-rolled mock that shapes a SIE response couples the test to the envelope.
Move it to a `FakeModel` answer:

```php
// Before: the whole envelope by hand.
MockClient::global(['*' => MockResponse::make([
    'model' => 'docling',
    'items' => [['data' => ['markdown' => '# Invoice']]],
], 200)]);

// After: the item only. The id is echoed back for you.
SIE::fake(['docling' => FakeModel::extracting(fn (array $item) => [
    'data' => ['markdown' => '# Invoice'],
])]);
```

`extracting()` returns the extract item, so `data`, `error`, `relations`,
`classifications` and `objects` are all reachable. Use it for mixed-success
batches too — one item carrying `error`, the rest carrying results.

For a payload nobody should hand-write, record the real one once:

```php
SIE::fake(['docling' => FakeModel::recording('docling-pdf-simple')]);
```

The first run needs a reachable `SIE_ENDPOINT` and credentials and stores the
response under `tests/Fixtures/Saloon`; later runs replay it. Record with the
connection's `format` set to `json`, or the fixture holds unreadable base64
msgpack.

### 4. Give each model every answer the test needs

A configured model now raises when asked for a capability it has no answer for.
Chain the missing ones:

```php
SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)->andScores(['b' => 0.9, 'a' => 0.2])]);
```

Each link returns a new instance. Models left out of `fake()` still answer
everything with 8-dimensional vectors.

### 5. Move error and retry tests onto faked failures

```php
SIE::fake(['BAAI/bge-m3' => FakeModel::failing(400, 'INVALID_INPUT', 'items must not be empty')]);

// Retry: fail once, then answer. Sleeps are faked, so this is instant.
SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)->andFailingTimes(1, 503, 'PROVISIONING')]);
```

Failures are answered at the wire, so the package's own error handling runs and
the test sees the same typed exception production would. Drop any
`->provisionTimeout(0.1)` that only existed to keep the suite fast, and assert
waits with `SIE::assertSlept()`.

### 6. Fix what now raises or reports differently

- chat and streaming are faked properly; a test asserting an empty completion or
  an empty chunk list was asserting a bug and must be rewritten
- `SIE::assertChatted()` asserts the chat route; `assertGenerated()` no longer
  matches it
- a faked generate model declares `tokens`, not `text`, in the catalog
- `FakeModel::$capability` no longer exists
- pools and unrecognised routes raise — mock those with `MockClient` directly

### 7. Run the suite

Execute the test suite using the user's preferred tooling.

A `RuntimeException` naming `SIE::fake()` is the double telling you what it was
asked for and could not answer. Read the message: it names the route or the
missing answer.

## Rules, References, and Templates

Read before executing:

- `vendor/avvertix/superlinked-sie-laravel/UPGRADE.md` — every breaking change
- `vendor/avvertix/superlinked-sie-laravel/docs/testing.md` — the full double surface

## Examples

- A Docling processor test that replayed a captured JSON fixture through
  `MockClient::global()` becomes `FakeModel::recording()`, dropping the
  `destroyGlobal()` teardown and the connection-URL override with it.
- A job that must survive a provisioning wait is tested with
  `FakeModel::dense(1024)->andFailingTimes(1, 503, 'PROVISIONING')` plus
  `SIE::assertSlept(1)` and `SIE::assertSentCount(2)`, instead of being left
  untested.

## Anti-patterns

- Reaching for `MockClient` for anything `SIE::fake()` can answer — it couples
  the suite to the response envelope the double exists to hide.
- Asserting on an empty result without ruling out `hasFailures()`; extract
  batches are mixed-success, and the double can now produce that branch.
- Silencing a `SIE::fake() cannot answer …` exception by reintroducing a global
  mock instead of adding the answer the test needs.
- Keeping `MockClient::destroyGlobal()` in a `beforeEach` that only ever calls
  `SIE::fake()`.
