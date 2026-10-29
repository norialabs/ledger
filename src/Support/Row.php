<?php

declare(strict_types=1);

namespace Noria\Ledger\Support;

use stdClass;

final class Row
{
    public static function object(mixed $value): stdClass
    {
        Assert::that($value instanceof stdClass || is_array($value), 'Expected a JSON object.');

        return (object) $value;
    }

    /** @template T
     * @param  callable(stdClass): T  $decode
     * @return list<T>
     */
    public static function objects(mixed $value, callable $decode): array
    {
        Assert::that(is_array($value) && array_is_list($value), 'Expected a JSON object list.');

        return array_map(fn (mixed $item) => $decode(self::object($item)), $value);
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        Assert::that(is_array($value) && array_is_list($value), 'Expected a string list.');
        $result = [];
        foreach ($value as $item) {
            Assert::that(is_string($item), 'Expected a string list item.');
            $result[] = $item;
        }

        return $result;
    }

    /** @template T
     * @param  callable(mixed): T  $decode
     * @return array<string, T>
     */
    public static function map(mixed $value, callable $decode): array
    {
        Assert::that(is_array($value) || $value instanceof stdClass, 'Expected a JSON map.');
        $result = [];
        foreach ((array) $value as $key => $item) {
            Assert::that(is_string($key), 'Expected a named JSON map key.');
            $result[$key] = $decode($item);
        }

        return $result;
    }

    public static function text(stdClass $row, string $key): string
    {
        $value = $row->{$key} ?? null;
        Assert::that(is_string($value) || is_int($value), 'Missing or invalid database field: '.$key);

        return (string) $value;
    }

    public static function nullable(stdClass $row, string $key): ?string
    {
        Assert::that(property_exists($row, $key), 'Missing database field: '.$key);

        return $row->{$key} === null ? null : self::text($row, $key);
    }

    public static function boolean(stdClass $row, string $key): bool
    {
        $value = $row->{$key} ?? null;
        Assert::that(in_array($value, [true, false, 1, 0, '1', '0', 't', 'f'], true), 'Missing or invalid database field: '.$key);

        return in_array($value, [true, 1, '1', 't'], true);
    }

    /** @return array<array-key, mixed> */
    public static function json(stdClass $row, string $key): array
    {
        $value = json_decode(self::text($row, $key), true, 512, JSON_THROW_ON_ERROR);
        Assert::that(is_array($value), 'Expected a JSON array or object: '.$key);

        return $value;
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
