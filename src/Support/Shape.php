<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Support;

/**
 * Narrowing for the values PHP can only describe as `mixed`: a decoded JSON
 * payload, a session bag, and host configuration.
 *
 * Every one of those is a string-keyed map by contract and `mixed` to the type
 * system, so each boundary that reads one has to restate what it accepts. These
 * helpers state it once. Nothing here coerces a value into a shape the payload
 * did not have: a non-array becomes an empty map, and everything else is kept
 * exactly as it arrived.
 *
 * @internal
 */
final class Shape
{
    /**
     * The value as a string-keyed map, or an empty map when it is not an array.
     *
     * Keys are written back as strings because that is what the shape promises.
     * PHP re-canonicalizes integer-like string keys on write, so the entries and
     * every lookup against them are unchanged.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * The value as a list of maps, with non-array rows dropped.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (is_array($row)) {
                $rows[] = self::map($row);
            }
        }

        return $rows;
    }
}
