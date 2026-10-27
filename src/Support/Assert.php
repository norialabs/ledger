<?php

declare(strict_types=1);

namespace Noria\Ledger\Support;

use DateTimeImmutable;
use Noria\Ledger\Exceptions\LedgerException;

final class Assert
{
    public static function that(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new LedgerException($message);
        }
    }

    public static function uuid(string $value): string
    {
        self::that(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1, 'Expected a lowercase UUID.');

        return $value;
    }

    public static function text(string $value, int $max = 256): string
    {
        self::that(trim($value) !== '' && strlen($value) <= $max && ! str_contains($value, "\0"), 'Text is empty, too long, or contains a null byte.');

        return $value;
    }

    public static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        self::that($date !== false && $date->format('Y-m-d') === $value && $value >= '0001-01-01', 'Expected a valid YYYY-MM-DD date.');

        return $value;
    }

    public static function decimal(string $value): string
    {
        self::that(strlen($value) <= 128 && preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1, 'Expected a plain decimal string.');

        return $value;
    }
}
