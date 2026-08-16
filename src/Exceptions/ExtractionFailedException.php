<?php

declare(strict_types=1);

namespace Sie\Exceptions;

use Sie\Client\Exceptions\SieException;
use Sie\Results\ExtractResults;

/**
 * Raised by {@see ExtractResults::throwIfAnyFailed()} when a batch contained a
 * partial failure. Never raised automatically — see ADR 0004.
 */
final class ExtractionFailedException extends SieException
{
    public function __construct(
        string $message,
        public readonly ExtractResults $results,
    ) {
        parent::__construct($message);
    }
}
