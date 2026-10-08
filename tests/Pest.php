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
 * The id of the first loaded live model satisfying `$predicate`, or skip.
 *
 * Resolving by capability rather than hardcoding ids keeps the suite working
 * against a differently-provisioned instance. Only models already loaded count:
 * a catalog entry that is not loaded would be downloaded by the first request,
 * so the suite skips instead of pulling models nobody preloaded.
 *
 * @param  list<string>  $candidates  Model ids to probe, in preference order.
 * @param  ?callable(ModelInfo): bool  $predicate
 */
function firstModelMatching(SieClient $client, array $candidates, ?callable $predicate = null): string
{
    foreach ($candidates as $candidate) {
        try {
            $model = $client->getModel($candidate);

            if ($model->loaded === true && ($predicate === null || $predicate($model))) {
                return $candidate;
            }
        } catch (Throwable) {
            // Not served by this deployment — try the next candidate.
        }
    }

    test()->markTestSkipped('No loaded model on this instance matched ['.implode(', ', $candidates).'].');
}

/**
 * The multivector embedder compose.yaml preloads (`SIE_PRELOAD_MODELS`), or
 * skip. It only produces `multivector`, so encode calls must ask for it: the
 * server defaults to `dense` and rejects that.
 */
function embeddingModel(): string
{
    return firstModelMatching(
        sieClient(),
        ['topk-io/topk-embed-v1-xsmall'],
        static fn (ModelInfo $m): bool => in_array('multivector', $m->outputs ?? [], true),
    );
}

/**
 * A minimal 8x8 RGB JPEG, so the server has real bytes to decode. It must have
 * three colour components: image processors normalise with a 3-value RGB mean,
 * and a grayscale JPEG fails with "mean must have 1 elements if it is an
 * iterable, got 3".
 */
function tinyJpeg(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gOTAK'
        .'/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU'
        .'/8AAEQgACAAIAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A5KiiivxQ/qg//9k=',
        true,
    );
}

uses(TestCase::class)->in(__DIR__);
