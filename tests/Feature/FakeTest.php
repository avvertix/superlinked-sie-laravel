<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use PHPUnit\Framework\ExpectationFailedException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\RequestException;
use Sie\Exceptions\UnsupportedCapabilityException;
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

afterEach(fn () => MockClient::destroyGlobal());

it('fakes an encode without touching the network', function () {
    SIE::fake();

    $results = SIE::model('BAAI/bge-m3')->encode('Hello world');

    expect($results)->toHaveCount(1);
    expect($results->sole()->dense)->toHaveCount(8);
});

it('fakes vectors at the width the caller asked for', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    expect(SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense)->toHaveCount(1024);
});

it('returns deterministic vectors so snapshots stay stable', function () {
    SIE::fake();

    $first = SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense;
    $second = SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense;
    $other = SIE::model('BAAI/bge-m3')->encode('Goodbye world')->sole()->dense;

    expect($first)->toBe($second);
    expect($first)->not->toBe($other);
});

it('returns one fake result per input', function () {
    SIE::fake();

    expect(SIE::model('BAAI/bge-m3')->encode(['a', 'b', 'c']))->toHaveCount(3);
});

it('asserts that a model was encoded', function () {
    SIE::fake();

    SIE::model('BAAI/bge-m3')->instruction('retrieve')->encode('Hello world');

    SIE::assertEncoded('BAAI/bge-m3');
    SIE::assertEncoded('BAAI/bge-m3', fn (array $body): bool => $body['params']['instruction'] === 'retrieve');
});

it('fails the assertion when a different model was encoded', function () {
    SIE::fake();

    SIE::model('BAAI/bge-m3')->encode('Hello world');

    SIE::assertEncoded('docling');
})->throws(ExpectationFailedException::class);

it('asserts that nothing was sent', function () {
    SIE::fake();

    SIE::assertNothingSent();
});

it('fakes an extraction with the entities the caller supplied', function () {
    SIE::fake(['gliner' => FakeModel::entities([
        ['text' => 'Ada', 'label' => 'PERSON', 'score' => 0.99, 'start' => 0, 'end' => 3],
    ])]);

    $results = SIE::model('gliner')->labels(['PERSON'])->extract('Ada Lovelace');

    expect($results)->toHaveCount(1);
    expect($results->sole()->entities)->toHaveCount(1);
    expect($results->sole()->entities[0]->text)->toBe('Ada');
    SIE::assertExtracted('gliner');
});

it('rewires itself when fake() is called again in the same process', function () {
    // Saloon's global mock is a static that survives the application rebuild
    // between tests, so a second fake() used to be inert — the first one kept
    // answering and the second recorded nothing.
    SIE::fake(['gliner' => FakeModel::entities([
        ['text' => 'Ada', 'label' => 'PERSON', 'score' => 0.99, 'start' => 0, 'end' => 3],
    ])]);

    SIE::model('gliner')->labels(['PERSON'])->extract('Ada Lovelace');

    SIE::fake(['gliner' => FakeModel::entities([])]);

    $results = SIE::model('gliner')->labels(['PERSON'])->extract('nothing to see here');

    expect($results->sole()->entities)->toBeEmpty();
    SIE::assertExtracted('gliner');
    SIE::assertSentCount(1);
});

it('refuses to take over a global mock it did not install', function () {
    MockClient::global(['*' => MockResponse::make(['status' => 'ok'], 200)]);

    SIE::fake();
})->throws(RuntimeException::class, 'global Saloon MockClient is already installed');

it('fakes a parsed document on the data member', function () {
    SIE::fake(['docling' => FakeModel::extracting(fn (array $item): array => [
        'data' => ['markdown' => '# Invoice', 'text' => 'Invoice'],
    ])]);

    $parsed = SIE::model('docling')->extract('invoice.pdf')->sole();

    expect($parsed->data)->toBe(['markdown' => '# Invoice', 'text' => 'Invoice']);
    expect($parsed->error)->toBeNull();
});

it('fakes a mixed-success batch where one item failed', function () {
    SIE::fake(['gliner' => FakeModel::extracting(fn (array $item): array => $item['text'] === 'bad'
        ? ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'tokenizer failed']]
        : ['entities' => [['text' => 'Ada', 'label' => 'PERSON', 'score' => 0.97, 'start' => 0, 'end' => 3]]],
    )]);

    $results = SIE::model('gliner')->labels(['PERSON'])->extract(['Ada Lovelace', 'bad']);

    expect($results)->toHaveCount(2);
    expect($results->hasFailures())->toBeTrue();
    expect($results->failed())->toHaveCount(1);
    expect($results->failed()->sole()->error->code)->toBe('INTERNAL_ERROR');
    expect($results->succeeded()->sole()->entities)->toHaveCount(1);
});

it('lets a faked extraction answer the input it was given', function () {
    SIE::fake(['gliner' => FakeModel::extracting(fn (array $item): array => [
        'entities' => str_contains($item['text'], '@')
            ? [['text' => $item['text'], 'label' => 'EMAIL', 'score' => 0.99, 'start' => 0, 'end' => 3]]
            : [],
    ])]);

    $results = SIE::model('gliner')->labels(['EMAIL'])->extract(['ada@example.test', 'no address here']);

    expect($results->first()->entities)->toHaveCount(1);
    expect($results->first()->entities[0]->label)->toBe('EMAIL');
    expect($results->last()->entities)->toBeEmpty();
});

it('fakes a generation with the text the caller supplied', function () {
    SIE::fake(['tiny-llm' => FakeModel::text('a duck is a bird')]);

    expect(SIE::model('tiny-llm')->generate('what is a duck?')->text)->toBe('a duck is a bird');
    SIE::assertGenerated('tiny-llm');
});

it('fakes a score in descending relevance order', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['b' => 0.9, 'a' => 0.2])]);

    $results = SIE::model('BAAI/bge-m3')->score('duck?', ['a', 'b']);

    expect($results->top(2))->toBe(['b', 'a']);
    SIE::assertScored('BAAI/bge-m3');
});

it('fakes the model catalog from the configured models', function () {
    SIE::fake([
        'BAAI/bge-m3' => FakeModel::dense(1024),
        'gliner' => FakeModel::entities([]),
    ]);

    expect(SIE::models()->pluck('name')->all())->toBe(['BAAI/bge-m3', 'gliner']);
    expect(SIE::models()->firstWhere('name', 'BAAI/bge-m3')->outputs)->toBe(['dense']);
    expect(SIE::models()->firstWhere('name', 'gliner')->outputs)->toBe(['json']);
});

it('fakes a generation for a model whose id carries a slash', function () {
    // generate escapes "/" as "__" in its path; the fake has to put it back or
    // it misses the configured model and answers an empty generation.
    SIE::fake(['Qwen/Qwen3.5-4B' => FakeModel::text('a duck is a bird')]);

    expect(SIE::model('Qwen/Qwen3.5-4B')->generate('what is a duck?')->text)->toBe('a duck is a bird');
    SIE::assertGenerated('Qwen/Qwen3.5-4B');
});

it('fakes a chat completion, and tells the route apart from generate', function () {
    SIE::fake(['tiny-llm' => FakeModel::text('a duck is a bird')]);

    $completion = SIE::connection()->client()->chatCompletions('tiny-llm', [['role' => 'user', 'content' => 'what is a duck?']]);

    expect($completion->choices[0]->message->content)->toBe('a duck is a bird');
    SIE::assertChatted('tiny-llm');
    SIE::assertSentCount(1);

    expect(fn () => SIE::assertGenerated('tiny-llm'))->toThrow(ExpectationFailedException::class);
});

it('fakes a streamed generation', function () {
    SIE::fake(['tiny-llm' => FakeModel::text('a duck is a bird')]);

    $chunks = iterator_to_array(SIE::connection()->client()->streamGenerate('tiny-llm', 'what is a duck?', 64));

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]->textDelta)->toBe('a duck is a bird');
    expect($chunks[1]->done)->toBeTrue();
    expect($chunks[1]->finishReason)->toBe('stop');
    SIE::assertGenerated('tiny-llm');
});

it('fakes a streamed chat completion', function () {
    SIE::fake(['tiny-llm' => FakeModel::text('a duck is a bird')]);

    $chunks = iterator_to_array(SIE::connection()->client()->streamChatCompletions('tiny-llm', [['role' => 'user', 'content' => 'hi']]));

    expect($chunks)->toHaveCount(2);
    expect($chunks[0]->choices[0]->delta->content)->toBe('a duck is a bird');
    SIE::assertChatted('tiny-llm');
});

it('answers /health so waitForCapacity does not reach the wire', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)]);

    $capacity = SIE::capacity();

    expect($capacity->status)->toBe('healthy');
    expect($capacity->workerCount)->toBe(1);
    expect($capacity->workers)->toHaveCount(1);
});

it('refuses a route it cannot answer instead of inventing a response', function () {
    SIE::fake();

    SIE::connection()->pools()->get('eval-bench');
})->throws(RuntimeException::class, 'SIE::fake() cannot answer');
it('answers two capabilities from one model, the way bge-m3 really does', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)->andScores(['b' => 0.9, 'a' => 0.2])]);

    expect(SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense)->toHaveCount(1024);
    expect(SIE::model('BAAI/bge-m3')->score('duck?', ['a', 'b'])->top(2))->toBe(['b', 'a']);

    expect(SIE::models()->firstWhere('name', 'BAAI/bge-m3')->outputs)->toBe(['dense', 'score']);
});

it('keeps each link of the chain to itself', function () {
    $dense = FakeModel::dense(1024);
    $both = $dense->andScores(['a' => 0.5]);

    expect($dense->scores)->toBeNull();
    expect($both->dimensions)->toBe(1024);
    expect($both->scores)->toBe(['a' => 0.5]);
});

it('refuses a capability the configured model was given no answer for', function () {
    SIE::fake(['gliner' => FakeModel::entities([])]);

    SIE::model('gliner')->encode('Hello world');
})->throws(RuntimeException::class, 'SIE::fake() has no encode answer for [gliner]');

it('still answers for a model nobody configured', function () {
    SIE::fake(['gliner' => FakeModel::entities([])]);

    expect(SIE::model('some/other-model')->encode('Hello world')->sole()->dense)->toHaveCount(8);
});

it('reports the outputs a fake declares rather than the ones it answers', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)->declaring(['dense', 'sparse'])]);

    expect(SIE::models()->firstWhere('name', 'BAAI/bge-m3')->outputs)->toBe(['dense', 'sparse']);
});
it('retries a faked provisioning wait, instantly, and then succeeds', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)->andFailingTimes(1, 503, 'PROVISIONING')]);

    $results = SIE::model('BAAI/bge-m3')->encode('Hello world');

    expect($results->sole()->dense)->toHaveCount(8);
    SIE::assertSentCount(2); // the rejected attempt and the retry
    SIE::assertSlept(1);
});

it('gives up on a provisioning wait once the budget is spent', function () {
    // Proves the faked clock advances: a real one would spin here forever.
    SIE::fake(['BAAI/bge-m3' => FakeModel::failing(503, 'PROVISIONING')]);

    SIE::model('BAAI/bge-m3')->provisionTimeout(30.0)->encode('Hello world');
})->throws(ProvisioningException::class);

it('surfaces a faked client error through the package error path', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::failing(400, 'INVALID_INPUT', 'items must not be empty')]);

    SIE::model('BAAI/bge-m3')->encode('Hello world');
})->throws(RequestException::class, 'items must not be empty');

it('maps a faked capability rejection to the typed exception', function () {
    SIE::fake(['docling' => FakeModel::failing(
        503,
        'QUEUE_UNAVAILABLE',
        'missing rate for model="docling", profile="default", operation="encode", region="us"',
    )]);

    SIE::model('docling')->encode('Hello world');
})->throws(UnsupportedCapabilityException::class, "Model 'docling' cannot serve encode()");

it('fails the next request whatever model it is for', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)])->failNext(503, 'PROVISIONING');

    expect(SIE::model('BAAI/bge-m3')->encode('Hello world')->sole()->dense)->toHaveCount(8);
    SIE::assertSentCount(2);
});
it('replays a recorded cluster response so nobody has to know the payload shape', function () {
    SIE::fake(['docling' => FakeModel::recording('docling-pdf-simple')]);

    $parsed = SIE::model('docling')->extract('invoice.pdf')->sole();

    expect($parsed->data['markdown'])->toContain('This is a test PDF');
    SIE::assertExtracted('docling');
});
it('leaves a Sleep fake the application installed alone', function () {
    // Sleep::fake() resets the recorded sequence and any whenFakingSleep
    // callbacks, so faking it on the application's behalf would silently
    // discard what its own code had registered there.
    Sleep::fake();
    Sleep::for(2)->seconds();

    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)->andFailingTimes(1, 503, 'PROVISIONING')]);
    SIE::model('BAAI/bge-m3')->encode('Hello world');

    Sleep::assertSleptTimes(1);   // the application's own sleep, still recorded
    SIE::assertSlept(1);          // the retry ladder's wait, recorded by the fake
});

it('asserts that nothing waited when nothing failed', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)]);

    SIE::model('BAAI/bge-m3')->encode('Hello world');

    SIE::assertSlept(0);
});
it('counts the requests it faked', function () {
    SIE::fake();

    SIE::model('BAAI/bge-m3')->encode('a');
    SIE::model('BAAI/bge-m3')->encode('b');

    SIE::assertSentCount(2);
});
