<?php

declare(strict_types=1);

use Sie\Client\Exceptions\ServerException;
use Sie\Client\Support\WireList;

it('returns an empty list for a missing member', function () {
    expect(WireList::of(null, 'items'))->toBe([]);
    expect(WireList::of([], 'items'))->toBe([]);
});

it('returns the objects it was given as a list', function () {
    expect(WireList::of([['a' => 1], ['b' => 2]], 'items'))->toBe([['a' => 1], ['b' => 2]]);
});

it('reindexes a sparse array into a list', function () {
    // A JSON object with numeric-looking keys decodes to a non-list array, and
    // every caller then reads results positionally.
    expect(WireList::of([2 => ['a' => 1], 5 => ['b' => 2]], 'items'))->toBe([['a' => 1], ['b' => 2]]);
});

it('rejects a member that is not a list at all', function () {
    WireList::of('nope', 'items');
})->throws(ServerException::class, "Malformed SIE response: expected 'items' to be a list of objects, got string.");

it('rejects an element that is not an object', function () {
    // Left unchecked this reaches a fromArray(array $data) as a TypeError from
    // deep inside a mapper, which says nothing about which field was wrong.
    WireList::of([['a' => 1], 'nope'], 'items');
})->throws(ServerException::class, "Malformed SIE response: expected every element of 'items' to be an object, got string at index 1.");

it('carries a wire error code so the failure is classifiable', function () {
    try {
        WireList::of('nope', 'items');
    } catch (ServerException $exception) {
        expect($exception->errorCode)->toBe('MALFORMED_RESPONSE');

        return;
    }

    throw new RuntimeException('Expected a ServerException.');
});
