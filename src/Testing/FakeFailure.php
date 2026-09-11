<?php

declare(strict_types=1);

namespace Sie\Testing;

use Saloon\Http\Faking\MockResponse;
use Sie\Client\Support\ErrorCodes;

/**
 * A failure a **Fake** answers with, as the cluster would send it.
 *
 * Answered at the wire — a status and an error envelope — rather than by
 * throwing a typed exception directly, so `ErrorParser`, the retry ladder in
 * `RetryingRequestSender` and the capability mapping in `PendingRequest` all
 * run. A test that fakes a `503 PROVISIONING` is then testing the package's own
 * error path instead of asserting that the double was configured (ADR 0007).
 */
final class FakeFailure
{
    /**
     * @param  ?int  $times  How many requests this failure answers; null for every one.
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?int $times = null,
    ) {}

    public function toResponse(): MockResponse
    {
        $detail = ['message' => $this->message ?? sprintf('Faked %s failure', $this->code ?? "HTTP {$this->status}")];

        if ($this->code !== null) {
            $detail = ['code' => $this->code, ...$detail];
        }

        return MockResponse::make(
            ['detail' => $detail],
            $this->status,
            $this->code !== null ? [ErrorCodes::ERROR_CODE_HEADER => $this->code] : [],
        );
    }
}
