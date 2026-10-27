<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Support;

use stdClass;

final class Row
{
    public static function text(stdClass $row, string $key): string
    {
        $value = $row->{$key} ?? null;
        Assert::that(is_string($value) || is_int($value), 'Missing or invalid database field: '.$key);

        return is_string($value) || is_int($value) ? (string) $value : '';
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
}
