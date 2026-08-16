<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Sie\File;
use Sie\Input;

it('builds a text input', function () {
    expect(Input::text('Hello world')->toArray())->toBe(['text' => 'Hello world']);
});

it('carries an id when one is given', function () {
    expect(Input::text('Hello world', 'doc-1')->toArray())->toBe(['id' => 'doc-1', 'text' => 'Hello world']);
});

it('normalises a bare string into a single text input', function () {
    $inputs = Input::listFrom('Hello world');

    expect($inputs)->toHaveCount(1);
    expect($inputs[0]->toArray())->toBe(['text' => 'Hello world']);
});

it('normalises a list of strings into a batch', function () {
    $inputs = Input::listFrom(['a', 'b']);

    expect($inputs)->toHaveCount(2);
    expect($inputs[1]->toArray())->toBe(['text' => 'b']);
});

it('treats an associative array as one already-shaped input, not a batch', function () {
    $inputs = Input::listFrom(['text' => 'Hello world', 'id' => 'doc-1']);

    expect($inputs)->toHaveCount(1);
    expect($inputs[0]->toArray())->toBe(['id' => 'doc-1', 'text' => 'Hello world']);
});

it('accepts an Input instance unchanged', function () {
    $input = Input::text('Hello world');

    expect(Input::from($input))->toBe($input);
});

it('builds immutable copies when chaining', function () {
    $original = Input::text('Hello world');
    $withId = $original->withId('doc-1');

    expect($original->id)->toBeNull();
    expect($withId->id)->toBe('doc-1');
    expect($withId->text)->toBe('Hello world');
});

it('reads a document from a filesystem disk and infers its format', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('invoices/march.pdf', 'PDF-BYTES');

    $wire = Input::document(File::disk('documents', 'invoices/march.pdf'))->toArray();

    expect($wire['document'])->toBe(['data' => 'PDF-BYTES', 'format' => 'pdf']);
});

it('prefers an explicitly supplied format over the inferred one', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('report.bin', 'RAW');

    $wire = Input::document(File::disk('documents', 'report.bin', 'docx'))->toArray();

    expect($wire['document']['format'])->toBe('docx');
});

it('infers image formats from the image table, not the document one', function () {
    Storage::fake('images');
    Storage::disk('images')->put('cover.jpg', 'JPG-BYTES');

    $wire = Input::image(File::disk('images', 'cover.jpg'))->toArray();

    expect($wire['images'])->toBe([['data' => 'JPG-BYTES', 'format' => 'jpeg']]);
});

it('reports its resolved size in bytes so a request can be capped before sending', function () {
    Storage::fake('documents');
    Storage::disk('documents')->put('a.pdf', str_repeat('x', 500));

    $input = Input::text('12345')->withDocument(File::disk('documents', 'a.pdf'));

    expect($input->bytes())->toBe(505);
});

it('throws when the images key is not a list', function () {
    Input::from(['images' => 'not-a-list']);
})->throws(InvalidArgumentException::class, 'The "images" key must be a list of images.');
