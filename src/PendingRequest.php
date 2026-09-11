<?php

declare(strict_types=1);

namespace Sie;

use Illuminate\Container\Container;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Dumpable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Sie\Client\Data\EncodeResult;
use Sie\Client\Data\ExtractResult;
use Sie\Client\Data\GenerateChunk;
use Sie\Client\Data\GenerateResult;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Data\ScoreResult;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\SieClient;
use Sie\Client\Support\ErrorCodes;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Exceptions\RequestTooLargeException;
use Sie\Exceptions\UnsupportedCapabilityException;
use Sie\Results\EncodeResults;
use Sie\Results\ExtractResults;
use Sie\Results\ScoreResults;
use SplFileInfo;

/**
 * A request you are still building.
 *
 * The chain is flat: every option for every **Capability** lives on this one
 * class and the terminal verb — `encode()`, `score()`, `extract()`,
 * `generate()` — is what executes. Options that do not apply to the terminal
 * you call raise `InvalidArgumentException` rather than being silently ignored;
 * ADR 0003 records why that check lives at runtime instead of in the type
 * system.
 *
 * Only the **Connection** name is held, never a resolved client, so a
 * half-built chain can be handed to a queued job (see ADR 0005).
 */
final class PendingRequest
{
    use Conditionable;
    use Dumpable;
    use Macroable;

    /**
     * Which options each terminal verb understands. Routing options (profile,
     * pool, gpu, and the capacity settings) apply everywhere and are held in
     * their own properties rather than in `$params`.
     */
    private const array CAPABILITY_OPTIONS = [
        'encode' => ['instruction', 'options', 'outputs', 'outputDtype', 'asQuery'],
        'score' => ['instruction', 'options'],
        'extract' => ['instruction', 'options', 'labels', 'schema'],
        'generate' => ['maxNewTokens', 'temperature', 'topP', 'stop'],
    ];

    private const int DEFAULT_MAX_REQUEST_BYTES = 33554432;

    private const int DEFAULT_MAX_NEW_TOKENS = 512;

    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $pool = null;

    private ?string $gpu = null;

    private bool $waitForCapacity = true;

    private ?float $provisionTimeout = null;

    private ?int $maxOomRetries = null;

    public function __construct(
        private ?string $connection = null,
        private ?string $model = null,
    ) {}

    public function connection(?string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Select a named variant of the model, sent as `model:profile`.
     *
     * A **Profile** changes which variant of the model runs; it is not the same
     * as {@see outputs()}, which chooses which representations come back. The
     * distinction matters because `BAAI/bge-m3` has profiles literally named
     * `dense`, `sparse`, and `multivector`.
     */
    public function profile(string $profile): self
    {
        if ($this->model !== null && str_contains($this->model, ':')) {
            throw new InvalidArgumentException("The model id [{$this->model}] already names a profile.");
        }

        $this->params['profile'] = $profile;

        return $this;
    }

    /** Route to a named resource **Pool**. */
    public function pool(string $pool): self
    {
        $this->pool = $pool;

        return $this;
    }

    /** Route to a **GPU type**, e.g. `l4`. */
    public function gpu(string $gpu): self
    {
        $this->gpu = $gpu;

        return $this;
    }

    /** Fail immediately instead of waiting for **Provisioning**. */
    public function withoutWaitingForCapacity(): self
    {
        $this->waitForCapacity = false;

        return $this;
    }

    public function waitForCapacity(bool $wait = true): self
    {
        $this->waitForCapacity = $wait;

        return $this;
    }

    public function provisionTimeout(float $seconds): self
    {
        $this->provisionTimeout = $seconds;

        return $this;
    }

    public function maxOomRetries(int $retries): self
    {
        $this->maxOomRetries = $retries;

        return $this;
    }

    /** Steer an **Encode** or **Score** with a model-specific instruction. */
    public function instruction(string $instruction): self
    {
        $this->params['instruction'] = $instruction;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): self
    {
        $this->params['options'] = [...($this->params['options'] ?? []), ...$options];

        return $this;
    }

    /**
     * Choose which **Outputs** come back. Omit to inherit the cluster's own
     * default, which is `dense`.
     *
     * @param  list<string>  $types
     */
    public function outputs(array $types): self
    {
        $this->params['outputs'] = $types;

        return $this;
    }

    public function outputDtype(string $dtype): self
    {
        $this->params['outputDtype'] = $dtype;

        return $this;
    }

    /** Encode as a search query rather than as a stored document. */
    public function asQuery(bool $isQuery = true): self
    {
        $this->params['asQuery'] = $isQuery;

        return $this;
    }

    /**
     * @param  list<string>  $labels
     */
    public function labels(array $labels): self
    {
        $this->params['labels'] = $labels;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    public function schema(array $schema): self
    {
        $this->params['schema'] = $schema;

        return $this;
    }

    public function maxNewTokens(int $tokens): self
    {
        $this->params['maxNewTokens'] = $tokens;

        return $this;
    }

    public function temperature(float $temperature): self
    {
        $this->params['temperature'] = $temperature;

        return $this;
    }

    public function topP(float $topP): self
    {
        $this->params['topP'] = $topP;

        return $this;
    }

    /**
     * @param  string|list<string>  $stop
     */
    public function stop(string|array $stop): self
    {
        $this->params['stop'] = $stop;

        return $this;
    }

    /**
     * Turn inputs into vector **Outputs**.
     *
     * @param  Input|File|SplFileInfo|string|iterable<mixed>  $inputs
     */
    public function encode(Input|File|SplFileInfo|string|iterable $inputs): EncodeResults
    {
        $this->assertOptionsApplyTo('encode');
        $model = $this->resolvedModel('encode');
        $items = $this->wireItems($inputs);

        /** @var list<EncodeResult> $results */
        $results = $this->run('encode', $model, fn (): array|EncodeResult => $this->client()->encode(
            model: $model,
            items: $items,
            outputTypes: $this->params['outputs'] ?? null,
            instruction: $this->params['instruction'] ?? null,
            outputDtype: $this->params['outputDtype'] ?? null,
            isQuery: $this->params['asQuery'] ?? null,
            options: $this->params['options'] ?? null,
            gpu: $this->routing(),
            waitForCapacity: $this->waitForCapacity,
            provisionTimeoutS: $this->provisionTimeout,
            maxOomRetries: $this->maxOomRetries ?? ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
        ));

        return new EncodeResults($results);
    }

    /**
     * Rank inputs by relevance to a query.
     *
     * @param  Input|File|SplFileInfo|string|iterable<mixed>  $inputs
     */
    public function score(Input|File|SplFileInfo|string $query, Input|File|SplFileInfo|string|iterable $inputs): ScoreResults
    {
        $this->assertOptionsApplyTo('score');
        $model = $this->resolvedModel('score');
        $queryInput = Input::from($query);
        $items = $this->wireItems($inputs, $queryInput->bytes());

        $result = $this->run('score', $model, fn (): ScoreResult => $this->client()->score(
            model: $model,
            query: $queryInput->toArray(),
            items: $items,
            instruction: $this->params['instruction'] ?? null,
            options: $this->params['options'] ?? null,
            gpu: $this->routing(),
            waitForCapacity: $this->waitForCapacity,
            provisionTimeoutS: $this->provisionTimeout,
            maxOomRetries: $this->maxOomRetries ?? ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
        ));

        return ScoreResults::fromResult($result);
    }

    /**
     * Pull structured data out of inputs.
     *
     * Batches are mixed-success: a failed input stays in the returned
     * collection carrying its error (see ADR 0004). Call
     * `throwIfAnyFailed()` on the result to opt into strictness.
     *
     * @param  Input|File|SplFileInfo|string|iterable<mixed>  $inputs
     */
    public function extract(Input|File|SplFileInfo|string|iterable $inputs): ExtractResults
    {
        $this->assertOptionsApplyTo('extract');
        $model = $this->resolvedModel('extract');
        $items = $this->wireItems($inputs);

        /** @var list<ExtractResult> $results */
        $results = $this->run('extract', $model, fn (): array|ExtractResult => $this->client()->extract(
            model: $model,
            items: $items,
            labels: $this->params['labels'] ?? null,
            outputSchema: $this->params['schema'] ?? null,
            instruction: $this->params['instruction'] ?? null,
            options: $this->params['options'] ?? null,
            gpu: $this->routing(),
            waitForCapacity: $this->waitForCapacity,
            provisionTimeoutS: $this->provisionTimeout,
            maxOomRetries: $this->maxOomRetries ?? ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
        ));

        return new ExtractResults($results);
    }

    /** Produce text from a prompt. */
    public function generate(string $prompt): GenerateResult
    {
        $this->assertOptionsApplyTo('generate');
        $model = $this->resolvedModel('generate');

        return $this->run('generate', $model, fn (): GenerateResult => $this->client()->generate(
            model: $model,
            prompt: $prompt,
            maxNewTokens: $this->params['maxNewTokens'] ?? self::DEFAULT_MAX_NEW_TOKENS,
            temperature: $this->params['temperature'] ?? null,
            topP: $this->params['topP'] ?? null,
            stop: $this->params['stop'] ?? null,
            gpu: $this->routing(),
            waitForCapacity: $this->waitForCapacity,
            provisionTimeoutS: $this->provisionTimeout,
            maxOomRetries: $this->maxOomRetries ?? ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
        ));
    }

    /**
     * Produce text from a prompt, a chunk at a time.
     *
     * The returned collection wraps a live SSE stream, so it is single-pass:
     * iterating it a second time yields nothing.
     *
     * @return LazyCollection<int, GenerateChunk>
     */
    public function stream(string $prompt): LazyCollection
    {
        $this->assertOptionsApplyTo('generate');

        $model = $this->resolvedModel('generate');
        $client = $this->client();
        $params = $this->params;
        $routing = $this->routing();
        $wait = $this->waitForCapacity;
        $provisionTimeout = $this->provisionTimeout;
        $maxOomRetries = $this->maxOomRetries ?? ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES;

        return LazyCollection::make(static fn (): iterable => $client->streamGenerate(
            model: $model,
            prompt: $prompt,
            maxNewTokens: $params['maxNewTokens'] ?? self::DEFAULT_MAX_NEW_TOKENS,
            temperature: $params['temperature'] ?? null,
            topP: $params['topP'] ?? null,
            stop: $params['stop'] ?? null,
            gpu: $routing,
            waitForCapacity: $wait,
            provisionTimeoutS: $provisionTimeout,
            maxOomRetries: $maxOomRetries,
        ));
    }

    /**
     * Details for this model, read from the cluster's model config. Does not
     * load the model or trigger inference.
     */
    public function info(): ModelInfo
    {
        return $this->client()->getModel($this->resolvedModel('info'));
    }

    /**
     * Load this model onto a worker ahead of real traffic.
     *
     * A probe input is required because there is no input every model accepts:
     * `docling` declares `inputs: [image, document]` and rejects text outright,
     * so a text probe would fail for exactly the models that are slowest to
     * load. The probe is dispatched through whichever capability the model's
     * declared **Outputs** support.
     */
    public function warmup(Input|string $probe): ModelInfo
    {
        $info = $this->info();
        $outputs = $info->outputs ?? [];

        if (array_intersect($outputs, ['dense', 'sparse', 'multivector']) !== []) {
            $this->encode($probe);
        } elseif (in_array('json', $outputs, true)) {
            $this->extract($probe);
        } elseif (in_array('score', $outputs, true)) {
            $this->score($probe, [$probe]);
        } else {
            throw new InvalidArgumentException(
                "Cannot warm up [{$info->name}]: it declares no outputs this package knows how to probe.",
            );
        }

        return $this->info();
    }

    /**
     * The wire model id, with any **Profile** composed in.
     */
    public function modelId(): ?string
    {
        if ($this->model === null) {
            return null;
        }

        $profile = $this->params['profile'] ?? null;

        return is_string($profile) ? "{$this->model}:{$profile}" : $this->model;
    }

    private function resolvedModel(string $capability): string
    {
        $model = $this->modelId();

        if ($model === null) {
            throw new InvalidArgumentException("No model was given. Call model() before {$capability}().");
        }

        return $model;
    }

    /**
     * Reject options that belong to a different capability, rather than
     * silently dropping them (ADR 0003).
     */
    private function assertOptionsApplyTo(string $capability): void
    {
        $allowed = [...self::CAPABILITY_OPTIONS[$capability], 'profile'];

        foreach (array_keys($this->params) as $option) {
            if (! in_array($option, $allowed, true)) {
                throw new InvalidArgumentException("The [{$option}] option does not apply to {$capability}().");
            }
        }
    }

    /**
     * Run a terminal call, translating the cluster's "wrong kind of model"
     * rejection into a typed exception.
     *
     * Since we deliberately do not preflight against the model catalog
     * (ADR 0003), this is where that mistake becomes legible.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function run(string $capability, string $model, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RequestException|ServerException $exception) {
            if (self::isCapabilityMismatch($exception)) {
                throw new UnsupportedCapabilityException(
                    "Model '{$model}' cannot serve {$capability}(): {$exception->getMessage()}",
                    $model,
                    $capability,
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    /**
     * Does this rejection mean "that model does not serve this capability"?
     *
     * The cluster has no dedicated code for it, and both codes below are shared
     * with unrelated failures, so the message is what decides:
     *
     * - `400 INVALID_INPUT` — "Model 'docling' does not support output types:
     *   {'dense'}", raised when the model is routable but rejects the request.
     * - `503 QUEUE_UNAVAILABLE` — `missing rate for model="docling",
     *   profile="default", operation="encode", region="us"`. This is the
     *   gateway's terminal "the rate book cannot price this, so it will not be
     *   run" answer (`ESTIMATE_UNROUTABLE_ERROR_CODES` in the Python SDK); it
     *   is not retried and carries no `Retry-After`. A missing rate for a
     *   (model, profile, operation, region) tuple is how an operation the model
     *   does not serve surfaces — though strictly it says the tuple is
     *   unpriced, which a capable-but-unpriced model would report identically.
     *   The same code also carries reasons that are about the input rather than
     *   the identity ("page pricing requires a non-empty image or document
     *   input"), hence matching the `missing rate` prefix rather than the code.
     *
     * The retryable 503s ({@see RetryingRequestSender} consumes them first)
     * never reach here: `PROVISIONING`, `MODEL_LOADING`, `LORA_LOADING` and
     * `RESOURCE_EXHAUSTED`.
     */
    private static function isCapabilityMismatch(RequestException|ServerException $exception): bool
    {
        return match ($exception->errorCode) {
            'INVALID_INPUT' => str_contains($exception->getMessage(), 'does not support'),
            'QUEUE_UNAVAILABLE' => str_starts_with($exception->getMessage(), 'missing rate for model='),
            default => false,
        };
    }

    /**
     * Resolve inputs to their wire shape, reading files and enforcing the
     * configured byte ceiling before anything is sent.
     *
     * @param  Input|File|SplFileInfo|string|iterable<mixed>  $inputs
     * @return list<array<string, mixed>>
     */
    private function wireItems(Input|File|SplFileInfo|string|iterable $inputs, int $additionalBytes = 0): array
    {
        $list = Input::listFrom($inputs);

        $bytes = array_sum(array_map(static fn (Input $input): int => $input->bytes(), $list)) + $additionalBytes;
        $limit = $this->config('max_request_bytes', self::DEFAULT_MAX_REQUEST_BYTES);

        if ($bytes > $limit) {
            throw new RequestTooLargeException($bytes, $limit);
        }

        return array_map(static fn (Input $input): array => $input->toArray(), $list);
    }

    /**
     * Fold **Pool** and **GPU type** back into the single slash-separated
     * string the client takes. They are separate concepts in this API on
     * purpose — the combined form is a wire detail.
     */
    private function routing(): ?string
    {
        return match (true) {
            $this->pool !== null && $this->gpu !== null => "{$this->pool}/{$this->gpu}",
            $this->pool !== null => "{$this->pool}/",
            default => $this->gpu,
        };
    }

    private function client(): SieClient
    {
        return Container::getInstance()->make(SieManager::class)->connection($this->connection)->client();
    }

    private function config(string $key, mixed $default): mixed
    {
        /** @var array<string, mixed> $config */
        $config = Container::getInstance()->make('config')->get('superlinked-sie-laravel', []);

        return $config[$key] ?? $default;
    }
}
