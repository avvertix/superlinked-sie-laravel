# The laravel/ai bridge exposes dense vectors only

SIE models routinely declare several **Outputs** at once — `BAAI/bge-m3` serves `dense`,
`sparse`, `multivector`, and `score` — but `laravel/ai`'s `EmbeddingGateway::generateEmbeddings()`
takes a fixed `int $dimensions` and returns a single `EmbeddingsResponse`, with nowhere to put a
second representation. Rather than smuggle sparse and multivector output through side channels,
the bridge maps **Encode** to dense vectors only, and everything else stays on the native `SIE::`
surface where it can be typed properly.

## Consequences

`Ai::embeddings()` against the `sie` driver will never return sparse or multivector data, and
`instruction` / `is_query` / `profile` / `pool` / `gpu` reach SIE only through the untyped
`$providerOptions` array. Applications that need those should call `SIE::` directly.

`laravel/ai` stays a `require-dev` + `suggest` dependency; the driver registers itself only when
`Laravel\Ai\AiManager` is present, so the package remains usable without it.

## Considered options

Implementing `TextProvider` as well was rejected: SIE's `generate` is a raw-prompt completion
endpoint with no tool-calling or chat semantics, so most of the contract would have to throw.
