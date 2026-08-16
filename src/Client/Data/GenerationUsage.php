<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * Token usage block. Shared shape for both the SIE-native `generate` and OpenAI-compatible `chat` surfaces.
 *
 * `$creditsCharged`/`$rateBookVersion` ride the same block on a settled
 * response — including the terminal chunk of a stream, which is the only place
 * a streamed request can report what it cost. Absence means this block did not
 * carry the charge, **not** that nothing was charged; an explicit `0` is a
 * settlement that cost nothing. Nothing here is ever an estimate.
 */
final class GenerationUsage
{
    public function __construct(
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $totalTokens,
        public readonly ?int $creditsCharged = null,
        public readonly ?string $rateBookVersion = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        [$creditsCharged, $rateBookVersion] = self::settledCharge($data);

        return new self(
            promptTokens: self::coerceCount($data['prompt_tokens'] ?? null),
            completionTokens: self::coerceCount($data['completion_tokens'] ?? null),
            totalTokens: self::coerceCount($data['total_tokens'] ?? null),
            creditsCharged: $creditsCharged,
            rateBookVersion: $rateBookVersion,
        );
    }

    /**
     * The settled charge carried by one usage block, or `[null, null]`.
     *
     * Both-or-neither by design: a charge with no book version cannot be
     * reconciled, and a version with no charge describes nothing. Anything
     * malformed is treated as absent rather than partially surfaced. Port of
     * `settled_charge_from_usage`.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?int, 1: ?string}
     */
    private static function settledCharge(array $data): array
    {
        $credits = $data['credits_charged'] ?? null;
        $version = $data['rate_book_version'] ?? null;

        // is_int() excludes bool in PHP, mirroring Python's explicit bool guard
        // (where `True` would otherwise pass an isinstance(int) check).
        if (! is_int($credits) || $credits < 0) {
            return [null, null];
        }

        if (! is_string($version) || $version === '') {
            return [null, null];
        }

        return [$credits, $version];
    }

    /**
     * Best-effort coerce a usage token count to an int, mirroring
     * `_coerce_token_count`'s tolerance of malformed optional usage fields.
     * A non-numeric or non-finite value degrades to `0` rather than raising;
     * a numeric value is cast as-is (including negative, matching the
     * Python implementation, which does not clamp despite its docstring).
     */
    private static function coerceCount(mixed $value): int
    {
        if ((is_int($value) || is_float($value)) && is_finite($value)) {
            return (int) $value;
        }

        return 0;
    }
}
