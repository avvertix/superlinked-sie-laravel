# Documents and images are buffered in memory, never streamed

The package accepts files from any Laravel filesystem disk, which normally
implies streaming support via `readStream()` — but SIE takes a single serialized
body per request in which every image and document is one field, so the whole
payload must exist before the first byte is sent. Streaming would buy nothing
and cost a great deal of complexity, so we read files with `Storage::get()` and
hold them in memory for the life of the request.

## Consequences

Peak memory is the total bytes of a batch held at once, plus a transient copy of
each file as it is read. On the JSON path add roughly 37% on top, because base64
inflates every file; msgpack sends the same bytes raw and pays no such tax (see
ADR 0006). A configurable `max_request_bytes` throws before a request is built
rather than letting PHP hit its memory limit mid-encode.

The ceiling counts **raw** bytes rather than encoded ones. That makes it
slightly conservative on the JSON path, which is the right way round: a limit
that moves when a configuration flag flips would be worse than one that is
stable and a little cautious.

We deliberately do **not** auto-chunk an oversized batch into several requests:
that would silently turn one call into many and break the guarantee that a
**Result** collection contains exactly one **Result** per **Input**, in order.
Splitting a batch is the caller's decision.
