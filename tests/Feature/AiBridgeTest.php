<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Contracts\Providers\ClassificationProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Sie\Ai\SieProvider;
use Sie\Client\Requests\Encode\EncodeRequest;
use Sie\Exceptions\ExtractionFailedException;
use Sie\Exceptions\MissingEmbeddingDimensionsException;
use Sie\Facades\SIE;
use Sie\Testing\FakeModel;

// The bridge is optional, so the suite has to stay green without laravel/ai.
beforeEach(function () {
    if (! class_exists(AiManager::class)) {
        test()->markTestSkipped('laravel/ai is not installed.');
    }
});

afterEach(fn () => MockClient::destroyGlobal());

function sieProvider(array $config = []): SieProvider
{
    return new SieProvider(
        ['driver' => 'sie', 'name' => 'sie', ...$config],
        app('events'),
    );
}

it('is both an embedding and a reranking provider', function () {
    expect(sieProvider())
        ->toBeInstanceOf(EmbeddingProvider::class)
        ->toBeInstanceOf(RerankingProvider::class);
});

it('generates dense embeddings through the bridge', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', 1024);

    $response = sieProvider()->embeddings(['Hello world', 'Goodbye world']);

    expect($response)->toBeInstanceOf(EmbeddingsResponse::class)->toHaveCount(2);
    expect($response->first())->toHaveCount(1024);
    expect($response->meta->model)->toBe('BAAI/bge-m3');

    SIE::assertEncoded('BAAI/bge-m3');
});

it('refuses to guess the embedding width when it is not configured', function () {
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', null);

    sieProvider()->defaultEmbeddingsDimensions();
})->throws(MissingEmbeddingDimensionsException::class);

it('passes provider options through to SIE', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(8)]);

    sieProvider()->embeddings(
        ['Hello world'],
        dimensions: 8,
        providerOptions: ['instruction' => 'retrieve', 'is_query' => true, 'pool' => 'eval-bench'],
    );

    SIE::assertEncoded('BAAI/bge-m3', function (array $body): bool {
        expect($body['params']['instruction'])->toBe('retrieve');
        expect($body['params']['options']['is_query'])->toBeTrue();

        return true;
    });
});

it('reranks documents and maps them back to their original index', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['1' => 0.9, '0' => 0.2])]);

    $response = sieProvider()->rerank(['a duck', 'a goose'], 'waterfowl');

    expect($response)->toBeInstanceOf(RerankingResponse::class)->toHaveCount(2);
    expect($response->first()->document)->toBe('a goose');
    expect($response->first()->index)->toBe(1);
    expect($response->first()->score)->toBe(0.9);
    expect($response->documents()->all())->toBe(['a goose', 'a duck']);
});

it('limits reranked results when asked', function () {
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['1' => 0.9, '0' => 0.2])]);

    expect(sieProvider()->rerank(['a duck', 'a goose'], 'waterfowl', limit: 1))->toHaveCount(1);
});

it('only accepts text inputs, matching its dense-only mapping', function () {
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', 8);

    sieProvider()->embeddings([Image::fromPath(__FILE__)]);
})->throws(InvalidArgumentException::class, 'only supports text embeddings inputs');

it('uses the connection named in config rather than the default one', function () {
    config()->set('superlinked-sie-laravel.connections.eu', ['url' => 'https://eu.sie.test']);
    config()->set('superlinked-sie-laravel.ai.connection', 'eu');
    config()->set('superlinked-sie-laravel.ai.embeddings.dimensions', 8);

    $mock = MockClient::global([
        MockResponse::make(['model' => 'BAAI/bge-m3', 'items' => [['dense' => ['values' => [0.1]]]]], 200),
    ]);

    sieProvider()->embeddings(['Hello world']);

    $mock->assertSent(function (EncodeRequest $request, $response): bool {
        expect((string) $response->getPendingRequest()->getUri())->toStartWith('https://eu.sie.test');

        return true;
    });
});

it('generates embeddings through the Embeddings entry point', function () {
    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);
    Config::set('superlinked-sie-laravel.ai.embeddings.dimensions', 1024);
    SIE::fake(['BAAI/bge-m3' => FakeModel::dense(1024)]);

    $response = Embeddings::for(['a duck paddles on a pond'])->generate('sie');

    expect($response->first())->toHaveCount(1024);
    SIE::assertEncoded('BAAI/bge-m3');
});

it('reranks through the Reranking entry point', function () {
    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);
    SIE::fake(['BAAI/bge-m3' => FakeModel::scores(['1' => 0.9, '0' => 0.2])]);

    $response = Reranking::of(['a duck', 'a goose'])->rerank('waterfowl', 'sie');

    expect($response->documents()->all())->toBe(['a goose', 'a duck']);
    SIE::assertScored('BAAI/bge-m3');
});

function decideFake(array $data): FakeModel
{
    return FakeModel::extracting(fn (array $item): array => ['data' => $data]);
}

it('is also a classification provider', function () {
    expect(sieProvider())->toBeInstanceOf(ClassificationProvider::class);
    expect(sieProvider()->defaultClassificationModel())->toBe('fastino/GLiNER2.5-Decide');
});

it('answers boolean, choice and score questions from one extract call', function () {
    SIE::fake(['fastino/GLiNER2.5-Decide' => decideFake([
        'urgent' => ['type' => 'noul', 'noul' => 0.93, 'answer' => true, 'confidence' => 0.86],
        'department' => [
            'type' => 'choice',
            'choice' => 'billing',
            'probabilities' => ['billing' => 0.8, 'technical' => 0.15, 'sales' => 0.05],
            'confidence' => 0.8,
        ],
        'frustration' => [
            'type' => 'score',
            'score' => 1.8,
            'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'],
            'probabilities' => ['0' => 0.05, '1' => 0.1, '2' => 0.85],
            'confidence' => 0.85,
        ],
    ])]);

    $response = sieProvider()->classify('I was charged twice!', [
        'urgent' => new Boolean('Does this request need an immediate response?'),
        'department' => new Choice('Which team?', ['billing' => 'Payments', 'technical' => 'Bugs', 'sales' => 'Plans']),
        'frustration' => new Score('How frustrated?', ['Calm', 'Frustrated', 'Very angry']),
    ]);

    expect($response)->toBeInstanceOf(ClassificationResponse::class)->toHaveCount(3);
    expect($response->meta->model)->toBe('fastino/GLiNER2.5-Decide');

    expect($response->answer('urgent'))->toBeInstanceOf(BooleanAnswer::class);
    expect($response->answer('urgent')->probability)->toBe(0.93);
    expect($response->answer('urgent')->isTrue())->toBeTrue();
    expect($response->answer('urgent')->isTrue(0.95))->toBeFalse();

    expect($response->answer('department'))->toBeInstanceOf(ChoiceAnswer::class);
    expect($response->answer('department')->choice)->toBe('billing');
    expect($response->answer('department')->probabilityOf('technical'))->toBe(0.15);
    expect($response->answer('department')->confidence)->toBe(0.8);

    expect($response->answer('frustration'))->toBeInstanceOf(ScoreAnswer::class);
    expect($response->answer('frustration')->score)->toBe(1.8);
    expect($response->answer('frustration')->legend)->toBe([0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry']);

    SIE::assertExtracted('fastino/GLiNER2.5-Decide');
    SIE::assertSentCount(1);
});

it('sends each question in the shape the decision model expects', function () {
    SIE::fake(['fastino/GLiNER2.5-Decide' => decideFake([
        'urgent' => ['type' => 'noul', 'noul' => 0.1],
        'department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 1.0, 'sales' => 0.0]],
        'frustration' => ['type' => 'score', 'score' => 0.0, 'legend' => [], 'probabilities' => []],
    ])]);

    sieProvider()->classify('Hello', [
        'urgent' => new Boolean('Urgent?', ['true' => 'Time-sensitive', 'false' => 'Not urgent']),
        'department' => new Choice('Which team?', ['billing' => 'Payments', 'sales' => null]),
        'frustration' => new Score('How frustrated?', ['Calm', 'Angry']),
    ]);

    SIE::assertExtracted('fastino/GLiNER2.5-Decide', function (array $body): bool {
        expect($body['items'][0]['text'])->toBe('Hello');
        expect($body['params']['output_schema'])->toBe([
            'urgent' => [
                'type' => 'noul',
                'instructions' => 'Urgent?',
                'criteria' => ['true' => 'Time-sensitive', 'false' => 'Not urgent'],
            ],
            'department' => [
                'type' => 'choice',
                'instructions' => 'Which team?',
                // An option without a description falls back to its own name.
                'criteria' => ['billing' => 'Payments', 'sales' => 'sales'],
            ],
            'frustration' => [
                'type' => 'score',
                'instructions' => 'How frustrated?',
                'criteria' => ['Calm', 'Angry'],
            ],
        ]);

        return true;
    });
});

it('sends a structured state as JSON text', function () {
    SIE::fake(['fastino/GLiNER2.5-Decide' => decideFake(['urgent' => ['type' => 'noul', 'noul' => 0.5]])]);

    sieProvider()->classify(['subject' => 'Refund', 'body' => 'Twice!'], ['urgent' => new Boolean('Urgent?')]);

    SIE::assertExtracted('fastino/GLiNER2.5-Decide', function (array $body): bool {
        expect(json_decode($body['items'][0]['text'], true))->toBe(['subject' => 'Refund', 'body' => 'Twice!']);

        return true;
    });
});

it('classifies through the Classification entry point and the decide macro', function () {
    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);
    SIE::fake(['fastino/GLiNER2.5-Decide' => decideFake(['decision' => ['type' => 'noul', 'noul' => 0.97]])]);

    $response = Classification::of('WIN a FREE cruise')->question('decision', new Boolean('Is this spam?'))->classify('sie');

    expect($response->answer('decision')->isTrue())->toBeTrue();
    expect(Str::of('WIN a FREE cruise')->decide('Is this spam?', provider: 'sie'))->toBeTrue();
    expect(Str::of('WIN a FREE cruise')->decide('Is this spam?', threshold: 0.99, provider: 'sie'))->toBeFalse();
});

it('uses the classification model named in config', function () {
    config()->set('superlinked-sie-laravel.ai.classification.model', 'fastino/GLiNER2.5-Decide-1B');
    SIE::fake(['fastino/GLiNER2.5-Decide-1B' => decideFake(['urgent' => ['type' => 'noul', 'noul' => 0.5]])]);

    sieProvider()->classify('Hello', ['urgent' => new Boolean('Urgent?')]);

    SIE::assertExtracted('fastino/GLiNER2.5-Decide-1B');
});

it('fails loudly when the server answers fewer questions than were asked', function () {
    SIE::fake(['fastino/GLiNER2.5-Decide' => decideFake(['urgent' => ['type' => 'noul', 'noul' => 0.5]])]);

    sieProvider()->classify('Hello', [
        'urgent' => new Boolean('Urgent?'),
        'department' => new Choice('Which team?', ['billing' => 'Payments', 'sales' => 'Plans']),
    ]);
})->throws(InvalidArgumentException::class, 'no answer for question [department]');

it('fails loudly on an answer type it does not know', function () {
    SIE::fake(['fastino/GLiNER2.5-Decide' => decideFake(['urgent' => ['type' => 'mystery']])]);

    sieProvider()->classify('Hello', ['urgent' => new Boolean('Urgent?')]);
})->throws(InvalidArgumentException::class, 'unknown answer type for question [urgent]');

it('surfaces an extraction failure instead of returning empty answers', function () {
    SIE::fake(['fastino/GLiNER2.5-Decide' => FakeModel::extracting(
        fn (array $item): array => ['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'model crashed']],
    )]);

    sieProvider()->classify('Hello', ['urgent' => new Boolean('Urgent?')]);
})->throws(ExtractionFailedException::class, 'model crashed');
