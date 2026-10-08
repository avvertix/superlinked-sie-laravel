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
use Laravel\Ai\Files\Document;
use Laravel\Ai\Responses\ClassificationResponse;
use Sie\Tests\Client\Support\Env;

/**
 * The laravel/ai classification entry points, answered by a real SIE cluster.
 *
 * They are skipped without SIE_ENDPOINT, like the rest of the live suite.
 */
beforeEach(function () {
    if (! class_exists(AiManager::class) || ! class_exists(Classification::class)) {
        test()->markTestSkipped('laravel/ai classification is not available.');
    }

    $endpoint = Env::get('SIE_ENDPOINT');

    if ($endpoint === null) {
        test()->markTestSkipped('SIE_ENDPOINT is not set — skipping live integration test.');
    }

    config()->set('superlinked-sie-laravel.connections.default', [
        'url' => $endpoint,
        'key' => Env::get('SIE_KEY'),
        'timeout' => 120,
    ]);
    config()->set('superlinked-sie-laravel.ai.classification.model', Env::get('SIE_DECIDE_MODEL') ?? 'fastino/GLiNER2.5-Decide');
    Config::set('ai.providers.sie', ['driver' => 'sie', 'key' => null, 'name' => 'sie']);
});

it('is a classification provider', function () {
    expect(app(AiManager::class)->classificationProvider('sie'))->toBeInstanceOf(ClassificationProvider::class);
});

it('answers boolean, choice and score questions through Classification::of', function () {
    $response = Classification::of('I was charged twice and nobody is answering my emails. Fix this today!')
        ->questions([
            'urgent' => new Boolean('Does this request need an immediate response?', [
                'true' => 'Explicitly time-sensitive',
                'false' => 'No urgency expressed',
            ]),
            'department' => new Choice('Which team should handle this request?', [
                'billing' => 'Payments, invoices, and refunds',
                'technical' => 'Bugs, outages, and integrations',
                'sales' => 'Pricing, plans, and upgrades',
            ]),
            'frustration' => new Score('How frustrated is the customer?', [
                'Calm',
                'Frustrated',
                'Very angry',
            ]),
        ])
        ->classify('sie');

    expect($response)->toBeInstanceOf(ClassificationResponse::class)->toHaveCount(3);

    expect($response->answer('urgent')->isTrue())->toBeTrue();
    expect($response->answer('department')->choice)->toBe('billing');
    expect($response->answer('frustration')->score)->toBeGreaterThan(0.5);
});

it('exposes probabilities so callers can apply their own threshold', function () {
    $response = Classification::of('Remote unauthenticated RCE in the login handler.')
        ->question('severity', new Choice('Triage severity', [
            'critical' => 'Exploitable remotely, no auth',
            'low' => 'Minor impact',
        ]))
        ->classify('sie');

    expect($response->answer('severity')->probabilities)->toHaveKeys(['critical', 'low']);
});

it('decides a yes/no question with Str::decide', function () {
    expect(Str::of('WIN a FREE cruise!!! Click here now')->decide('Is this spam?', provider: 'sie'))->toBeTrue();
    expect(Str::of('Hi, could you resend invoice 1042? Thanks, Maria')->decide('Is this spam?', provider: 'sie'))->toBeFalse();
});

it('decides with criteria and a stricter threshold', function () {
    $spam = Str::of('Huge discount, limited time, click now')->decide(
        'Is this spam?',
        criteria: [
            'true' => 'Unsolicited bulk mail.',
            'false' => 'A genuine message from a customer.',
        ],
        threshold: 0.9,
        provider: 'sie',
    );

    expect($spam)->toBeBool();
});

it('picks an item from a collection with Collection::decide', function () {
    $teams = collect(['billing', 'technical', 'sales']);

    expect($teams->decide('Which team handles this?', 'My invoice is wrong', provider: 'sie'))
        ->toBe('billing');
});

it('classifies the content of a text attachment', function () {
    $email = Document::fromString("Subject: Charged twice\n\nI see two charges for invoice 1042. Please refund one.", 'text/plain')
        ->as('email.txt');

    $response = Classification::of('Support request attached.', [$email])
        ->question('department', new Choice('Which team should handle this request?', [
            'billing' => 'Payments, invoices, and refunds',
            'technical' => 'Bugs, outages, and integrations',
            'sales' => 'Pricing, plans, and upgrades',
        ]))
        ->classify('sie');

    expect($response->answer('department')->choice)->toBe('billing');
});
