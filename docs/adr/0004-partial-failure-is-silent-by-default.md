# Partial failure in a batch is silent by default

**Extract** batches are mixed-success: the cluster returns a per-**Input** error while its
siblings succeed. The package surfaces this without throwing — the failed **Result** carries its
error and stays in the collection, and callers opt in to strictness with
`ExtractResults::throwIfAnyFailed()`.

Throwing by default was rejected because one unparseable PDF in a batch of fifty would discard
forty-nine good results, which is the wrong default for a batch API where the work is already
paid for.

## Consequences

An empty **Result** means "nothing found" only once **Partial failure** has been ruled out — the
trap this default creates. It is called out in `CONTEXT.md`, in the README, and in
`ExtractResults`' own docblock, because a caller who does not know to check will silently treat
failed extractions as empty ones.
