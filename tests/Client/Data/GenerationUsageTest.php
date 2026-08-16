<?php

declare(strict_types=1);

use Sie\Client\Data\GenerationUsage;

it('parses token counts', function () {
    $usage = GenerationUsage::fromArray([
        'prompt_tokens' => 5,
        'completion_tokens' => 2,
        'total_tokens' => 7,
    ]);

    expect($usage->promptTokens)->toBe(5)
        ->and($usage->completionTokens)->toBe(2)
        ->and($usage->totalTokens)->toBe(7);
});

it('surfaces a settled charge when both the credits and the book version are present', function () {
    $usage = GenerationUsage::fromArray([
        'prompt_tokens' => 1,
        'completion_tokens' => 1,
        'total_tokens' => 2,
        'credits_charged' => 12,
        'rate_book_version' => '2026-07-26-production-bootstrap-v2',
    ]);

    expect($usage->creditsCharged)->toBe(12)
        ->and($usage->rateBookVersion)->toBe('2026-07-26-production-bootstrap-v2');
});

it('treats an explicit zero charge as a real settlement, not an absence', function () {
    $usage = GenerationUsage::fromArray([
        'credits_charged' => 0,
        'rate_book_version' => 'v1',
    ]);

    expect($usage->creditsCharged)->toBe(0)
        ->and($usage->rateBookVersion)->toBe('v1');
});

it('drops the charge entirely unless both halves are well-formed', function (array $usage) {
    // Both-or-neither: a charge with no book version cannot be reconciled, and
    // a version with no charge describes nothing.
    $parsed = GenerationUsage::fromArray($usage);

    expect($parsed->creditsCharged)->toBeNull()
        ->and($parsed->rateBookVersion)->toBeNull();
})->with([
    'charge without version' => [['credits_charged' => 5]],
    'version without charge' => [['rate_book_version' => 'v1']],
    'empty version' => [['credits_charged' => 5, 'rate_book_version' => '']],
    'non-string version' => [['credits_charged' => 5, 'rate_book_version' => 9]],
    'negative charge' => [['credits_charged' => -1, 'rate_book_version' => 'v1']],
    'float charge' => [['credits_charged' => 1.5, 'rate_book_version' => 'v1']],
    'string charge' => [['credits_charged' => '5', 'rate_book_version' => 'v1']],
    'boolean charge' => [['credits_charged' => true, 'rate_book_version' => 'v1']],
    'neither' => [[]],
]);
