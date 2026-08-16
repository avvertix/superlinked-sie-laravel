<?php

declare(strict_types=1);

namespace Sie\Client\Exceptions;

use Sie\Client\Data\RequestMetadata;

/**
 * Error when the request input exceeds the model's maximum token capacity.
 *
 * Raised when the server returns HTTP `400 INPUT_TOO_LONG` for an extraction
 * request. Distinct from a generic {@see RequestException} so callers can
 * branch on token-budget failures specifically (e.g. truncate the input
 * client-side, switch to a longer-context model) without parsing the error
 * code.
 */
class InputTooLongException extends RequestException
{
    public function __construct(
        string $message,
        public readonly ?string $model = null,
        ?RequestMetadata $request = null,
    ) {
        parent::__construct($message, errorCode: 'INPUT_TOO_LONG', statusCode: 400, request: $request);
    }
}
