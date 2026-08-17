<?php

declare(strict_types=1);

use MessagePack\BufferUnpacker;
use Sie\Client\Body\SieBodyRepository;
use Sie\Client\Support\Binary;
use Sie\Client\Support\WireFormat;

it('encodes as JSON by default', function () {
    $body = new SieBodyRepository(['items' => [['text' => 'hi']]]);

    expect((string) $body)->toBe('{"items":[{"text":"hi"}]}');
});

it('encodes as msgpack when told to', function () {
    $body = (new SieBodyRepository(['items' => [['text' => 'hi']]]))->useFormat(WireFormat::Msgpack);

    $packed = (string) $body;

    expect($packed)->not->toBe('{"items":[{"text":"hi"}]}');
    expect((new BufferUnpacker($packed))->unpack())->toBe(['items' => [['text' => 'hi']]]);
});

it('base64-encodes binary data on the JSON path', function () {
    $body = new SieBodyRepository(['items' => [['document' => ['data' => new Binary('%PDF'), 'format' => 'pdf']]]]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $body, true);

    expect($decoded['items'][0]['document']['data'])->toBe(base64_encode('%PDF'));
});

it('sends binary data as a native msgpack bin, not a string', function () {
    $body = (new SieBodyRepository(['data' => new Binary('%PDF')]))->useFormat(WireFormat::Msgpack);

    $packed = (string) $body;

    // 0xc4 is msgpack's bin8 marker. A 4-byte *string* would be fixstr (0xa4).
    // The server rejects a str here with "Expected `bytes`, got `str`", so this
    // marker is the whole difference between a 200 and a 400.
    expect(bin2hex($packed))->toContain('c404');
    expect(bin2hex($packed))->not->toContain('a425504446');
    expect((new BufferUnpacker($packed))->unpack())->toBe(['data' => '%PDF']);
});

it('sends UTF-8-valid documents as binary too', function () {
    // A markdown file is perfectly valid UTF-8, so byte-sniffing would pack it
    // as a str and the server would reject it. The marker has to be explicit.
    $body = (new SieBodyRepository(['data' => new Binary("# Ada\n")]))->useFormat(WireFormat::Msgpack);

    expect(bin2hex((string) $body))->toContain('c406');
});

it('leaves ordinary strings as msgpack strings', function () {
    $body = (new SieBodyRepository(['text' => 'hi']))->useFormat(WireFormat::Msgpack);

    // fixstr marker 0xa2 for a 2-byte string, not bin8.
    expect(bin2hex((string) $body))->toContain('a26869');
});

it('unwraps binary nested anywhere in the payload', function () {
    $body = (new SieBodyRepository([
        'items' => [
            ['images' => [['data' => new Binary("\x01\x02"), 'format' => null]]],
            ['document' => ['data' => new Binary('%PDF'), 'format' => 'pdf']],
        ],
    ]))->useFormat(WireFormat::Msgpack);

    $decoded = (new BufferUnpacker((string) $body))->unpack();

    expect($decoded['items'][0]['images'][0]['data'])->toBe("\x01\x02");
    expect($decoded['items'][1]['document']['data'])->toBe('%PDF');
});

it('reports the content type for the format it will encode', function () {
    expect((new SieBodyRepository)->contentType())->toBe('application/json');
    expect((new SieBodyRepository)->useFormat(WireFormat::Msgpack)->contentType())->toBe('application/msgpack');
});
