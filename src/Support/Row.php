<?php

declare(strict_types=1);

namespace Noria\Ledger\Support;

use stdClass;

final class Row
{
    public static function text(stdClass $row, string $key): string
    {
        $value = $row->{$key} ?? null;
        Assert::that(is_string($value) || is_int($value), 'Missing or invalid database field: '.$key);

        return (string) $value;
    }

    public static function nullable(stdClass $row, string $key): ?string
    {
        return ($row->{$key} ?? null) === null ? null : self::text($row, $key);
    }

    public static function boolean(stdClass $row, string $key): bool
    {
        return in_array($row->{$key} ?? null, [true, 1, '1', 't'], true);
    }

    /** @return array<array-key, mixed> */
    public static function json(stdClass $row, string $key): array
    {
        $value = json_decode(self::text($row, $key), true, 512, JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    /** @return array<string, string> */
    public static function dimensions(stdClass $row, string $key = 'dimensions'): array
    {
        return self::stringMap(self::json($row, $key));
    }

    /** @return array<string, string> */
    public static function stringMap(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) || $value instanceof stdClass ? (array) $value : [] as $name => $text) {
            Assert::that(is_string($name) && is_string($text), 'Expected a string map.');
            $result[$name] = $text;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function jsonObject(stdClass $row, string $key): array
    {
        $result = [];
        foreach (self::json($row, $key) as $name => $value) {
            Assert::that(is_string($name), 'Expected named JSON object fields: '.$key);
            $result[$name] = $value;
        }

        return $result;
    }
}
