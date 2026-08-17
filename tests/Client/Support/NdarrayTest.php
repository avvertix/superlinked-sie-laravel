<?php

declare(strict_types=1);

use Sie\Client\Support\Ndarray;

/**
 * Builds the map shape msgpack-numpy puts on the wire.
 *
 * @param  list<int>  $shape
 */
function nd(string $type, array $shape, string $data): array
{
    return ['nd' => true, 'type' => $type, 'kind' => '', 'shape' => $shape, 'data' => $data];
}

/** A float16 from its raw bit pattern. */
function half(int $bits): array
{
    return nd('<f2', [1], pack('v', $bits));
}

it('recognises a msgpack-numpy array', function () {
    expect(Ndarray::is(nd('<f4', [1], pack('g', 1.0))))->toBeTrue();
    expect(Ndarray::is(['nd' => false]))->toBeFalse();
    expect(Ndarray::is(['dense' => [1.0]]))->toBeFalse();
    expect(Ndarray::is('not an array'))->toBeFalse();
});

it('decodes little-endian float32', function () {
    $values = Ndarray::decode(nd('<f4', [3], pack('g3', 0.5, -1.5, 2.25)));

    expect($values)->toBe([0.5, -1.5, 2.25]);
});

it('decodes little-endian float64', function () {
    expect(Ndarray::decode(nd('<f8', [2], pack('e2', 0.5, -1.5))))->toBe([0.5, -1.5]);
});

it('decodes signed and unsigned bytes as integers', function () {
    // Integer dtypes stay integers: the JSON path yields ints here, and
    // SparseResult::$indices is list<int>. A float would make the transport
    // visible to callers.
    expect(Ndarray::decode(nd('|i1', [3], pack('c3', -128, 0, 127))))->toBe([-128, 0, 127]);
    expect(Ndarray::decode(nd('|u1', [3], pack('C3', 0, 128, 255))))->toBe([0, 128, 255]);
});

it('decodes little-endian int32, the dtype sparse indices arrive in', function () {
    expect(Ndarray::decode(nd('<i4', [3], pack('V3', 0, 7, 4294967295))))->toBe([0, 7, -1]);
});

it('reshapes a 2-D array, the shape multivector arrives in', function () {
    $values = Ndarray::decode(nd('<f4', [2, 3], pack('g6', 1, 2, 3, 4, 5, 6)));

    expect($values)->toBe([[1.0, 2.0, 3.0], [4.0, 5.0, 6.0]]);
});

it('returns an empty list for an empty array', function () {
    expect(Ndarray::decode(nd('<f4', [0], '')))->toBe([]);
});

it('refuses a dtype it cannot decode', function () {
    Ndarray::decode(nd('<c8', [1], '12345678'));
})->throws(InvalidArgumentException::class, 'Unsupported numpy dtype [<c8]');

it('refuses a buffer whose length disagrees with the shape', function () {
    Ndarray::decode(nd('<f4', [4], pack('g2', 1.0, 2.0)));
})->throws(InvalidArgumentException::class, 'expected 16 bytes for shape [4] of <f4, got 8');

// float16 has no unpack() format, so it is decoded by hand and pinned here
// against the bit patterns that break naive implementations.
it('decodes float16 zeroes and their sign', function () {
    expect(Ndarray::decode(half(0x0000)))->toBe([0.0]);
    expect(Ndarray::decode(half(0x8000))[0])->toBe(-0.0);
});

it('decodes normal float16 values', function () {
    expect(Ndarray::decode(half(0x3C00)))->toBe([1.0]);       // 1
    expect(Ndarray::decode(half(0xBC00)))->toBe([-1.0]);      // -1
    expect(Ndarray::decode(half(0xC000)))->toBe([-2.0]);      // -2
    expect(Ndarray::decode(half(0x3555))[0])->toBeGreaterThan(0.333);
    expect(Ndarray::decode(half(0x7BFF)))->toBe([65504.0]);   // largest finite
});

it('decodes float16 subnormals', function () {
    // Smallest positive subnormal: 2**-24
    expect(Ndarray::decode(half(0x0001)))->toBe([2 ** -24]);
    // Largest subnormal: (1023/1024) * 2**-14
    expect(Ndarray::decode(half(0x03FF)))->toBe([(1023 / 1024) * 2 ** -14]);
    // Smallest positive normal: 2**-14
    expect(Ndarray::decode(half(0x0400)))->toBe([2 ** -14]);
});

it('decodes float16 infinities and NaN', function () {
    expect(Ndarray::decode(half(0x7C00))[0])->toBe(INF);
    expect(Ndarray::decode(half(0xFC00))[0])->toBe(-INF);
    expect(is_nan(Ndarray::decode(half(0x7E00))[0]))->toBeTrue();
});

it('leaves a structure without ndarrays untouched', function () {
    $payload = ['model' => 'x', 'scores' => [['item_id' => 'a', 'score' => 0.5]]];

    expect(Ndarray::normalize($payload))->toBe($payload);
});

it('normalises ndarrays wherever they appear in a response', function () {
    $payload = [
        'model' => 'BAAI/bge-m3',
        'items' => [[
            'dense' => ['dims' => 3, 'dtype' => 'float32', 'values' => nd('<f4', [3], pack('g3', 1, 2, 3))],
            'sparse' => [
                'indices' => nd('<i4', [2], pack('V2', 5, 9)),
                'values' => nd('<f4', [2], pack('g2', 0.25, 0.5)),
            ],
        ]],
    ];

    $normalized = Ndarray::normalize($payload);

    expect($normalized['items'][0]['dense']['values'])->toBe([1.0, 2.0, 3.0]);
    expect($normalized['items'][0]['dense']['dims'])->toBe(3);
    expect($normalized['items'][0]['sparse']['indices'])->toBe([5, 9]);
    expect($normalized['items'][0]['sparse']['values'])->toBe([0.25, 0.5]);
});
