<?php

declare(strict_types=1);

use Sie\Client\Data\ExtractItemError;

it('carries a well-formed server error through unchanged', function () {
    $error = ExtractItemError::fromWire(['code' => 'INPUT_TOO_LONG', 'message' => 'Input exceeds capacity']);

    expect($error->code)->toBe('INPUT_TOO_LONG')
        ->and($error->message)->toBe('Input exceeds capacity');
});

it('substitutes the malformed fallback rather than dropping the failure', function (mixed $wire) {
    // The item still failed — a malformed envelope must never downgrade to
    // "no error", which would read as a successful empty extraction.
    $error = ExtractItemError::fromWire($wire);

    expect($error->code)->toBe(ExtractItemError::MALFORMED_CODE)
        ->and($error->message)->toBe(ExtractItemError::MALFORMED_MESSAGE);
})->with([
    'missing message' => [['code' => 'X']],
    'missing code' => [['message' => 'boom']],
    'blank code' => [['code' => '   ', 'message' => 'boom']],
    'blank message' => [['code' => 'X', 'message' => '  ']],
    'empty code' => [['code' => '', 'message' => 'boom']],
    'non-string code' => [['code' => 500, 'message' => 'boom']],
    'non-string message' => [['code' => 'X', 'message' => ['nested']]],
    'empty array' => [[]],
    'scalar instead of object' => ['just a string'],
    'integer' => [42],
]);
