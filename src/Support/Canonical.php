<?php

declare(strict_types=1);

namespace Noria\Ledger\Support;

use JsonSerializable;

final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        Assert::that(! is_float($value) && (! is_object($value)) && ! is_resource($value), 'Only exact JSON values are accepted.');

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}
