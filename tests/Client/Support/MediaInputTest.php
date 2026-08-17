<?php

declare(strict_types=1);

use Sie\Client\Support\Binary;
use Sie\Client\Support\MediaInput;

it('keeps raw bytes as bytes, inferring no format for a bare string', function () {
    $wire = MediaInput::image("\xFF\xD8\xFF");

    // Bytes stay raw and marked. How they travel is the body repository's call,
    // because JSON wants base64 and msgpack wants a native bin, and the server
    // rejects whichever one it did not ask for.
    expect($wire['data'])->toBeInstanceOf(Binary::class);
    expect($wire['data']->bytes)->toBe("\xFF\xD8\xFF");
    expect($wire['format'])->toBeNull();
});

it('reads a file and infers the image format from its extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'sie').'.png';
    file_put_contents($path, "\x89PNG");

    try {
        $wire = MediaInput::image(new SplFileInfo($path));

        expect($wire['data']->bytes)->toBe("\x89PNG");
        expect($wire['format'])->toBe('png');
    } finally {
        unlink($path);
    }
});

it('lets an explicit format override extension inference', function () {
    $path = tempnam(sys_get_temp_dir(), 'sie').'.png';
    file_put_contents($path, 'x');

    try {
        $wire = MediaInput::image(['data' => new SplFileInfo($path), 'format' => 'jpeg']);

        expect($wire['format'])->toBe('jpeg');
    } finally {
        unlink($path);
    }
});

it('treats an explicit null format as "no hint" rather than re-inferring', function () {
    // Presence check, not a null check — matches Python's `.get("format", inferred)`.
    $path = tempnam(sys_get_temp_dir(), 'sie').'.png';
    file_put_contents($path, 'x');

    try {
        $wire = MediaInput::image(['data' => new SplFileInfo($path), 'format' => null]);

        expect($wire['format'])->toBeNull();
    } finally {
        unlink($path);
    }
});

it('infers the document format from its extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'sie').'.pdf';
    file_put_contents($path, '%PDF');

    try {
        $wire = MediaInput::document(new SplFileInfo($path));

        expect($wire['data']->bytes)->toBe('%PDF');
        expect($wire['format'])->toBe('pdf');
    } finally {
        unlink($path);
    }
});

it('keeps empty binary empty', function () {
    expect(MediaInput::image('')['data']->bytes)->toBe('');
});

it('keeps arbitrary binary intact', function () {
    $binary = random_bytes(256);

    expect(MediaInput::image($binary)['data']->bytes)->toBe($binary);
});

it('throws when the file does not exist', function () {
    expect(fn () => MediaInput::document(new SplFileInfo('/nonexistent/path.pdf')))
        ->toThrow(RuntimeException::class);
});
