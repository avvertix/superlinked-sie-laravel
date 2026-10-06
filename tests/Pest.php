<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Response;
use Sie\Client\Connectors\SieConnector;
use Sie\Client\Data\ModelInfo;
use Sie\Client\SieClient;
use Sie\Tests\Client\Fixtures\PingRequest;
use Sie\Tests\Client\Support\Env;
use Sie\Tests\TestCase;

/** Sends a single mocked response through a throwaway connector and returns the resulting Response. */
function sendMocked(MockResponse $mockResponse): Response
{
    $connector = new SieConnector('https://example.test');
    $connector->withMockClient(new MockClient([$mockResponse]));

    return $connector->send(new PingRequest);
}

/**
 * A client pointed at the live instance from `.env` / the environment.
 *
 * Every test in the Integration suite (tests/Client/Integration) goes through
 * this helper, so `SIE_ENDPOINT` (see tests/.env.example) is the only switch:
 * the calling test is skipped when it is absent, and a fresh clone with no
 * credentials still runs green.
 */
function sieClient(float $timeoutS = 120.0): SieClient
{
    $endpoint = Env::get('SIE_ENDPOINT');

    if ($endpoint === null) {
        test()->markTestSkipped('SIE_ENDPOINT is not set — skipping live integration test.');
    }

    return new SieClient($endpoint, timeoutS: $timeoutS, apiKey: Env::get('SIE_KEY'));
}

/**
 * The id of the first live model satisfying `$predicate`, or skip.
 *
 * Resolving by capability rather than hardcoding ids keeps the suite working
 * against a differently-provisioned instance.
 *
 * @param  list<string>  $candidates  Model ids to probe, in preference order.
 * @param  callable(ModelInfo): bool  $predicate
 */
function firstModelMatching(SieClient $client, array $candidates, callable $predicate): string
{
    foreach ($candidates as $candidate) {
        try {
            if ($predicate($client->getModel($candidate))) {
                return $candidate;
            }
        } catch (Throwable) {
            // Not served by this deployment — try the next candidate.
        }
    }

    test()->markTestSkipped('No candidate model on this instance matched the required capability.');
}

uses(TestCase::class)->in(__DIR__);
