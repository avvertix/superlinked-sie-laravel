<?php

declare(strict_types=1);

use Sie\Client\Data\ExtractResult;

/*
 * Kept as the original smoke test, now reading credentials from the environment
 * (`.env` or real env vars) instead of hardcoding them — see PORTING-PLAN.md
 * Step 0. The broader live suite lives in tests/Integration/.
 */

it('extracts entities against the live instance', function () {
    requiresBillableCalls();

    $result = sieClient()->extract(
        'urchade/gliner_multi-v2.1',
        ['text' => 'Paris is the capital of France.'],
        ['person', 'organization', 'country', 'city'],
    );

    expect($result)->toBeInstanceOf(ExtractResult::class)
        ->and($result->entities)->not->toBeEmpty();
})->group('billable');
