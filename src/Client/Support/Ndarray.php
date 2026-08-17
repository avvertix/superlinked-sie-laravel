<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use InvalidArgumentException;

/**
 * Decodes the numpy arrays that arrive inside msgpack responses.
 *
 * `sie_server` serialises with `msgpack.packb(..., use_bin_type=True)` under
 * msgpack-numpy, which does not use a msgpack ext type: an `ndarray` becomes an
 * ordinary map carrying the dtype string, the shape, and the raw little-endian
 * buffer.
 *
 *     dense        {nd: true, type: "<f4", kind: "", shape: [1024],   data: <4096 bytes>}
 *     multivector  {nd: true, type: "<f4", kind: "", shape: [4, 1024], data: <16384 bytes>}
 *
 * On the JSON path the same values arrive as plain nested lists, so
 * {@see normalize()} runs over a decoded msgpack body to make the two
 * transports produce identical PHP values. Everything downstream — every
 * `Data` class — is then unable to tell which one was used.
 */
final class Ndarray
{
    /**
     * Bytes per element for each dtype the gateway emits.
     *
     * `<` is little-endian, `|` is not-applicable (single byte). The observed
     * set is float32 dense/sparse/multivector, float16/int8/uint8 from
     * `output_dtype` quantisation, and int32 sparse indices; float64 and int64
     * are included because they cost one line each and are the obvious
     * neighbours.
     */
    private const array WIDTHS = [
        '<f2' => 2, '<f4' => 4, '<f8' => 8,
        '|i1' => 1, '|u1' => 1,
        '<i2' => 2, '<i4' => 4, '<i8' => 8,
        '<u2' => 2, '<u4' => 4,
    ];

    /**
     * Whether a decoded value is one of msgpack-numpy's array maps.
     */
    public static function is(mixed $value): bool
    {
        return is_array($value)
            && ($value['nd'] ?? false) === true
            && isset($value['type'], $value['shape'], $value['data']);
    }

    /**
     * Replace every ndarray map in a decoded body with plain PHP lists,
     * leaving everything else exactly as it was.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public static function normalize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (self::is($value)) {
                /** @var array{type: string, shape: list<int>, data: string} $value */
                $payload[$key] = self::decode($value);
            } elseif (is_array($value)) {
                $payload[$key] = self::normalize($value);
            }
        }

        return $payload;
    }

    /**
     * Decode one ndarray map into a list of numbers, nested to match its shape.
     *
     * Integer dtypes stay PHP integers and float dtypes stay floats, because
     * that is what the JSON path yields and the two must agree exactly:
     * `SparseResult::$indices` is `list<int>`, and a float there would make the
     * transport observable to callers.
     *
     * @param  array<string, mixed>  $array
     * @return list<mixed>
     */
    public static function decode(array $array): array
    {
        $type = is_string($array['type'] ?? null) ? $array['type'] : '';
        $data = is_string($array['data'] ?? null) ? $array['data'] : '';

        /** @var list<int> $shape */
        $shape = is_array($array['shape'] ?? null) ? array_values($array['shape']) : [];

        $width = self::WIDTHS[$type] ?? null;

        if ($width === null) {
            throw new InvalidArgumentException(
                "Unsupported numpy dtype [{$type}] in a SIE response. Supported: ".implode(', ', array_keys(self::WIDTHS)).'.',
            );
        }

        $count = array_product($shape === [] ? [0] : $shape);
        $expected = $count * $width;

        if (strlen($data) !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'Malformed numpy array in a SIE response: expected %d bytes for shape [%s] of %s, got %d.',
                $expected,
                implode(', ', $shape),
                $type,
                strlen($data),
            ));
        }

        return self::reshape(self::flatten($type, $data, (int) $count), $shape);
    }

    /**
     * @return list<int|float>
     */
    private static function flatten(string $type, string $data, int $count): array
    {
        if ($count === 0) {
            return [];
        }

        // PHP's unpack() has no half-float format, so float16 is decoded from
        // its bits; every other dtype has a direct one.
        return match ($type) {
            '<f2' => self::halves($data, $count),
            '<f4' => array_values(unpack("g{$count}", $data) ?: []),
            '<f8' => array_values(unpack("e{$count}", $data) ?: []),
            '|i1' => array_values(unpack("c{$count}", $data) ?: []),
            '|u1' => array_values(unpack("C{$count}", $data) ?: []),
            '<i2' => self::signed(array_values(unpack("v{$count}", $data) ?: []), 16),
            '<u2' => array_values(unpack("v{$count}", $data) ?: []),
            '<i4' => self::signed(array_values(unpack("V{$count}", $data) ?: []), 32),
            '<u4' => array_values(unpack("V{$count}", $data) ?: []),
            '<i8' => array_values(unpack("q{$count}", $data) ?: []),
            default => [],
        };
    }

    /**
     * Reinterpret unsigned integers of `$bits` width as two's-complement signed.
     *
     * `unpack()` only offers little-endian codes for *unsigned* 16- and 32-bit
     * integers (`v` and `V`); the signed codes use machine byte order, which
     * would silently produce garbage on a big-endian host.
     *
     * @param  list<int>  $values
     * @return list<int>
     */
    private static function signed(array $values, int $bits): array
    {
        $limit = 1 << ($bits - 1);
        $wrap = 1 << $bits;

        return array_map(static fn (int $value): int => $value >= $limit ? $value - $wrap : $value, $values);
    }

    /**
     * Decode IEEE 754 binary16 values, which PHP cannot unpack natively.
     *
     * @return list<float>
     */
    private static function halves(string $data, int $count): array
    {
        /** @var list<int> $bits */
        $bits = array_values(unpack("v{$count}", $data) ?: []);
        $values = [];

        foreach ($bits as $half) {
            $exponent = ($half >> 10) & 0x1F;
            $fraction = $half & 0x3FF;

            // The cast is load-bearing: `(1 + 0/1024) * 2 ** 0` is integer
            // arithmetic in PHP, so a plain 1.0 would decode to int(1) and
            // stop matching what the JSON path produces for a float dtype.
            $magnitude = (float) match (true) {
                // Subnormals (and zero) have no implicit leading one.
                $exponent === 0 => $fraction === 0 ? 0.0 : $fraction / 1024 * 2 ** -14,
                $exponent === 0x1F => $fraction === 0 ? INF : NAN,
                default => (1 + $fraction / 1024) * 2 ** ($exponent - 15),
            };

            // Negative zero matters: it is a legitimate value and `-0.0` is not
            // the same float as `0.0` to a strict comparison.
            $values[] = ($half >> 15) === 1 ? -$magnitude : $magnitude;
        }

        return $values;
    }

    /**
     * Nest a flat list to match `$shape`. One dimension is already flat; the
     * multivector case is `[tokens, dims]`.
     *
     * @param  list<float>  $values
     * @param  list<int>  $shape
     * @return list<mixed>
     */
    private static function reshape(array $values, array $shape): array
    {
        if (count($shape) <= 1) {
            return $values;
        }

        $rows = array_shift($shape);
        $stride = (int) array_product($shape);

        $nested = [];

        for ($i = 0; $i < $rows; $i++) {
            $nested[] = self::reshape(array_slice($values, $i * $stride, $stride), $shape);
        }

        return $nested;
    }
}
