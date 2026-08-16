# Documents and images are buffered in memory, never streamed

The package accepts files from any Laravel filesystem disk, which normally implies streaming
support via `readStream()` — but SIE's encode/score/extract endpoints take a single JSON body in
which every image and document is a base64 `data` field, so the whole payload must exist before
the first byte is sent. Streaming would buy nothing and cost a great deal of complexity, so we
read files with `Storage::get()` and hold them in memory for the life of the request.

## Consequences

Peak memory is roughly 1.37× the total bytes of a batch (base64 overhead), held all at once,
plus a transient copy of each file as it is read. A configurable `max_request_bytes` throws
before a request is built rather than letting PHP hit its memory limit mid-encode.

We deliberately do **not** auto-chunk an oversized batch into several requests: that would
silently turn one call into many and break the guarantee that a **Result** collection contains
exactly one **Result** per **Input**, in order. Splitting a batch is the caller's decision.
