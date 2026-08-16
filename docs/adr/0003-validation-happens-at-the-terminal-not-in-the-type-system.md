# Validation happens at the terminal call and at the server, not in the type system

`SIE::model(…)` returns a single flat `PendingRequest` carrying the options for all four
**Capabilities**, rather than narrowing to a per-capability builder. This is a deliberate
deviation for a repo that otherwise runs PHPStan with 100% type coverage: option overlap between
capabilities is high (profile, pool, gpu, instruction, timeouts all span two or more), only a
handful of options are genuinely exclusive, and a flat chain reads the way Laravel developers
expect — ending on the call that does the work.

The type safety we gave up is recovered at runtime instead: the terminal verb throws
`InvalidArgumentException` on a contradictory combination, so `->labels([...])->encode()` fails
loudly rather than silently ignoring the labels.

Relatedly, we do **not** preflight a request against the model's declared **Outputs**, even
though `/v1/models` tells us that e.g. `docling` cannot **Encode**. Preflighting would make every
call two round-trips, or introduce a cache-staleness failure mode where a model the cluster
happily serves is rejected locally. The server's own error is mapped to
`UnsupportedCapabilityException` instead.

## Consequences

Static analysis cannot catch a capability/option mismatch; tests must. The model catalog is
available for inspection but is never authoritative over what a request may attempt — the cluster
is.
