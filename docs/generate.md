# Generate

Generation produces text from a prompt.

```php
use Sie\Facades\SIE;

SIE::model('some-llm')->maxNewTokens(256)->temperature(0.2)->generate('Summarise: …')->text;

// Streaming returns a single-pass LazyCollection of chunks.
SIE::model('some-llm')->stream('Summarise: …')->each(function ($chunk) {
    echo $chunk->textDelta;
});
```

Generate always talks JSON, and streaming uses JSON frames over SSE. The connection's `format` setting does not change that. See [Configuration](configuration.md#wire-format).

The package does not model OpenAI-compatible chat completions. To call them, use the underlying client described in [Advanced](advanced.md).
