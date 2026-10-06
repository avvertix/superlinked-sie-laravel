<?php

declare(strict_types=1);

use Sie\Facades\SIE;
use Sie\Tests\Client\Support\Env;

/**
 * Exercises typed decisions against a real cluster, through
 * the native `SIE::` surface. This is the wire contract the laravel/ai
 * classification bridge will be built on.
 *
 * Needs SIE_ENDPOINT, and a cluster serving the models below. Override the
 * model id with SIE_DECIDE_MODEL.
 */
function decideModel(): string
{
    return Env::get('SIE_DECIDE_MODEL') ?? 'fastino/GLiNER2.5-Decide';
}

beforeEach(function () {
    $endpoint = Env::get('SIE_ENDPOINT');

    if ($endpoint === null) {
        test()->markTestSkipped('SIE_ENDPOINT is not set — skipping live integration test.');
    }

    config()->set('superlinked-sie-laravel.connections.default', [
        'url' => $endpoint,
        'key' => Env::get('SIE_KEY'),
        'timeout' => 120,
    ]);
});

const RCE_TEXT = 'Remote unauthenticated RCE in the login handler.';

it('answers a choice question with a label and probabilities', function () {
    $result = SIE::model(decideModel())
        ->schema([
            'severity' => [
                'type' => 'choice',
                'instructions' => 'Triage severity',
                'criteria' => [
                    'critical' => 'Exploitable remotely, no auth',
                    'low' => 'Minor impact',
                ],
            ],
        ])
        ->extract(RCE_TEXT)
        ->throwIfAnyFailed()
        ->sole();

    $answer = $result->data['severity'];

    expect($answer['type'])->toBe('choice')
        ->and($answer['choice'])->toBe('critical')
        ->and(array_keys($answer['probabilities']))->toEqualCanonicalizing(['critical', 'low'])
        ->and(array_sum($answer['probabilities']))->toEqualWithDelta(1.0, 0.01)
        ->and($answer['confidence'])->toBeFloat();
});

it('answers a yes/no question with the probability that it holds', function () {
    $result = SIE::model(decideModel())
        ->schema([
            'needs_auth' => [
                'type' => 'noul',
                'instructions' => 'Does exploitation require authentication?',
            ],
        ])
        ->extract(RCE_TEXT)
        ->throwIfAnyFailed()
        ->sole();

    $answer = $result->data['needs_auth'];

    expect($answer['type'])->toBe('noul')
        ->and($answer['noul'])->toBeFloat()->toBeBetween(0.0, 1.0)
        ->and($answer['answer'])->toBeBool()
        // "unauthenticated" is in the text, so the model should lean false.
        ->and($answer['noul'])->toBeLessThan(0.5);
});

it('answers a score question with an expected ordinal and a legend', function () {
    $result = SIE::model(decideModel())
        ->schema([
            'urgency' => [
                'type' => 'score',
                'instructions' => 'How urgent is this?',
                'criteria' => ['Not urgent', 'Soon', 'Immediate'],
            ],
        ])
        ->extract(RCE_TEXT)
        ->throwIfAnyFailed()
        ->sole();

    $answer = $result->data['urgency'];

    expect($answer['type'])->toBe('score')
        ->and($answer['score'])->toBeFloat()->toBeBetween(0.0, 2.0)
        ->and($answer['legend'])->toBe(['0' => 'Not urgent', '1' => 'Soon', '2' => 'Immediate'])
        ->and(array_keys($answer['probabilities']))->toHaveCount(3);
});

it('answers several mixed questions about one record in a single call', function () {
    $result = SIE::model(decideModel())
        ->schema([
            'severity' => [
                'type' => 'choice',
                'instructions' => 'Triage severity',
                'criteria' => ['critical' => 'Exploitable remotely, no auth', 'low' => 'Minor impact'],
            ],
            'urgency' => ['type' => 'score', 'instructions' => 'How urgent is this?', 'criteria' => ['Not urgent', 'Soon', 'Immediate']],
            'needs_auth' => ['type' => 'noul', 'instructions' => 'Does exploitation require auth?'],
        ])
        ->extract(RCE_TEXT)
        ->throwIfAnyFailed()
        ->sole();

    expect($result->data)->toHaveKeys(['severity', 'urgency', 'needs_auth']);
});

it('keeps a batch aligned, one answer per record', function () {
    $results = SIE::model(decideModel())
        ->schema(['needs_auth' => ['type' => 'noul', 'instructions' => 'Does exploitation require auth?']])
        ->extract([RCE_TEXT, 'An authenticated admin can edit a template.'])
        ->throwIfAnyFailed();

    expect($results)->toHaveCount(2)
        ->and($results[0]->data['needs_auth']['noul'])
        ->toBeLessThan($results[1]->data['needs_auth']['noul']);
});
