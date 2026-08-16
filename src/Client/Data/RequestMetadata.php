<?php

declare(strict_types=1);

namespace Sie\Client\Data;

/**
 * Gateway metadata from a terminal response.
 *
 * For batch encode/extract calls every item repeats this request-scoped
 * metadata: the values describe the whole HTTP request, not one item.
 *
 * Currently carries only `$id`. The Python SDK's `RequestMetadata` also models
 * metered `usage`, `credits_debited`, `rate_book_version` and
 * `execution_identity_sha256`, but those ride the billing path and no
 * deployment we can test against emits them — see PORTING-PLAN.md Step 5. They
 * are additive when a metered gateway is available; nothing here has to change
 * to accommodate them.
 */
final class RequestMetadata
{
    /**
     * @param  ?string  $id  Server-assigned request id — the value to quote in a support ticket.
     */
    public function __construct(
        public readonly ?string $id = null,
    ) {}

    /** Whether any field carried a value — nothing valid means no metadata is attached at all. */
    public function isEmpty(): bool
    {
        return $this->id === null;
    }
}
