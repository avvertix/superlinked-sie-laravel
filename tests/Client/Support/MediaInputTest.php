<?php

declare(strict_types=1);

use Sie\Client\Support\MediaInput;

it('converts raw bytes to standard base64 with no format inference', function () {
    $wire = MediaInput::image("\xFF\xD8\xFF");

    expect($wire)->toBe(['data' => base64_encode("\xFF\xD8\xFF"), 'format' => null])
        ->and($wire['data'])->toBe('/9j/');
});

it('reads a file and infers the image format from its extension', function () {
    $path = tempnam(sys_get_temp_dir(), 'sie').'.png';
    file_put_contents($path, "\x89PNG");

    try {
        $wire = MediaInput::image(new SplFileInfo($path));

        expect($wire)->toBe(['data' => base64_encode("\x89PNG"), 'format' => 'png']);
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

        expect($wire)->toBe(['data' => base64_encode('%PDF'), 'format' => 'pdf']);
    } finally {
        unlink($path);
    }
});

it('encodes empty binary as an empty string', function () {
    expect(MediaInput::image(''))->toBe(['data' => '', 'format' => null]);
});

it('round-trips arbitrary binary through base64', function () {
    $binary = random_bytes(256);

    expect(base64_decode(MediaInput::image($binary)['data'], true))->toBe($binary);
});

it('throws when the file does not exist', function () {
    expect(fn () => MediaInput::document(new SplFileInfo('/nonexistent/path.pdf')))
        ->toThrow(RuntimeException::class);
});
