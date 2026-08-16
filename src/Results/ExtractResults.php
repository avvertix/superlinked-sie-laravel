<?php

declare(strict_types=1);

namespace Sie\Results;

use Illuminate\Support\Collection;
use Sie\Client\Data\ExtractResult;
use Sie\Exceptions\ExtractionFailedException;

/**
 * The results of an extraction, one per input, in the order they were sent.
 *
 * Extract batches are mixed-success: a failed input stays in this collection
 * carrying its error rather than aborting the batch (see ADR 0004). That makes
 * an empty result ambiguous — it means "nothing found" only once
 * {@see hasFailures()} has been ruled out. Callers that would rather fail
 * loudly should call {@see throwIfAnyFailed()}.
 *
 * @extends Collection<int, ExtractResult>
 */
final class ExtractResults extends Collection
{
    public function failed(): self
    {
        return $this->filter(static fn (ExtractResult $result): bool => $result->error !== null)->values();
    }

    public function succeeded(): self
    {
        return $this->filter(static fn (ExtractResult $result): bool => $result->error === null)->values();
    }

    public function hasFailures(): bool
    {
        return $this->contains(static fn (ExtractResult $result): bool => $result->error !== null);
    }

    /**
     * Throw if any input failed, otherwise return this collection unchanged so
     * the call can sit inline in a chain.
     */
    public function throwIfAnyFailed(): self
    {
        $failed = $this->failed();

        if ($failed->isEmpty()) {
            return $this;
        }

        $reasons = [];

        foreach ($failed as $result) {
            $error = $result->error;

            if ($error !== null) {
                $reasons[] = sprintf('[%s] %s', $error->code, $error->message);
            }
        }

        throw new ExtractionFailedException(
            sprintf(
                '%d of %d inputs failed to extract: %s',
                $failed->count(),
                $this->count(),
                implode('; ', array_unique($reasons)),
            ),
            $failed,
        );
    }
}
