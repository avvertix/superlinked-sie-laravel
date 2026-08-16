<?php

declare(strict_types=1);

namespace Sie\Client;

use Generator;
use InvalidArgumentException;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Data\CapacityInfo;
use Sie\Client\Data\ChatCompletion;
use Sie\Client\Data\ChatCompletionChunk;
use Sie\Client\Data\EncodeResult;
use Sie\Client\Data\ExtractResult;
use Sie\Client\Data\GenerateChunk;
use Sie\Client\Data\GenerateResult;
use Sie\Client\Data\ModelInfo;
use Sie\Client\Data\PoolInfo;
use Sie\Client\Data\ScoreResult;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\SieConnectionException;
use Sie\Client\Resources\CapacityResource;
use Sie\Client\Resources\ChatResource;
use Sie\Client\Resources\EncodeResource;
use Sie\Client\Resources\ExtractResource;
use Sie\Client\Resources\GenerateResource;
use Sie\Client\Resources\ModelsResource;
use Sie\Client\Resources\PoolsResource;
use Sie\Client\Resources\ScoreResource;
use Sie\Client\Support\ChatBodyBuilder;
use Sie\Client\Support\Clock;
use Sie\Client\Support\ErrorCodes;
use Sie\Client\Support\GpuParam;
use Sie\Client\Support\ItemWireConverter;
use Sie\Client\Support\RetryingRequestSender;
use Sie\Client\Support\SimpleRequestSender;
use Sie\Client\Support\Sleeper;
use Sie\Client\Support\SystemClock;
use Sie\Client\Support\SystemSleeper;

/**
 * Client for the Search Inference Engine.
 *
 * Unlike the Python SDK, this client always negotiates `application/json`
 * (not msgpack) — see the project decision recorded alongside this port.
 *
 * Example:
 *     $client = new SieClient('http://localhost:8080');
 *     $result = $client->encode('bge-m3', ['text' => 'Hello world']);
 *     $result->dense; // list<float>
 */
final class SieClient
{
    private readonly SieConnector $connector;

    private readonly RetryingRequestSender $sender;

    private readonly EncodeResource $encodeResource;

    private readonly ScoreResource $scoreResource;

    private readonly ExtractResource $extractResource;

    private readonly GenerateResource $generateResource;

    private readonly ChatResource $chatResource;

    private readonly ModelsResource $modelsResource;

    private readonly CapacityResource $capacityResource;

    private readonly PoolsResource $poolsResource;

    private readonly Clock $clock;

    private readonly Sleeper $sleeper;

    /**
     * @param  ?array<string, mixed>  $options  Default options dict, merged with per-call options (per-call wins).
     * @param  Clock  $clock  Injectable so retry/backoff tests can fake elapsed time instead of waiting in real time.
     * @param  Sleeper  $sleeper  Injectable so retry/backoff tests don't have to sleep in real time.
     */
    public function __construct(
        string $baseUrl,
        float $timeoutS = 30.0,
        ?string $apiKey = null,
        private readonly ?string $gpu = null,
        private readonly ?array $options = null,
        Clock $clock = new SystemClock,
        Sleeper $sleeper = new SystemSleeper,
    ) {
        $this->clock = $clock;
        $this->sleeper = $sleeper;
        $this->connector = new SieConnector($baseUrl, $timeoutS, $apiKey);
        $this->sender = new RetryingRequestSender($this->connector, $clock, $sleeper);
        $simpleSender = new SimpleRequestSender($this->connector);
        $this->encodeResource = new EncodeResource($this->sender);
        $this->scoreResource = new ScoreResource($this->sender);
        $this->extractResource = new ExtractResource($this->sender);
        $this->generateResource = new GenerateResource($this->sender);
        $this->chatResource = new ChatResource($this->sender);
        $this->modelsResource = new ModelsResource($simpleSender);
        $this->capacityResource = new CapacityResource($simpleSender);
        $this->poolsResource = new PoolsResource($this->connector);
    }

    public function baseUrl(): string
    {
        return $this->connector->resolveBaseUrl();
    }

    /**
     * Encode one item (or a list of items) into vector representations.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $items  A single item (associative array, e.g.
     *                                                                  `['text' => '...']`) or a list of items.
     * @param  ?list<string>  $outputTypes  Which outputs to return: "dense" | "sparse" | "multivector". Default: ["dense"].
     * @param  ?array<string, mixed>  $options  Runtime options dict, merged with the client's default options.
     * @return EncodeResult|list<EncodeResult> An `EncodeResult` if a single item was passed, a list if a list was passed.
     */
    public function encode(
        string $model,
        array $items,
        ?array $outputTypes = null,
        ?string $instruction = null,
        ?string $outputDtype = null,
        ?bool $isQuery = null,
        ?array $options = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): EncodeResult|array {
        $singleItem = ! array_is_list($items);
        $itemsList = $singleItem ? [$items] : $items;
        $itemsForWire = ItemWireConverter::convertAll($itemsList);

        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);
        $resolvedOptions = $this->resolveOptions($options);

        if ($isQuery !== null) {
            $resolvedOptions = [...($resolvedOptions ?? []), 'is_query' => $isQuery];
        }

        $params = array_filter([
            'output_types' => $outputTypes,
            'instruction' => $instruction,
            'output_dtype' => $outputDtype,
            'options' => $resolvedOptions,
        ], static fn (mixed $value): bool => $value !== null);

        $payload = ['items' => $itemsForWire];

        if ($params !== []) {
            $payload['params'] = $params;
        }

        $results = $this->encodeResource->encode(
            $model,
            $payload,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );

        return $singleItem ? $results[0] : $results;
    }

    /**
     * Score items against a query (reranking).
     *
     * @param  array<string, mixed>  $query
     * @param  list<array<string, mixed>>  $items
     * @param  ?array<string, mixed>  $options
     */
    public function score(
        string $model,
        array $query,
        array $items,
        ?string $instruction = null,
        ?array $options = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): ScoreResult {
        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);
        $resolvedOptions = $this->resolveOptions($options);

        $payload = array_filter([
            'query' => ItemWireConverter::convert($query),
            'items' => ItemWireConverter::convertAll($items),
            'instruction' => $instruction,
            'options' => $resolvedOptions,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->scoreResource->score(
            $model,
            $payload,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );
    }

    /**
     * Extract entities/relations/classifications from one item (or a list of items).
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $items
     * @param  ?list<string>  $labels
     * @param  ?array<string, mixed>  $outputSchema
     * @param  ?array<string, mixed>  $options
     * @return ExtractResult|list<ExtractResult>
     */
    public function extract(
        string $model,
        array $items,
        ?array $labels = null,
        ?array $outputSchema = null,
        ?string $instruction = null,
        ?array $options = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): ExtractResult|array {
        $singleItem = ! array_is_list($items);
        $itemsList = $singleItem ? [$items] : $items;
        $itemsForWire = ItemWireConverter::convertAll($itemsList);

        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);
        $resolvedOptions = $this->resolveOptions($options);

        $params = array_filter([
            'labels' => $labels,
            'output_schema' => $outputSchema,
            'instruction' => $instruction,
            'options' => $resolvedOptions,
        ], static fn (mixed $value): bool => $value !== null);

        $payload = ['items' => $itemsForWire];

        if ($params !== []) {
            $payload['params'] = $params;
        }

        $results = $this->extractResource->extract(
            $model,
            $payload,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );

        return $singleItem ? $results[0] : $results;
    }

    /**
     * SIE-native blocking text generation.
     *
     * @param  ?float  $temperature  Sampling temperature override. Omit to use the selected model profile's default.
     * @param  ?float  $topP  Nucleus sampling cutoff override. Omit to use the selected model profile's default.
     * @param  string|list<string>|null  $stop
     */
    public function generate(
        string $model,
        string $prompt,
        int $maxNewTokens,
        ?float $temperature = null,
        ?float $topP = null,
        string|array|null $stop = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): GenerateResult {
        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);

        $payload = array_filter([
            'prompt' => $prompt,
            'max_new_tokens' => $maxNewTokens,
            'temperature' => $temperature,
            'top_p' => $topP,
            'stop' => $stop,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->generateResource->generate(
            $model,
            $payload,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );
    }

    /**
     * SIE-native streaming text generation.
     *
     * @param  ?float  $temperature  Sampling temperature override. Omit to use the selected model profile's default.
     * @param  ?float  $topP  Nucleus sampling cutoff override. Omit to use the selected model profile's default.
     * @param  string|list<string>|null  $stop
     * @param  ?array<string, mixed>  $extraBody  Merged last, so it can supply any forward-compat field.
     * @return Generator<int, GenerateChunk>
     */
    public function streamGenerate(
        string $model,
        string $prompt,
        int $maxNewTokens,
        ?float $temperature = null,
        ?float $topP = null,
        string|array|null $stop = null,
        ?array $extraBody = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): Generator {
        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);

        $payload = [
            'prompt' => $prompt,
            'max_new_tokens' => $maxNewTokens,
            'stream' => true,
        ];

        // Only send what the caller set, so the gateway applies the selected
        // model profile's own sampling defaults for the rest.
        foreach (['temperature' => $temperature, 'top_p' => $topP] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        if ($stop !== null) {
            $payload['stop'] = $stop;
        }

        if ($extraBody !== null) {
            $payload = [...$payload, ...$extraBody];
        }

        yield from $this->generateResource->streamGenerate(
            $model,
            $payload,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );
    }

    /**
     * OpenAI-compatible chat completion (non-streaming).
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  string|list<string>|null  $stop
     * @param  ?list<array<string, mixed>>  $tools
     * @param  ?array<string, mixed>  $responseFormat
     * @param  ?array<string, float>  $logitBias
     * @param  ?array<string, mixed>  $extraBody  Merged last, so it can supply any forward-compat field.
     */
    public function chatCompletions(
        string $model,
        array $messages,
        ?int $maxCompletionTokens = null,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?float $topP = null,
        ?int $topK = null,
        ?float $repetitionPenalty = null,
        string|array|null $stop = null,
        ?array $tools = null,
        mixed $toolChoice = null,
        ?bool $parallelToolCalls = null,
        ?array $responseFormat = null,
        ?float $frequencyPenalty = null,
        ?float $presencePenalty = null,
        ?int $n = null,
        ?int $bestOf = null,
        ?bool $logprobs = null,
        ?int $topLogprobs = null,
        ?array $logitBias = null,
        ?int $seed = null,
        ?string $user = null,
        ?string $safetyIdentifier = null,
        ?string $loraAdapter = null,
        ?array $extraBody = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): ChatCompletion {
        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);

        $payload = ChatBodyBuilder::build(
            model: $model,
            messages: $messages,
            stream: false,
            maxCompletionTokens: $maxCompletionTokens,
            maxTokens: $maxTokens,
            temperature: $temperature,
            topP: $topP,
            topK: $topK,
            repetitionPenalty: $repetitionPenalty,
            stop: $stop,
            tools: $tools,
            toolChoice: $toolChoice,
            parallelToolCalls: $parallelToolCalls,
            responseFormat: $responseFormat,
            frequencyPenalty: $frequencyPenalty,
            presencePenalty: $presencePenalty,
            n: $n,
            bestOf: $bestOf,
            logprobs: $logprobs,
            topLogprobs: $topLogprobs,
            logitBias: $logitBias,
            seed: $seed,
            user: $user,
            safetyIdentifier: $safetyIdentifier,
            loraAdapter: $loraAdapter,
            extraBody: $extraBody,
        );

        return $this->chatResource->chatCompletions(
            $payload,
            $model,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );
    }

    /**
     * OpenAI-compatible chat completion (streaming). `bestOf` is intentionally not accepted here:
     * the gateway rejects `best_of` combined with `stream: true`.
     *
     * @param  list<array<string, mixed>>  $messages
     * @param  string|list<string>|null  $stop
     * @param  ?list<array<string, mixed>>  $tools
     * @param  ?array<string, mixed>  $responseFormat
     * @param  ?array<string, float>  $logitBias
     * @param  ?array<string, mixed>  $streamOptions
     * @param  ?array<string, mixed>  $extraBody  Merged last, so it can supply any forward-compat field.
     * @return Generator<int, ChatCompletionChunk>
     */
    public function streamChatCompletions(
        string $model,
        array $messages,
        ?int $maxCompletionTokens = null,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?float $topP = null,
        ?int $topK = null,
        ?float $repetitionPenalty = null,
        string|array|null $stop = null,
        ?array $tools = null,
        mixed $toolChoice = null,
        ?bool $parallelToolCalls = null,
        ?array $responseFormat = null,
        ?float $frequencyPenalty = null,
        ?float $presencePenalty = null,
        ?int $n = null,
        ?bool $logprobs = null,
        ?int $topLogprobs = null,
        ?array $logitBias = null,
        ?int $seed = null,
        ?string $user = null,
        ?string $safetyIdentifier = null,
        ?string $loraAdapter = null,
        ?array $streamOptions = null,
        ?array $extraBody = null,
        ?string $gpu = null,
        bool $waitForCapacity = true,
        ?float $provisionTimeoutS = null,
        int $maxOomRetries = ErrorCodes::RESOURCE_EXHAUSTED_MAX_RETRIES,
    ): Generator {
        [$poolName, $gpuType] = $this->resolvePoolAndGpu($gpu);

        $payload = ChatBodyBuilder::build(
            model: $model,
            messages: $messages,
            stream: true,
            maxCompletionTokens: $maxCompletionTokens,
            maxTokens: $maxTokens,
            temperature: $temperature,
            topP: $topP,
            topK: $topK,
            repetitionPenalty: $repetitionPenalty,
            stop: $stop,
            tools: $tools,
            toolChoice: $toolChoice,
            parallelToolCalls: $parallelToolCalls,
            responseFormat: $responseFormat,
            frequencyPenalty: $frequencyPenalty,
            presencePenalty: $presencePenalty,
            n: $n,
            logprobs: $logprobs,
            topLogprobs: $topLogprobs,
            logitBias: $logitBias,
            seed: $seed,
            user: $user,
            safetyIdentifier: $safetyIdentifier,
            loraAdapter: $loraAdapter,
            streamOptions: $streamOptions,
            extraBody: $extraBody,
        );

        yield from $this->chatResource->streamChatCompletions(
            $payload,
            $model,
            $gpuType,
            $poolName,
            $waitForCapacity,
            $provisionTimeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S,
            $maxOomRetries,
        );
    }

    /**
     * List available models with their capabilities.
     *
     * @return list<ModelInfo>
     */
    public function listModels(): array
    {
        return $this->modelsResource->list();
    }

    /**
     * Get details for a specific model. Lightweight — reads from model
     * config, does not load the model or trigger inference.
     */
    public function getModel(string $model): ModelInfo
    {
        return $this->modelsResource->get($model);
    }

    /**
     * Get current cluster capacity from `/health`. Requires `$baseUrl` to
     * point at a gateway (not a bare worker).
     */
    public function getCapacity(?string $gpu = null): CapacityInfo
    {
        return $this->capacityResource->getCapacity($gpu);
    }

    /**
     * Block until capacity for `$gpu` is available (or `$timeoutS` elapses).
     *
     * When `$model` is given, this warms it up via a throwaway `encode()`
     * call and lets that call's own provisioning retry loop do the
     * waiting. Otherwise it polls `getCapacity()` directly, tolerating
     * transient connection/request errors during scale-up.
     */
    public function waitForCapacity(
        string $gpu,
        ?string $model = null,
        ?float $timeoutS = null,
        float $pollIntervalS = 5.0,
    ): CapacityInfo {
        $timeout = $timeoutS ?? ErrorCodes::DEFAULT_PROVISION_TIMEOUT_S;

        if ($model !== null) {
            $this->encode($model, ['text' => 'warmup'], gpu: $gpu, waitForCapacity: true, provisionTimeoutS: $timeout);

            return $this->getCapacity($gpu);
        }

        $start = $this->clock->now();

        while (true) {
            try {
                $capacity = $this->getCapacity($gpu);

                if (($capacity->workerCount ?? 0) > 0) {
                    return $capacity;
                }
            } catch (SieConnectionException|RequestException) {
                // Transient during scale-up (gateway not up yet, or briefly erroring) — keep polling.
            }

            $elapsed = $this->clock->now() - $start;

            if ($elapsed >= $timeout) {
                throw new ProvisioningException(
                    sprintf("Provisioning timeout after %.1fs waiting for GPU '%s'", $elapsed, $gpu),
                    gpu: $gpu,
                );
            }

            $this->sleeper->sleep(min($pollIntervalS, $timeout - $elapsed));
        }
    }

    /**
     * Create or update a named resource pool for isolated capacity.
     * Re-posting the same name updates its readiness requirements/caps and
     * renews the lease.
     *
     * @param  ?array<string, int>  $gpus  Machine profile requirements for pool readiness, e.g. `['l4' => 2]`.
     * @param  ?array<string, int>  $gpuCaps  Maximum assigned workers per machine profile.
     * @param  ?list<string>  $pinnedModels  Model ids to keep loaded on this pool's workers.
     */
    public function createPool(
        string $name,
        ?array $gpus = null,
        ?array $gpuCaps = null,
        ?string $bundle = null,
        ?int $minimumWorkerCount = null,
        ?array $pinnedModels = null,
        ?string $queuePool = null,
    ): void {
        if ($minimumWorkerCount !== null && $minimumWorkerCount < 0) {
            throw new InvalidArgumentException('minimumWorkerCount must be >= 0');
        }

        $payload = array_filter([
            'name' => $name,
            'gpus' => $gpus,
            'gpu_caps' => $gpuCaps,
            'queue_pool' => $queuePool,
            'bundle' => $bundle,
            'minimum_worker_count' => $minimumWorkerCount,
            'pinned_models' => $pinnedModels,
        ], static fn (mixed $value): bool => $value !== null);

        $this->poolsResource->create($name, $payload);
    }

    /**
     * Get information about a pool, or `null` if it doesn't exist.
     */
    public function getPool(string $name): ?PoolInfo
    {
        return $this->poolsResource->get($name);
    }

    /**
     * Delete a pool. Returns `true` if it existed and was deleted, `false`
     * if it didn't exist. Pools are also GC'd automatically after
     * inactivity — this is only needed for immediate cleanup.
     */
    public function deletePool(string $name): bool
    {
        return $this->poolsResource->delete($name);
    }

    /**
     * Renew a pool's lease. No automatic background renewal is performed
     * (see the porting decision) — call this periodically (e.g. from your
     * own cron/queue-worker loop) for the lifetime of a pool you want kept alive.
     */
    public function renewPoolLease(string $name): void
    {
        $this->poolsResource->renew($name);
    }

    private function resolveGpu(?string $gpu): ?string
    {
        return $gpu ?? $this->gpu;
    }

    /**
     * @param  ?array<string, mixed>  $options
     * @return ?array<string, mixed>
     */
    private function resolveOptions(?array $options): ?array
    {
        if ($this->options === null) {
            return $options;
        }

        if ($options === null) {
            return $this->options;
        }

        return [...$this->options, ...$options];
    }

    /**
     * @return array{0: ?string, 1: ?string} [poolName, gpuType]
     */
    private function resolvePoolAndGpu(?string $gpu): array
    {
        $resolvedGpu = $this->resolveGpu($gpu);

        if ($resolvedGpu === null) {
            return [null, null];
        }

        return GpuParam::parse($resolvedGpu);
    }
}
