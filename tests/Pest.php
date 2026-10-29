<?php

declare(strict_types=1);

use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Reporting\AccountBalance;
use Noria\Ledger\Session;
use Noria\Ledger\Tests\TestCase;

uses(TestCase::class)->in('Integration');

/** @param array<string, mixed> $changes */
function deferredTaxConfiguration(Session $ledger, string $book, string $version = '1', array $changes = [], string $validFrom = '0001-01-01', ?string $validUntil = null): string
{
    return $ledger->configurations->install($book, new Pack('deferred-tax-example', $version, validFrom: $validFrom, validUntil: $validUntil, accounts: [
        ['code' => '1800', 'name' => 'Deferred tax asset', 'type' => 'asset'],
        ['code' => '2800', 'name' => 'Deferred tax liability', 'type' => 'liability'],
        ['code' => '5400', 'name' => 'Deferred income tax', 'type' => 'expense'],
        ['code' => '3400', 'name' => 'Revaluation surplus tax', 'type' => 'equity'],
        ['code' => '3500', 'name' => 'Opening deferred tax equity', 'type' => 'equity'],
        ['code' => '3510', 'name' => 'Share premium tax', 'type' => 'equity'],
        ['code' => '3520', 'name' => 'Restated retained earnings tax', 'type' => 'equity'],
    ], roles: ['tax.deferred.asset' => '1800', 'tax.deferred.liability' => '2800', 'tax.income.deferred' => '5400', 'tax.surplus' => '3400', 'tax.opening' => '3500',
        'tax.equity' => '3510', 'tax.restated' => '3520'],
        settings: ['deferred_tax' => ['version' => 'reviewed:'.$version, 'rates' => ['use' => '0.30'], 'rounding' => 'half_even', 'separate_reviewer' => false,
            'asset_classes' => ['equipment' => [['from' => '2026-01-01', 'until' => '2026-12-31', 'method' => 'straight_line', 'rate' => '0.125', 'initial_rate' => '0.10']]],
            'attributions' => ['profit' => 'tax.income.deferred', 'oci_non_recyclable' => 'tax.surplus', 'equity_contribution' => 'tax.equity', 'opening_adjustment' => 'tax.restated', 'opening' => 'tax.opening'], ...$changes]]));
}

/** @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function deferredTaxInputs(array $changes = []): array
{
    return ['kind' => 'temporary_asset', 'carrying_minor' => '100000', 'tax_base_minor' => '50000', 'recognize_minor' => '0',
        'recognition_reference' => 'Reviewed recoverability and exemptions', 'exempt' => false, 'recovery_manner' => 'use', 'basis_reference' => 'Reviewed tax bases', 'opening' => false,
        'attributions' => [['category' => 'profit', 'amount_minor' => '15000', 'reference' => 'Source profit difference']], ...$changes];
}

function postDeferredTax(Session $ledger, string $book, string $id, string $key, string $date): string
{
    $ledger->incomeTax->reviewDeferred($book, $id, $key.':approve', $date, true, 'Approve deferred tax', ['reference' => 'Reviewed working paper']);

    return $ledger->incomeTax->postDeferred($book, $id, $key.':post', $date, 'Recognize deferred tax');
}

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
