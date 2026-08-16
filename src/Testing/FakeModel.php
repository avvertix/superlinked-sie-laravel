<?php

declare(strict_types=1);

namespace Sie\Testing;

/**
 * How a faked model should answer.
 *
 * A fake has to choose a vector width from somewhere, and reading it from the
 * live cluster would defeat the point of faking. Callers state it instead.
 */
final class FakeModel
{
    /**
     * @param  list<array<string, mixed>>  $entities
     * @param  array<string, float>  $scores  Item id => score.
     */
    private function __construct(
        public readonly string $capability,
        public readonly int $dimensions = 8,
        public readonly array $entities = [],
        public readonly string $text = '',
        public readonly array $scores = [],
    ) {}

    /** Encodes to a dense vector of `$dimensions` floats. */
    public static function dense(int $dimensions): self
    {
        return new self('encode', dimensions: $dimensions);
    }

    /**
     * Extracts the given entities for every input.
     *
     * @param  list<array<string, mixed>>  $entities
     */
    public static function entities(array $entities): self
    {
        return new self('extract', entities: $entities);
    }

    /** Generates the given text. */
    public static function text(string $text): self
    {
        return new self('generate', text: $text);
    }

    /**
     * Scores inputs with the given item id => score map.
     *
     * @param  array<string, float>  $scores
     */
    public static function scores(array $scores): self
    {
        return new self('score', scores: $scores);
    }

    /**
     * The outputs a model of this shape would declare, so a faked catalog looks
     * like a real one.
     *
     * @return list<string>
     */
    public function outputs(): array
    {
        return match ($this->capability) {
            'extract' => ['json'],
            'generate' => ['text'],
            'score' => ['score'],
            default => ['dense'],
        };
    }
}
