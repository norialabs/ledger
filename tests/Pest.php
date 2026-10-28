<?php

declare(strict_types=1);

use Noria\Ledger\Reporting\AccountBalance;
use Noria\Ledger\Tests\TestCase;

uses(TestCase::class)->in('Integration');

/** @param list<AccountBalance> $balances
 * @return array<string, AccountBalance>
 */
function byAccount(array $balances): array
{
    $keyed = [];
    foreach ($balances as $balance) {
        $keyed[$balance->accountId] = $balance;
    }

    return $keyed;
}

function text(mixed $value): string
{
    if (! is_string($value)) {
        throw new UnexpectedValueException('Expected a string, got '.get_debug_type($value).'.');
    }

    return $value;
}

/** @return array<mixed> */
function items(mixed $value): array
{
    if (! is_array($value)) {
        throw new UnexpectedValueException('Expected an array, got '.get_debug_type($value).'.');
    }

    return $value;
}

function row(mixed $value): stdClass
{
    if (! $value instanceof stdClass) {
        throw new UnexpectedValueException('Expected a database row, got '.get_debug_type($value).'.');
    }

    return $value;
}
