<?php

declare(strict_types=1);

use Sie\Client\Data\ExtractResult;
use Sie\Client\Data\ModelInfo;

/*
 * Kept as the original smoke test, now reading credentials from the environment
 * (`.env` or real env vars) instead of hardcoding them — see PORTING-PLAN.md
 * Step 0. The broader live suite lives in tests/Integration/.
 */

it('extracts entities against the live instance', function () {
    $result = sieClient()->extract(
        'urchade/gliner_multi-v2.1',
        ['text' => 'Paris is the capital of France.'],
        ['person', 'organization', 'country', 'city'],
    );

    expect($result)->toBeInstanceOf(ExtractResult::class)
        ->and($result->entities)->not->toBeEmpty();
});

it('extracts entities from a document input', function () {
    $client = sieClient();

    $model = firstModelMatching(
        $client,
        ['docling:ocr', 'docling'],
        static fn (ModelInfo $m): bool => in_array('document', $m->inputs ?? [], true),
    );

    $pdf = new SplFileInfo(__DIR__.'/../Fixtures/sample.pdf');

    $result = $client->extract($model, ['document' => $pdf]);

    // The assertion that matters is that this did not 400 — the extracted
    // content itself depends on which OCR recogniser the instance serves.
    expect($result->entities)->toBeArray();
});
