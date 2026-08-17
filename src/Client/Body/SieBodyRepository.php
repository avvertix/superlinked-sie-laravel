<?php

declare(strict_types=1);

namespace Sie\Client\Body;

use MessagePack\Packer;
use MessagePack\PackOptions;
use MessagePack\Type\Bin;
use Saloon\Repositories\Body\ArrayBodyRepository;
use Saloon\Traits\Body\CreatesStreamFromString;
use Sie\Client\Support\Binary;
use Sie\Client\Support\WireFormat;
use Stringable;

/**
 * A request body that can serialise itself as either JSON or msgpack.
 *
 * Shaped after Saloon's own `JsonBodyRepository`: an array repository that is
 * `Stringable`, with `toStream()` coming from `CreatesStreamFromString`. The
 * format arrives from the connection during plugin boot — see
 * {@see HasSieBody} — which is why it is mutable rather than constructor-set.
 */
class SieBodyRepository extends ArrayBodyRepository implements Stringable
{
    use CreatesStreamFromString;

    private WireFormat $format = WireFormat::Json;

    /**
     * @return $this
     */
    public function useFormat(WireFormat $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function format(): WireFormat
    {
        return $this->format;
    }

    public function contentType(): string
    {
        return $this->format->contentType();
    }

    public function __toString(): string
    {
        $data = $this->all();

        if ($this->format === WireFormat::Msgpack) {
            // FORCE_STR keeps ordinary strings as msgpack strings; the only
            // things that become bins are the ones explicitly marked Binary.
            return (new Packer(PackOptions::FORCE_STR))->pack(self::forMsgpack($data));
        }

        // JSON_THROW_ON_ERROR means this never returns false; an unencodable
        // body is a bug in the caller and should surface as one.
        return json_encode(self::forJson($data), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function forMsgpack(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Binary) {
                $data[$key] = new Bin($value->bytes);
            } elseif (is_array($value)) {
                $data[$key] = self::forMsgpack($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function forJson(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof Binary) {
                $data[$key] = base64_encode($value->bytes);
            } elseif (is_array($value)) {
                $data[$key] = self::forJson($value);
            }
        }

        return $data;
    }
}
