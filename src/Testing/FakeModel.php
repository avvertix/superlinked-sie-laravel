<?php

declare(strict_types=1);

namespace Sie\Testing;

use Closure;

/**
 * The **Answers** a faked model gives, one per **Capability**.
 *
 * A `FakeModel` describes what a model answers, not what it is: it carries no
 * capability of its own, so one model can encode *and* score the way
 * `BAAI/bge-m3` really does. The static constructors start a chain and the
 * `and*()` methods extend it, each returning a new instance (ADR 0007).
 *
 * A fake has to choose a vector width from somewhere, and reading it from the
 * live cluster would defeat the point of faking. Callers state it instead.
 */
final class FakeModel
{
    /**
     * @param  list<array<string, mixed>>|null  $entities
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $extracting  Per-item extract answer.
     * @param  array<string, float>|null  $scores  Item id => score.
     * @param  list<string>|null  $declaredOutputs  Overrides the outputs the answers imply.
     */
    private function __construct(
        public readonly ?int $dimensions = null,
        public readonly ?array $entities = null,
        public readonly ?Closure $extracting = null,
        public readonly ?string $text = null,
        public readonly ?array $scores = null,
        public readonly ?array $declaredOutputs = null,
        public readonly ?FakeFailure $failure = null,
        public readonly ?string $recording = null,
    ) {}

    /** Encodes to a dense vector of `$dimensions` floats. */
    public static function dense(int $dimensions): self
    {
        return new self(dimensions: $dimensions);
    }

    /**
     * Extracts the given entities for every input.
     *
     * The shorthand for "same answer for everything". Reach for
     * {@see extracting()} when the answer varies per input, or when it is not
     * entities.
     *
     * @param  list<array<string, mixed>>  $entities
     */
    public static function entities(array $entities): self
    {
        return new self(entities: $entities);
    }

    /**
     * Answers each input with whatever the callback returns for it.
     *
     * The callback receives one input item in its wire shape — `['text' => …]`,
     * or `['id' => …, 'document' => …]` — and returns the members of the
     * extract item to answer with: any of `entities`, `relations`,
     * `classifications`, `objects`, `data`, or `error`. The input's `id` is
     * echoed back automatically unless the callback returns its own.
     *
     * This is how a fake reaches the parts of an extraction that
     * {@see entities()} cannot — a parsed document on `data`, a per-item
     * failure on `error`, or a batch whose answer depends on the input:
     *
     * ```php
     * FakeModel::extracting(fn (array $item) => str_contains($item['text'], '@')
     *     ? ['entities' => [['text' => $item['text'], 'label' => 'email', 'score' => 0.99, 'start' => 0, 'end' => 3]]]
     *     : ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'tokenizer failed']]);
     * ```
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $callback
     */
    public static function extracting(Closure $callback): self
    {
        return new self(extracting: $callback);
    }

    /** Generates the given text, whether it is asked through generate or chat. */
    public static function text(string $text): self
    {
        return new self(text: $text);
    }

    /**
     * Scores inputs with the given item id => score map.
     *
     * @param  array<string, float>  $scores
     */
    public static function scores(array $scores): self
    {
        return new self(scores: $scores);
    }

    /**
     * Replays a **Recording** — a real cluster response captured on the first
     * run and stored by Saloon under `tests/Fixtures/Saloon`.
     *
     * For the answer nobody wants to hand-write: a parsed document, a model
     * whose payload is an upstream schema. The first run needs a reachable
     * `SIE_ENDPOINT` and credentials, because that run is the capture; every
     * run after it replays the file. Refreshing one means deleting it.
     *
     * Record with the connection's `format` set to `json`, or the fixture
     * stores base64-encoded msgpack that no reviewer can read in a diff. The
     * faked catalog cannot infer outputs from a recording, so pair it with
     * {@see declaring()} if the test reads the catalog.
     */
    public static function recording(string $fixture): self
    {
        return new self(recording: $fixture);
    }

    public function andRecording(string $fixture): self
    {
        return $this->with(recording: $fixture);
    }

    /**
     * Fails every request for this model, as the cluster would — a status and
     * an error envelope, so the package's own error handling runs.
     */
    public static function failing(int $status, ?string $code = null, ?string $message = null): self
    {
        return new self(failure: new FakeFailure($status, $code, $message));
    }

    /**
     * Fails the next `$times` requests for this model, then answers normally.
     *
     * The shape a retry test needs: `FakeModel::dense(1024)->andFailingTimes(1,
     * 503, 'PROVISIONING')` is one provisioning wait followed by a vector.
     */
    public static function failingTimes(int $times, int $status, ?string $code = null, ?string $message = null): self
    {
        return new self(failure: new FakeFailure($status, $code, $message, $times));
    }

    public function andFailing(int $status, ?string $code = null, ?string $message = null): self
    {
        return $this->with(failure: new FakeFailure($status, $code, $message));
    }

    public function andFailingTimes(int $times, int $status, ?string $code = null, ?string $message = null): self
    {
        return $this->with(failure: new FakeFailure($status, $code, $message, $times));
    }

    /** Adds a dense **Encode** answer to a model that already answers something else. */
    public function andDense(int $dimensions): self
    {
        return $this->with(dimensions: $dimensions);
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    public function andEntities(array $entities): self
    {
        return $this->with(entities: $entities);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $callback
     */
    public function andExtracting(Closure $callback): self
    {
        return $this->with(extracting: $callback);
    }

    public function andText(string $text): self
    {
        return $this->with(text: $text);
    }

    /**
     * @param  array<string, float>  $scores
     */
    public function andScores(array $scores): self
    {
        return $this->with(scores: $scores);
    }

    /**
     * States the outputs the faked catalog reports, instead of deriving them
     * from the answers.
     *
     * For the case the derivation cannot express: a model that really declares
     * `sparse` while the fake only answers `dense`, so a test can exercise
     * "this model cannot serve what you asked" without the fake having to
     * produce the output it is refusing.
     *
     * @param  list<string>  $outputs
     */
    public function declaring(array $outputs): self
    {
        return $this->with(declaredOutputs: $outputs);
    }

    /** Whether this model was given an **Answer** for `$capability`. */
    public function answers(string $capability): bool
    {
        if ($this->recording !== null) {
            return true;
        }

        if ($this->failure !== null && $this->failure->times === null) {
            return true;
        }

        return match ($capability) {
            'encode' => $this->dimensions !== null,
            'extract' => $this->entities !== null || $this->extracting !== null,
            'score' => $this->scores !== null,
            'generate', 'chat' => $this->text !== null,
            default => false,
        };
    }

    /**
     * The outputs a model answering this way would declare, so a faked catalog
     * looks like a real one. The union of what the answers imply, unless
     * {@see declaring()} overrode it.
     *
     * @return list<string>
     */
    public function outputs(): array
    {
        if ($this->declaredOutputs !== null) {
            return $this->declaredOutputs;
        }

        $outputs = [];

        if ($this->answers('encode')) {
            $outputs[] = 'dense';
        }

        if ($this->answers('extract')) {
            $outputs[] = 'json';
        }

        if ($this->answers('score')) {
            $outputs[] = 'score';
        }

        if ($this->answers('generate')) {
            $outputs[] = 'tokens';
        }

        return $outputs === [] ? ['dense'] : $outputs;
    }

    /**
     * @param  list<array<string, mixed>>|null  $entities
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $extracting
     * @param  array<string, float>|null  $scores
     * @param  list<string>|null  $declaredOutputs
     */
    private function with(
        ?int $dimensions = null,
        ?array $entities = null,
        ?Closure $extracting = null,
        ?string $text = null,
        ?array $scores = null,
        ?array $declaredOutputs = null,
        ?FakeFailure $failure = null,
        ?string $recording = null,
    ): self {
        return new self(
            dimensions: $dimensions ?? $this->dimensions,
            entities: $entities ?? $this->entities,
            extracting: $extracting ?? $this->extracting,
            text: $text ?? $this->text,
            scores: $scores ?? $this->scores,
            declaredOutputs: $declaredOutputs ?? $this->declaredOutputs,
            failure: $failure ?? $this->failure,
            recording: $recording ?? $this->recording,
        );
    }
}
