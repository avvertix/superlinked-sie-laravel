# msgpack is the default wire format

SIE prefers msgpack: an inference POST with no `Accept` header answers in
msgpack, and JSON is what it falls back to when asked. The client's original
port negotiated JSON on every request, which was a porting simplification rather
than a reading of the protocol — so the default is now msgpack, selectable per
**Connection** with `'format' => 'json'`.

Measured against a live cluster, the same 1024-dimension encode is **4,313 bytes
over msgpack and 16,569 over JSON**, because vectors travel as raw
little-endian buffers instead of decimal text and files travel as raw bytes
instead of base64.

## Consequences

**Binary fields are encoded differently per format, and the server rejects the
wrong one.** msgspec wants a native msgpack `bin` and answers a base64 string
with ``400 Expected `bytes`, got `str` ``; on the JSON path it wants exactly the
base64 string. Sniffing cannot decide it either — a markdown or CSV document is
valid UTF-8, so a "does this look like text?" check would pack it as a string
and the request would fail. Input therefore marks bytes with
{@see \Sie\Client\Support\Binary} and the body repository, which is the only
thing that knows the format, encodes them.

**Encode responses carry numpy arrays, not numbers.** msgpack-numpy writes an
`ndarray` as an ordinary map — `{nd, type, kind, shape, data}` — over a raw
buffer, including `<f2` half-floats that PHP's `unpack()` cannot read.
`Sie\Client\Support\Ndarray` decodes them at the response boundary so that every
`Data` class keeps working unchanged and cannot tell which transport was used.

**A msgpack request does not imply a msgpack response.** Errors are JSON on
every path, and so is every GET. The `Content-Type` is the only reliable signal,
and guessing is silent rather than loud: `{` is a valid msgpack positive fixint,
so unpacking a JSON error body yields the integer `123` instead of throwing.
Nothing sniffs bytes; an unrecognised or missing content type means JSON.

**Only encode, score and extract negotiate.** `generate` has no msgpack support
server-side at all, `/v1/models` and health answer JSON regardless, and
streaming is JSON frames over SSE.

## Considered options

Keeping JSON as the default was rejected: it costs roughly 4× the bytes on the
hot path for no benefit beyond readability, which `'format' => 'json'` still
buys when it is wanted.

A per-request format override was rejected as well. The format is a property of
how an application talks to a cluster, not of one call, and a per-request switch
would multiply the encode/decode paths under test for a knob nothing turns
mid-loop.
