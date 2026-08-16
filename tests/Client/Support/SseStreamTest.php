<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Sie\Client\Support\SseStream;

it('extracts data payloads and stops on [DONE]', function () {
    $stream = Utils::streamFor("data: {\"a\":1}\n\ndata: {\"a\":2}\n\ndata: [DONE]\n\ndata: {\"a\":3}\n\n");

    $payloads = iterator_to_array(SseStream::payloads($stream));

    expect($payloads)->toBe(['{"a":1}', '{"a":2}']);
});

it('strips a single leading space after "data:"', function () {
    $stream = Utils::streamFor("data:  {\"a\":1}\n\ndata: [DONE]\n\n");

    $payloads = iterator_to_array(SseStream::payloads($stream));

    // Only ONE leading space is stripped, so a second space survives inside the payload.
    expect($payloads)->toBe([' {"a":1}']);
});

it('skips blank lines and comment lines', function () {
    $stream = Utils::streamFor(":comment\n\ndata: {\"a\":1}\n\n\ndata: [DONE]\n\n");

    $payloads = iterator_to_array(SseStream::payloads($stream));

    expect($payloads)->toBe(['{"a":1}']);
});

it('yields a final payload even without a trailing newline', function () {
    $stream = Utils::streamFor('data: {"a":1}');

    $payloads = iterator_to_array(SseStream::payloads($stream));

    expect($payloads)->toBe(['{"a":1}']);
});
