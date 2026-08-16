<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/** Converts an item's `images`/`document` inputs to the JSON wire shape before sending. Shared by encode/score/extract. */
final class ItemWireConverter
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function convert(array $item): array
    {
        if (isset($item['images']) && is_array($item['images'])) {
            $item['images'] = array_map(MediaInput::image(...), $item['images']);
        }

        if (isset($item['document'])) {
            $item['document'] = MediaInput::document($item['document']);
        }

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function convertAll(array $items): array
    {
        return array_map(self::convert(...), $items);
    }
}
