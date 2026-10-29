<?php

declare(strict_types=1);

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\AccountBalance;
use Noria\Ledger\Reporting\ReportingMovement;
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
 * @param  array<string, mixed>  $trade
 */
function incomeTaxConfiguration(Session $ledger, string $book, string $version = '1', array $changes = [], array $trade = [], string $validFrom = '0001-01-01'): string
{
    return $ledger->configurations->install($book, new Pack('income-tax-example', $version, validFrom: $validFrom, accounts: [
        ['code' => '2300', 'name' => 'Current income tax payable', 'type' => 'liability'],
        ['code' => '5300', 'name' => 'Current income tax expense', 'type' => 'expense'],
    ], roles: ['tax.income.current' => '5300', 'tax.income.payable' => '2300', 'profit.revenue' => '4000'], settings: ['income_tax' => [
        'version' => 'reviewed:'.$version, 'separate_reviewer' => false, 'loss_extensions' => [], 'sources' => ['trade' => [
            'rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => 5, 'loss_sources' => ['trade'], 'profit_roles' => ['profit.revenue'], ...$trade,
        ]], ...$changes,
    ]]));
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

/** @return array{retained: string, expense: string, asset: string, taxExpense: string, taxPayable: string,
 * decision: array{impracticable: bool, reference: string, explanation: string, earliest_comparative_on: string, application_from: string, current_starts_on: string},
 * opening: array{reference: string, tax_reference: string, postings: list<Posting>, tax: list<Posting>},
 * periods: non-empty-list<array{closure_id: string, reference: string, tax_reference: string, postings: list<Posting>, tax: list<Posting>}>}
 */
function retrospectivePolicyFixture(Session $ledger, string $book, Currency $currency, bool $withTax = false): array
{
    $retained = $ledger->catalog->account($book, '3100', 'Retained earnings', AccountType::Equity);
    $expense = $ledger->catalog->account($book, '5100', 'Policy expense', AccountType::Expense);
    $asset = $ledger->catalog->account($book, '1500', 'Unregistered policy asset', AccountType::Asset);
    $income = $ledger->catalog->account($book, '4100', 'Comparative income', AccountType::Income);
    $taxExpense = $ledger->catalog->account($book, '5900', 'Reviewed tax effect', AccountType::Expense);
    $taxPayable = $ledger->catalog->account($book, '2500', 'Reviewed current tax payable', AccountType::Liability);
    $ledger->periods->open($book, '2023', '2023-01-01', '2023-12-31');
    $ledger->journals->post(new JournalRequest($book, 'opening', '2023-01-01', 'Opening balance', [
        new Posting($asset, Side::Debit, Money::of('100', $currency)), new Posting($retained, Side::Credit, Money::of('100', $currency),
            reportingMovement: new ReportingMovement('opening_balance', 'opening:1', 'Opening transition')),
    ]));
    $ledger->yearEnd->close($book, 'close-2023', '2023-01-01', '2023-12-31', $retained, 'Close');
    $periods = [];
    foreach (['2024', '2025'] as $year) {
        $ledger->periods->open($book, $year, $year.'-01-01', $year.'-12-31');
        $ledger->journals->post(new JournalRequest($book, 'activity-'.$year, $year.'-06-30', 'Activity', [
            new Posting($asset, Side::Debit, Money::of('100', $currency)), new Posting($income, Side::Credit, Money::of('100', $currency)),
        ]));
        $closure = $ledger->yearEnd->close($book, 'close-'.$year, $year.'-01-01', $year.'-12-31', $retained, 'Close');
        $periods[] = ['closure_id' => $closure, 'reference' => 'As-if policy expense '.$year, 'tax_reference' => $withTax ? 'Reviewed tax benefit '.$year : 'Reviewed: no tax effect',
            'postings' => [new Posting($expense, Side::Debit, Money::of('10', $currency)), new Posting($asset, Side::Credit, Money::of('10', $currency))],
            'tax' => $withTax ? [new Posting($taxPayable, Side::Debit, Money::of('3', $currency)), new Posting($taxExpense, Side::Credit, Money::of('3', $currency))] : []];
    }

    return ['retained' => $retained, 'expense' => $expense, 'asset' => $asset, 'taxExpense' => $taxExpense, 'taxPayable' => $taxPayable,
        'decision' => ['impracticable' => false, 'reference' => 'IAS8-review:1', 'explanation' => 'Full retrospective application is practicable',
            'earliest_comparative_on' => '2024-01-01', 'application_from' => '2024-01-01', 'current_starts_on' => '2026-01-01'],
        'opening' => ['reference' => 'Earliest comparative cumulative adjustment', 'tax_reference' => $withTax ? 'Reviewed opening tax benefit' : 'Reviewed: no tax effect',
            'postings' => [new Posting($retained, Side::Debit, Money::of('30', $currency)), new Posting($asset, Side::Credit, Money::of('30', $currency))],
            'tax' => $withTax ? [new Posting($taxPayable, Side::Debit, Money::of('9', $currency)), new Posting($retained, Side::Credit, Money::of('9', $currency))] : []],
        'periods' => $periods];
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
