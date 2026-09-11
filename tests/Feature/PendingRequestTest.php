<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Requests\Encode\EncodeRequest;
use Sie\Client\Requests\Extract\ExtractRequest;
use Sie\Client\Requests\Score\ScoreRequest;
use Sie\Exceptions\RequestTooLargeException;
use Sie\Exceptions\UnsupportedCapabilityException;
use Sie\Facades\SIE;
use Sie\File;
use Sie\Input;
use Sie\PendingRequest;
use Sie\Results\EncodeResults;
use Sie\Results\ExtractResults;
use Sie\Results\ScoreResults;

afterEach(fn () => MockClient::destroyGlobal());

function encodeResponse(array $items = [['dense' => ['values' => [0.1, 0.2]]]]): MockResponse
{
    return MockResponse::make(['model' => 'BAAI/bge-m3', 'items' => $items], 200);
}

it('encodes a bare string and returns a result collection', function () {
    MockClient::global([encodeResponse()]);

    $results = SIE::model('BAAI/bge-m3')->encode('Hello world');

    expect($results)->toBeInstanceOf(EncodeResults::class)->toHaveCount(1);
    expect($results->sole()->dense)->toBe([0.1, 0.2]);
});

it('always returns a collection, even for a single input', function () {
    MockClient::global([encodeResponse()]);

    expect(SIE::model('BAAI/bge-m3')->encode(Input::text('Hello world')))->toBeInstanceOf(EncodeResults::class);
});

it('encodes a batch and keeps one result per input, in order', function () {
    MockClient::global([encodeResponse([
        ['dense' => ['values' => [0.1]]],
        ['dense' => ['values' => [0.2]]],
    ])]);

    $results = SIE::model('BAAI/bge-m3')->encode(['a', 'b']);

    expect($results)->toHaveCount(2);
    expect($results->dense())->toBe([[0.1], [0.2]]);
});

it('sends the chained encode options on the wire', function () {
    $mock = MockClient::global([encodeResponse()]);

    SIE::model('BAAI/bge-m3')
        ->instruction('retrieve')
        ->outputs(['dense', 'sparse'])
        ->asQuery()
        ->pool('eval-bench')
        ->gpu('l4')
        ->encode('Hello world');

    $mock->assertSent(function (EncodeRequest $request): bool {
        $body = $request->body()->all();

        expect($request->resolveEndpoint())->toBe('/v1/encode/BAAI/bge-m3');
        expect($body['items'])->toBe([['text' => 'Hello world']]);
        expect($body['params']['instruction'])->toBe('retrieve');
        expect($body['params']['output_types'])->toBe(['dense', 'sparse']);
        expect($body['params']['options'])->toBe(['is_query' => true]);

        return true;
    });
});

it('composes a profile into the model id', function () {
    $mock = MockClient::global([encodeResponse()]);

    SIE::model('BAAI/bge-m3')->profile('multivector')->encode('Hello world');

    $mock->assertSent(fn (EncodeRequest $request): bool => $request->resolveEndpoint() === '/v1/encode/BAAI/bge-m3:multivector');
});

it('accepts a profile already embedded in the model id', function () {
    $mock = MockClient::global([MockResponse::make(['model' => 'docling', 'items' => [['data' => ['pages' => 1]]]], 200)]);

    SIE::model('docling:ocr')->extract(Input::text('x'));

    $mock->assertSent(fn (ExtractRequest $request): bool => $request->resolveEndpoint() === '/v1/extract/docling:ocr');
});

it('refuses to set a profile when the model id already names one', function () {
    SIE::model('docling:ocr')->profile('default');
})->throws(InvalidArgumentException::class, 'The model id [docling:ocr] already names a profile.');

it('requires a model before a terminal call', function () {
    (new PendingRequest)->encode('Hello world');
})->throws(InvalidArgumentException::class, 'No model was given. Call model() before encode().');

it('throws when an option belongs to a different capability', function () {
    SIE::model('BAAI/bge-m3')->labels(['PERSON'])->encode('Hello world');
})->throws(InvalidArgumentException::class, 'The [labels] option does not apply to encode().');

it('scores inputs against a query and returns ranked entries', function () {
    $mock = MockClient::global([
        MockResponse::make([
            'model' => 'BAAI/bge-m3',
            'scores' => [
                ['item_id' => 'b', 'score' => 0.9, 'rank' => 0],
                ['item_id' => 'a', 'score' => 0.1, 'rank' => 1],
            ],
        ], 200),
    ]);

    $results = SIE::model('BAAI/bge-m3')->score('what is a duck?', ['a', 'b']);

    expect($results)->toBeInstanceOf(ScoreResults::class);
    expect($results->top(1))->toBe(['b']);

    $mock->assertSent(function (ScoreRequest $request): bool {
        $body = $request->body()->all();

        expect($body['query'])->toBe(['text' => 'what is a duck?']);
        expect($body['items'])->toBe([['text' => 'a'], ['text' => 'b']]);

        return true;
    });
});

it('extracts and preserves partial failures instead of throwing', function () {
    MockClient::global([
        MockResponse::make([
            'model' => 'urchade/gliner_multi-v2.1',
            'items' => [
                ['id' => 'ok', 'entities' => [['text' => 'Ada', 'label' => 'PERSON', 'score' => 0.9, 'start' => 0, 'end' => 3]]],
                ['id' => 'bad', 'error' => ['code' => 'INVALID_INPUT', 'message' => 'Unreadable']],
            ],
        ], 200),
    ]);

    $results = SIE::model('urchade/gliner_multi-v2.1')
        ->labels(['PERSON'])
        ->extract([Input::text('Ada')->withId('ok'), Input::text('x')->withId('bad')]);

    expect($results)->toBeInstanceOf(ExtractResults::class)->toHaveCount(2);
    expect($results->hasFailures())->toBeTrue();
    expect($results->succeeded())->toHaveCount(1);
});

it('sends extraction labels on the wire', function () {
    $mock = MockClient::global([MockResponse::make(['model' => 'gliner', 'items' => [[]]], 200)]);

    SIE::model('gliner')->labels(['PERSON', 'ORG'])->extract('Ada Lovelace');

    $mock->assertSent(function (ExtractRequest $request): bool {
        expect($request->body()->all()['params']['labels'])->toBe(['PERSON', 'ORG']);

        return true;
    });
});

it('refuses a request whose inputs exceed the configured byte limit', function () {
    config()->set('superlinked-sie-laravel.max_request_bytes', 10);

    SIE::model('BAAI/bge-m3')->encode(str_repeat('x', 11));
})->throws(RequestTooLargeException::class);

it('counts file contents towards the byte limit without sending them', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('big.pdf', str_repeat('x', 5000));
    config()->set('superlinked-sie-laravel.max_request_bytes', 1000);

    SIE::model('docling')->extract(Input::document(File::disk('documents', 'big.pdf')));
})->throws(RequestTooLargeException::class);

it('survives serialization so it can be handed to a queued job', function () {
    $pending = SIE::model('BAAI/bge-m3')->instruction('retrieve')->pool('eval-bench');

    $revived = unserialize(serialize($pending));

    expect($revived)->toBeInstanceOf(PendingRequest::class);

    MockClient::global([encodeResponse()]);
    expect($revived->encode('Hello world'))->toHaveCount(1);
});

it('translates the cluster rejecting a capability into a typed exception', function () {
    MockClient::global([
        MockResponse::make([
            'error' => [
                'code' => 'INVALID_INPUT',
                'message' => "Model 'docling' does not support output types: {'dense'}. Supported: set()",
            ],
        ], 400),
    ]);

    SIE::model('docling')->encode('Hello world');
})->throws(UnsupportedCapabilityException::class, 'does not support output types');

it('translates a model with no queue for the operation into a typed exception', function () {
    MockClient::global([
        MockResponse::make([
            'detail' => [
                'code' => 'QUEUE_UNAVAILABLE',
                'message' => 'missing rate for model="docling", profile="default", operation="encode", region="us"',
            ],
        ], 503),
    ]);

    expect(fn () => SIE::model('docling')->encode('Hello world'))
        ->toThrow(function (UnsupportedCapabilityException $exception) {
            expect($exception->model)->toBe('docling');
            expect($exception->capability)->toBe('encode');
            expect($exception->getMessage())->toContain('missing rate for model="docling"');
            expect($exception->getPrevious())->toBeInstanceOf(ServerException::class);
        });
});

it('leaves a queue rejection that is not about the capability as a server error', function () {
    MockClient::global([
        MockResponse::make([
            'detail' => [
                'code' => 'QUEUE_UNAVAILABLE',
                'message' => 'page pricing requires a non-empty image or document input',
            ],
        ], 503),
    ]);

    SIE::model('docling')->extract('Hello world');
})->throws(ServerException::class, 'page pricing');

it('leaves other request errors as they are', function () {
    MockClient::global([
        MockResponse::make(['error' => ['code' => 'MODEL_NOT_FOUND', 'message' => "Model 'nope' not found"]], 404),
    ]);

    SIE::model('nope')->encode('Hello world');
})->throws(RequestException::class, 'not found');

it('accepts a file directly as an input, without wrapping it', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('invoices/march.pdf', 'PDF-BYTES');

    $mock = MockClient::global([
        MockResponse::make(['model' => 'docling', 'items' => [['data' => ['pages' => 1]]]], 200),
    ]);

    SIE::model('docling')->extract(File::disk('documents', 'invoices/march.pdf'));

    $mock->assertSent(function (ExtractRequest $request): bool {
        $item = $request->body()->all()['items'][0];

        expect($item['document']['data']->bytes)->toBe('PDF-BYTES');
        expect($item['document']['format'])->toBe('pdf');
        expect($item)->not->toHaveKey('images');

        // …and it reaches the wire as a native msgpack bin, not base64.
        expect(bin2hex((string) $request->body()))->toContain('c409'.bin2hex('PDF-BYTES'));

        return true;
    });
});

it('routes a file with an image extension into the images field', function () {
    Storage::fake('images');
    Storage::disk('images')->put('cover.jpg', 'JPG');

    $mock = MockClient::global([encodeResponse()]);

    SIE::model('openai/clip-vit-base-patch32')->encode(File::disk('images', 'cover.jpg'));

    $mock->assertSent(function (EncodeRequest $request): bool {
        $image = $request->body()->all()['items'][0]['images'][0];

        expect($image['data']->bytes)->toBe('JPG');
        expect($image['format'])->toBe('jpeg');

        return true;
    });
});

it('accepts a batch of files built with the disk shorthand', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('a.pdf', 'A');
    Storage::disk('documents')->put('b.pdf', 'B');

    $mock = MockClient::global([
        MockResponse::make(['model' => 'docling', 'items' => [['data' => []], ['data' => []]]], 200),
    ]);

    $results = SIE::model('docling')->extract(
        array_map(fn (string $p) => Input::fromDisk('documents', $p), ['a.pdf', 'b.pdf']),
    );

    expect($results)->toHaveCount(2);

    $mock->assertSent(function (ExtractRequest $request): bool {
        expect($request->body()->all()['items'])->toHaveCount(2);

        return true;
    });
});

it('accepts an SplFileInfo directly, the shape an uploaded file arrives in', function () {
    $path = sys_get_temp_dir().'/sie-upload-test.pdf';
    file_put_contents($path, 'PDF-BYTES');

    $mock = MockClient::global([
        MockResponse::make(['model' => 'docling', 'items' => [['data' => []]]], 200),
    ]);

    SIE::model('docling')->extract(new SplFileInfo($path));

    $mock->assertSent(function (ExtractRequest $request): bool {
        $document = $request->body()->all()['items'][0]['document'];

        expect($document['data']->bytes)->toBe('PDF-BYTES');
        expect($document['format'])->toBe('pdf');

        return true;
    });

    unlink($path);
});
