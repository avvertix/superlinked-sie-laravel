<?php

declare(strict_types=1);

use Sie\Client\Support\Scoring;

it('sums per-query-token max similarity against a matching document', function () {
    $query = [[1.0, 0.0], [0.0, 1.0]];
    $document = [[1.0, 0.0], [0.0, 1.0]];

    expect(Scoring::maxsim($query, [$document]))->toBe([2.0]);
});

it('scores an orthogonal (zero-similarity) document as zero', function () {
    $query = [[1.0, 0.0], [0.0, 1.0]];
    $zeroDocument = [[0.0, 0.0]];

    expect(Scoring::maxsim($query, [$zeroDocument]))->toBe([0.0]);
});

it('scores multiple documents in one call, preserving order', function () {
    $query = [[1.0, 0.0], [0.0, 1.0]];
    $matching = [[1.0, 0.0], [0.0, 1.0]];
    $zero = [[0.0, 0.0]];

    expect(Scoring::maxsim($query, [$matching, $zero]))->toBe([2.0, 0.0]);
});

it('picks the single best-matching document token per query token', function () {
    // Query token [1,0] best-matches [1,0] (score 1) over [0.5,0] (score 0.5).
    $query = [[1.0, 0.0]];
    $document = [[0.5, 0.0], [1.0, 0.0]];

    expect(Scoring::maxsim($query, [$document]))->toBe([1.0]);
});

it('scores a batch of queries against a shared set of documents', function () {
    $queryA = [[1.0, 0.0]];
    $queryB = [[0.0, 1.0]];
    $document = [[1.0, 0.0], [0.0, 1.0]];

    expect(Scoring::maxsimBatch([$queryA, $queryB], [$document]))->toBe([[1.0], [1.0]]);
});
