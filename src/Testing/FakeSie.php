<?php

declare(strict_types=1);

namespace Sie\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use Saloon\Http\Faking\Fixture;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest as SaloonPendingRequest;
use Sie\Client\Support\SseStream;

/**
 * Answers every SIE request locally, so application tests never reach a
 * cluster.
 *
 * Built on Saloon's `MockClient` rather than replacing it: a single reusable
 * wildcard mock intercepts each request and synthesises a shaped response.
 * Tests that need to assert on our exact wire format can still drop down to
 * `MockClient` with fixtures.
 */
final class FakeSie
{
    /** The vector width a model nobody configured encodes at. */
    private const int DEFAULT_DIMENSIONS = 8;

    /**
     * The global mock this class installed last, so a later fake() can tell its
     * own from one the application put there.
     */
    private static ?MockClient $installed = null;

    /** @var list<array{capability: string, model: string, body: array<string, mixed>}> */
    private array $recorded = [];

    /** @var list<FakeFailure> Queued by failNext(), consumed whatever the model. */
    private array $queued = [];

    /** @var array<string, int> How many failures each model has already served. */
    private array $served = [];

    private readonly MockClient $mock;

    /**
     * @param  array<string, FakeModel>  $models
     * @param  ?FakeTime  $time  The clock and sleeper the retry ladder was given, for assertSlept().
     */
    public function __construct(private readonly array $models = [], private readonly ?FakeTime $time = null)
    {
        $existing = MockClient::getGlobal();

        if ($existing !== null && $existing !== self::$installed) {
            throw new RuntimeException(
                'A global Saloon MockClient is already installed, and SIE::fake() would take over every request it answers. '.
                'Call MockClient::destroyGlobal() first if that is what you want.',
            );
        }

        // Saloon's global mock is a static that outlives Laravel's per-test
        // application rebuild, and MockClient::global() assigns with `??=`, so
        // it never replaces one. Without this, every fake() after the first in
        // a process would be inert: serving the first test's canned responses
        // and recording none of its own requests.
        MockClient::destroyGlobal();

        self::$installed = $this->mock = MockClient::global([
            '*' => fn (SaloonPendingRequest $request): MockResponse|Fixture => $this->respond($request),
        ]);
    }

    /**
     * Fails the next `$times` requests, whichever model they are for.
     *
     * For the retry story that is not about one model — "the first attempt is
     * rejected, the retry succeeds".
     */
    public function failNext(int $status, ?string $code = null, ?string $message = null, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->queued[] = new FakeFailure($status, $code, $message);
        }

        return $this;
    }

    public function mockClient(): MockClient
    {
        return $this->mock;
    }

    public function assertEncoded(string $model, ?Closure $callback = null): void
    {
        $this->assertCapability('encode', $model, $callback);
    }

    public function assertScored(string $model, ?Closure $callback = null): void
    {
        $this->assertCapability('score', $model, $callback);
    }

    public function assertExtracted(string $model, ?Closure $callback = null): void
    {
        $this->assertCapability('extract', $model, $callback);
    }

    public function assertGenerated(string $model, ?Closure $callback = null): void
    {
        $this->assertCapability('generate', $model, $callback);
    }

    /**
     * Chat and generate share one **Answer** but not one assertion: an
     * assertion that could not tell `/v1/chat/completions` from
     * `/v1/generate/{model}` would pass against the wrong route.
     */
    public function assertChatted(string $model, ?Closure $callback = null): void
    {
        $this->assertCapability('chat', $model, $callback);
    }

    /**
     * Asserts how many times the retry ladder waited.
     *
     * Counts waits, not their length: how long a retry backs off is this
     * package's business and its own tests cover it. `assertSlept(0)` is the
     * way to say a call went straight through.
     */
    public function assertSlept(int $times = 1): void
    {
        if ($this->time === null) {
            throw new RuntimeException('No time source is installed. SIE::fake() installs one; a FakeSie built by hand does not.');
        }

        PHPUnit::assertCount(
            $times,
            $this->time->slept(),
            sprintf('Expected the retry ladder to wait %d time(s).', $times),
        );
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertSame([], $this->inferenceRequests(), 'Expected no inference requests, but some were sent.');
    }

    public function assertSentCount(int $count): void
    {
        PHPUnit::assertCount($count, $this->inferenceRequests());
    }

    private function assertCapability(string $capability, string $model, ?Closure $callback): void
    {
        $matches = array_filter(
            $this->recorded,
            static fn (array $record): bool => $record['capability'] === $capability && $record['model'] === $model,
        );

        PHPUnit::assertNotEmpty(
            $matches,
            sprintf('Expected [%s] to be sent to %s(), but it was not.', $model, $capability),
        );

        if ($callback === null) {
            return;
        }

        foreach ($matches as $record) {
            if ($callback($record['body']) === true) {
                return;
            }
        }

        PHPUnit::fail(sprintf('A %s() request for [%s] was sent, but none matched the callback.', $capability, $model));
    }

    /**
     * @return list<array{capability: string, model: string, body: array<string, mixed>}>
     */
    private function inferenceRequests(): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (array $record): bool => $record['capability'] !== 'models',
        ));
    }

    private function respond(SaloonPendingRequest $request): MockResponse|Fixture
    {
        $path = parse_url($request->getUrl(), PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        // GET requests (the catalog, model info, health) carry no body at all.
        $raw = $request->body()?->all();

        /** @var array<string, mixed> $body */
        $body = is_array($raw) ? $raw : [];

        if ($path === '/v1/models') {
            return MockResponse::make(['models' => $this->catalog()], 200);
        }

        if (str_starts_with($path, '/v1/models/')) {
            return MockResponse::make($this->modelInfo(substr($path, strlen('/v1/models/'))), 200);
        }

        if ($path === '/health') {
            return $this->health();
        }

        if ($path === '/v1/chat/completions') {
            $model = is_string($body['model'] ?? null) ? $body['model'] : '';
            $this->recorded[] = ['capability' => 'chat', 'model' => $model, 'body' => $body];

            return $this->failing($model)
                ?? $this->replaying($model)
                ?? ($this->streamed($body)
                    ? $this->stream($this->chatChunks($model))
                    : $this->chat($model));
        }

        foreach (['encode', 'score', 'extract', 'generate'] as $capability) {
            if (! str_starts_with($path, "/v1/{$capability}/")) {
                continue;
            }

            $model = substr($path, strlen("/v1/{$capability}/"));

            // Only generate escapes a slash-bearing model id as `__` in its
            // path, so only generate has to put it back. Without this the
            // lookup misses the configured model, silently falls through to the
            // default fake, and answers an empty generation.
            if ($capability === 'generate') {
                $model = str_replace('__', '/', $model);
            }

            $this->recorded[] = ['capability' => $capability, 'model' => $model, 'body' => $body];

            $answered = $this->failing($model) ?? $this->replaying($model);

            if ($answered !== null) {
                return $answered;
            }

            if ($capability === 'generate' && $this->streamed($body)) {
                return $this->stream($this->generateChunks($model));
            }

            return $this->{$capability}($model, $body);
        }

        // Answering an unknown route with a plausible 200 is how a faked chat
        // completion came back empty and a faked stream yielded nothing. A test
        // double is the one place where failing loudly costs nothing.
        throw new RuntimeException(sprintf(
            'SIE::fake() cannot answer %s %s. Faked routes are inference (encode, score, extract, generate, chat), '.
            'the model catalog, and /health; anything else needs Saloon\'s MockClient directly.',
            $request->getMethod()->value,
            $path === '' ? $request->getUrl() : $path,
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function streamed(array $body): bool
    {
        return ($body['stream'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function encode(string $model, array $body): MockResponse
    {
        $dimensions = $this->answer($model, 'encode', 'FakeModel::dense(1024)')->dimensions ?? self::DEFAULT_DIMENSIONS;

        $items = array_map(
            fn (array $item): array => ['dense' => ['values' => $this->vector($this->seedOf($item), $dimensions)]],
            $this->itemsOf($body),
        );

        return MockResponse::make(['model' => $model, 'items' => $items], 200);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function score(string $model, array $body): MockResponse
    {
        $configured = $this->answer($model, 'score', 'FakeModel::scores([...])')->scores ?? [];

        if ($configured !== []) {
            arsort($configured);
            $scores = [];
            $rank = 0;

            foreach ($configured as $itemId => $score) {
                $scores[] = ['item_id' => (string) $itemId, 'score' => $score, 'rank' => $rank++];
            }

            return MockResponse::make(['model' => $model, 'scores' => $scores], 200);
        }

        // No scores configured: rank the inputs as they arrived, descending, so
        // the shape is right even when the test does not care about ordering.
        $items = $this->itemsOf($body);
        $count = count($items);

        $scores = [];

        foreach ($items as $rank => $item) {
            $scores[] = [
                'item_id' => (string) ($item['id'] ?? $rank),
                'score' => $count > 0 ? round(1 - ($rank / max($count, 1)), 6) : 0.0,
                'rank' => $rank,
            ];
        }

        return MockResponse::make(['model' => $model, 'scores' => $scores], 200);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extract(string $model, array $body): MockResponse
    {
        $fake = $this->answer($model, 'extract', 'FakeModel::entities([...]) or FakeModel::extracting(...)');
        $answer = $fake->extracting;

        $items = array_map(
            static function (array $item) use ($fake, $answer): array {
                // Whatever the callback returns is the item, so a fake can
                // answer with `data`, `error`, `relations` — anything
                // ExtractResult::fromArray() understands — not just entities.
                $members = $answer !== null ? $answer($item) : ['entities' => $fake->entities ?? []];

                return array_filter([
                    'id' => $item['id'] ?? null,
                    ...$members,
                ], static fn (mixed $value): bool => $value !== null);
            },
            $this->itemsOf($body),
        );

        return MockResponse::make(['model' => $model, 'items' => $items], 200);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function generate(string $model, array $body): MockResponse
    {
        return MockResponse::make([
            'model' => $model,
            'text' => $this->generated($model, 'generate'),
            'finish_reason' => 'stop',
        ], 200);
    }

    /**
     * Chat and generate are one **Answer**: the same canned text, whichever
     * route asked for it. Only the assertions tell the two routes apart.
     */
    private function chat(string $model): MockResponse
    {
        return MockResponse::make([
            'id' => 'chatcmpl-fake',
            'object' => 'chat.completion',
            'created' => 0,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $this->generated($model, 'chat')],
                'finish_reason' => 'stop',
            ]],
        ], 200);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateChunks(string $model): array
    {
        return [
            ['seq' => 0, 'text_delta' => $this->generated($model, 'generate'), 'done' => false],
            ['seq' => 1, 'done' => true, 'finish_reason' => 'stop'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chatChunks(string $model): array
    {
        $envelope = ['id' => 'chatcmpl-fake', 'object' => 'chat.completion.chunk', 'created' => 0, 'model' => $model];

        return [
            [...$envelope, 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $this->generated($model, 'chat')]]]],
            [...$envelope, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ];
    }

    /**
     * The gateway's simplified SSE emission: one single-line `data: <json>` per
     * event, then a literal `data: [DONE]` (see {@see SseStream}).
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function stream(array $events): MockResponse
    {
        $body = '';

        foreach ($events as $event) {
            $body .= 'data: '.json_encode($event)."\n\n";
        }

        return MockResponse::make($body."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);
    }

    /**
     * A healthy single-worker gateway. `waitForCapacity()` polls this route, so
     * a fake that could not answer it would push a normal application path out
     * to the wire.
     */
    private function health(): MockResponse
    {
        return MockResponse::make([
            'type' => 'gateway',
            'status' => 'healthy',
            'cluster' => ['worker_count' => 1, 'gpu_count' => 1, 'models_loaded' => count($this->models)],
            'configured_gpu_types' => ['l4'],
            'live_gpu_types' => ['l4'],
            'workers' => [[
                'url' => 'https://worker.fake',
                'gpu' => 'l4',
                'healthy' => true,
                'queue_depth' => 0,
                'loaded_models' => array_keys($this->models),
            ]],
        ], 200);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $models = [];

        foreach ($this->models as $name => $fake) {
            $models[] = $this->modelInfo($name);
        }

        return $models;
    }

    /**
     * @return array<string, mixed>
     */
    private function modelInfo(string $name): array
    {
        $fake = $this->models[$name] ?? self::unconfigured();
        $outputs = $fake->outputs();

        return [
            'name' => $name,
            'inputs' => ['text'],
            'outputs' => $outputs,
            'dims' => $fake->dimensions !== null ? ['dense' => $fake->dimensions] : [],
            'loaded' => true,
            'state' => 'loaded',
        ];
    }

    /**
     * The **Recording** this model replays, or null to synthesise an answer.
     *
     * Saloon captures the response on the first run and replays it after, so
     * the envelope is the cluster's own — the one case where nobody has to know
     * the shape. The request is still recorded before we get here, so the
     * assertions work exactly as they do for a synthesised answer.
     */
    private function replaying(string $model): ?Fixture
    {
        $recording = ($this->models[$model] ?? null)?->recording;

        return $recording !== null ? new Fixture($recording) : null;
    }

    /**
     * The failure this request is answered with, or null to answer normally.
     *
     * Queued failures are consumed first and are model-agnostic; a model's own
     * failure answers until its `times` budget is spent.
     */
    private function failing(string $model): ?MockResponse
    {
        $queued = array_shift($this->queued);

        if ($queued !== null) {
            return $queued->toResponse();
        }

        $failure = ($this->models[$model] ?? null)?->failure;

        if ($failure === null) {
            return null;
        }

        $served = $this->served[$model] ?? 0;

        if ($failure->times !== null && $served >= $failure->times) {
            return null;
        }

        $this->served[$model] = $served + 1;

        return $failure->toResponse();
    }

    /**
     * The **Answer** $model gives for $capability.
     *
     * A model the test never configured answers with defaults, or every fake
     * would need the whole catalog spelled out. A model it did configure
     * answers only what it was given an answer for: asking a faked extractor to
     * encode is a mistake, and a plausible-looking vector would hide it.
     */
    private function answer(string $model, string $capability, string $how): FakeModel
    {
        $fake = $this->models[$model] ?? null;

        if ($fake === null) {
            return self::unconfigured();
        }

        if (! $fake->answers($capability)) {
            throw new RuntimeException(sprintf(
                'SIE::fake() has no %s answer for [%s]. Give it one with %s, or leave the model out of fake() to get the default answer.',
                $capability,
                $model,
                $how,
            ));
        }

        return $fake;
    }

    private function generated(string $model, string $capability): string
    {
        return $this->answer($model, $capability, 'FakeModel::text(...)')->text ?? '';
    }

    /** What a model nobody configured answers: anything, blandly. */
    private static function unconfigured(): FakeModel
    {
        return FakeModel::dense(self::DEFAULT_DIMENSIONS)->andEntities([])->andScores([])->andText('');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function itemsOf(array $body): array
    {
        $items = $body['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedOf(array $item): string
    {
        return json_encode($item) ?: '';
    }

    /**
     * A stable pseudo-random vector derived from the input.
     *
     * Deliberately not `random_bytes()` or `mt_rand()`: a fake that returns a
     * different vector each run makes snapshot tests flap, and identical inputs
     * must embed identically for a test to be able to say so.
     *
     * @return list<float>
     */
    private function vector(string $seed, int $dimensions): array
    {
        $hash = crc32($seed);
        $values = [];

        for ($i = 0; $i < $dimensions; $i++) {
            // Knuth's multiplicative constant, kept inside 32 bits so the result
            // does not depend on the platform's integer width.
            $mixed = ($hash + ($i * 2654435761)) & 0xFFFFFFFF;
            $values[] = round(($mixed % 20000) / 10000 - 1, 6);
        }

        return $values;
    }
}
