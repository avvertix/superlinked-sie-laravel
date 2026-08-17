<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Sie\Client\Exceptions\ServerException;

/**
 * Narrows a decoded response member to a list of objects.
 *
 * A decoded body is `array<mixed>`, so every `array_map(X::fromArray(...), …)`
 * over one of its members starts from an unknown shape. Left implicit, a
 * malformed member surfaces as a `TypeError` thrown from inside a mapper,
 * naming a parameter rather than the field that was wrong.
 *
 * Reindexing matters as much as the check: results are read positionally —
 * one **Result** per **Input**, in order — so a member that decoded to a
 * non-sequential array has to become a list before anything indexes into it.
 */
final class WireList
{
    /**
     * @param  string  $field  The member's name on the wire, used in the error.
     * @return list<array<string, mixed>>
     */
    public static function of(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new ServerException(
                sprintf("Malformed SIE response: expected '%s' to be a list of objects, got %s.", $field, get_debug_type($value)),
                errorCode: 'MALFORMED_RESPONSE',
            );
        }

        $items = [];

        foreach (array_values($value) as $index => $item) {
            if (! is_array($item)) {
                throw new ServerException(
                    sprintf(
                        "Malformed SIE response: expected every element of '%s' to be an object, got %s at index %d.",
                        $field,
                        get_debug_type($item),
                        $index,
                    ),
                    errorCode: 'MALFORMED_RESPONSE',
                );
            }

            $items[] = $item;
        }

        return $items;
    }
}
