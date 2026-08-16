<?php

declare(strict_types=1);

namespace Sie\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest as SaloonPendingRequest;

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
    /** @var list<array{capability: string, model: string, body: array<string, mixed>}> */
    private array $recorded = [];

    private readonly MockClient $mock;

    /**
     * @param  array<string, FakeModel>  $models
     */
    public function __construct(private readonly array $models = [])
    {
        $this->mock = MockClient::global([
            '*' => fn (SaloonPendingRequest $request): MockResponse => $this->respond($request),
        ]);
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

    private function respond(SaloonPendingRequest $request): MockResponse
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

        foreach (['encode', 'score', 'extract', 'generate'] as $capability) {
            if (! str_starts_with($path, "/v1/{$capability}/")) {
                continue;
            }

            $model = substr($path, strlen("/v1/{$capability}/"));
            $this->recorded[] = ['capability' => $capability, 'model' => $model, 'body' => $body];

            return $this->{$capability}($model, $body);
        }

        if (str_starts_with($path, '/v1/models/')) {
            $model = substr($path, strlen('/v1/models/'));

            return MockResponse::make($this->modelInfo($model), 200);
        }

        return MockResponse::make(['status' => 'ok'], 200);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function encode(string $model, array $body): MockResponse
    {
        $dimensions = $this->model($model)->dimensions;

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
        $configured = $this->model($model)->scores;

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
        $entities = $this->model($model)->entities;

        $items = array_map(
            static fn (array $item): array => array_filter([
                'id' => $item['id'] ?? null,
                'entities' => $entities,
            ], static fn (mixed $value): bool => $value !== null),
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
            'text' => $this->model($model)->text,
            'finish_reason' => 'stop',
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
        $fake = $this->model($name);
        $outputs = $fake->outputs();

        return [
            'name' => $name,
            'inputs' => ['text'],
            'outputs' => $outputs,
            'dims' => $outputs === ['dense'] ? ['dense' => $fake->dimensions] : [],
            'loaded' => true,
            'state' => 'loaded',
        ];
    }

    private function model(string $name): FakeModel
    {
        // A model the test did not configure still has to answer, or every fake
        // would need the full catalog spelled out.
        return $this->models[$name] ?? FakeModel::dense(8);
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
