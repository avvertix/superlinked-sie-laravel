<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Saloon\Http\Faking\MockResponse;
use Sie\Client\Support\RequestMetadataParser;

it('parses a request id from the response headers', function () {
    $response = sendMocked(MockResponse::make(
        ['ok' => true],
        200,
        ['X-SIE-Request-ID' => '019ff083-a907-7541-ad13-6881e092a970'],
    ));

    expect(RequestMetadataParser::parse($response)?->id)->toBe('019ff083-a907-7541-ad13-6881e092a970');
});

it('finds the header regardless of case', function () {
    // Real gateway responses arrive lowercased over HTTP/1.1.
    $response = sendMocked(MockResponse::make(['ok' => true], 200, ['x-sie-request-id' => 'abc-123']));

    expect(RequestMetadataParser::parse($response)?->id)->toBe('abc-123');
});

it('returns null when the response carries no metadata at all', function () {
    expect(RequestMetadataParser::parse(sendMocked(MockResponse::make(['ok' => true], 200))))->toBeNull();
});

it('rejects a malformed request id rather than passing it on', function (string $id) {
    // This value gets echoed into logs and support tickets, so an unbounded
    // blob or non-ASCII payload from an intermediary must not ride along.
    $response = sendMocked(MockResponse::make(['ok' => true], 200, ['X-SIE-Request-ID' => $id]));

    expect(RequestMetadataParser::parse($response))->toBeNull();
})->with([
    'empty' => [''],
    'non-ascii' => ['abc-café'],
    'over 256 characters' => [str_repeat('a', 257)],
]);

it('never sees control characters, because PSR-7 rejects them first', function (string $id) {
    // Documents where the real boundary is: the transport refuses to build a
    // response carrying these at all. The parser's own printable-ASCII check is
    // defence in depth for any future non-PSR-7 source, not the primary gate.
    expect(fn () => new Response(200, ['X-SIE-Request-ID' => $id]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'trailing newline' => ["abc\n"],
    'embedded newline' => ["ab\ncd"],
    'embedded null byte' => ["ab\x00cd"],
    'control character' => ["abc\x07"],
]);

it('is handed already-trimmed values by PSR-7', function () {
    // Surrounding whitespace is stripped by the transport, so the parser's trim
    // check is likewise defence in depth rather than something a live response
    // can trigger.
    $response = sendMocked(MockResponse::make(['ok' => true], 200, ['X-SIE-Request-ID' => '  abc  ']));

    expect(RequestMetadataParser::parse($response)?->id)->toBe('abc');
});

it('accepts an id at exactly the length limit', function () {
    $id = str_repeat('a', 256);
    $response = sendMocked(MockResponse::make(['ok' => true], 200, ['X-SIE-Request-ID' => $id]));

    expect(RequestMetadataParser::parse($response)?->id)->toBe($id);
});

it('accepts internal spaces, which are printable', function () {
    $response = sendMocked(MockResponse::make(['ok' => true], 200, ['X-SIE-Request-ID' => 'req 123']));

    expect(RequestMetadataParser::parse($response)?->id)->toBe('req 123');
});
