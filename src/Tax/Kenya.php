<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Brick\Math\BigInteger;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Support\Assert;

final class Kenya
{
    /** @param array<string, string> $roles */
    public static function vatPack(string $version, string $validFrom, string $validUntil, string $policyReference, array $roles): Pack
    {
        Assert::text($policyReference, 128);
        foreach (['cash', 'receivable', 'payable', 'tax.output', 'tax.input', 'tax.irrecoverable', 'tax.reverse_charge.input',
            'tax.reverse_charge.payable', 'tax.withholding.receivable', 'tax.withholding.payable'] as $role) {
            Assert::that(isset($roles[$role]), 'Kenya VAT pack requires an explicit chart role: '.$role);
        }
        Assert::that(count(array_unique(array_values($roles))) === count($roles), 'Kenya VAT roles require separate posting accounts.');
        $movement = ['include_closing' => false, 'basis' => 'movement'];

        return new Pack('ke.vat', $version, roles: $roles, taxRules: [
            new TaxRule('KE-VAT-STANDARD', '0.16', $validFrom, $validUntil, $policyReference),
            new TaxRule('KE-VAT-ZERO', '0', $validFrom, $validUntil, $policyReference),
            new TaxRule('KE-WH-VAT', '0.02', $validFrom, $validUntil, $policyReference),
        ], statements: [new StatementDefinition('Kenya VAT ledger worksheet', $version, [
            'output' => ['label' => 'Domestic output VAT', 'accounts' => [$roles['tax.output']], 'sign' => 'credit', ...$movement],
            'input' => ['label' => 'Eligible input VAT', 'accounts' => [$roles['tax.input']], ...$movement],
            'withheld' => ['label' => 'Withholding VAT credits', 'accounts' => [$roles['tax.withholding.receivable']], ...$movement],
            'net_movement' => ['label' => 'VAT movement before opening credits and other adjustments', 'terms' => ['output' => '1', 'input' => '-1', 'withheld' => '-1']],
            'import_assessed' => ['label' => 'Separately assessed import VAT', 'accounts' => [$roles['tax.reverse_charge.payable']], 'sign' => 'credit', ...$movement],
            'import_pending' => ['label' => 'Import VAT pending payment and claim review', 'accounts' => [$roles['tax.reverse_charge.input']], 'basis' => 'closing'],
            'agent_withheld' => ['label' => 'VAT withheld as appointed agent', 'accounts' => [$roles['tax.withholding.payable']], 'sign' => 'credit', ...$movement],
        ])], settings: ['policy_reference' => $policyReference, 'source_checked_on' => '2026-10-27',
            'sources' => ['https://www.kra.go.ke/individual/filing-paying/types-of-taxes/value-added-tax'],
            'tax_categories' => ['standard' => 'KE-VAT-STANDARD', 'zero_rated' => 'KE-VAT-ZERO', 'withholding_vat' => 'KE-WH-VAT'],
            'tax_treatment_codes' => ['reverse_charge' => ['KE-VAT-STANDARD'], 'withholding.payment' => ['KE-WH-VAT'], 'withholding.receipt' => ['KE-WH-VAT']],
            'tax_returns' => ['vat' => self::vatReturnPolicy()],
            'exempt_is_zero_rated' => false, 'import_input_requires_payment_and_claim_review' => true,
            'worksheet_includes_opening_credits' => false, 'statutory_submission_format' => null],
            country: 'KE', validFrom: $validFrom, validUntil: $validUntil);
    }

    /** @return array<string, mixed> */
    public static function vatReturnPolicy(): array
    {
        $accounts = ['tax.output', 'tax.input', 'tax.reverse_charge.input', 'tax.reverse_charge.payable', 'tax.withholding.receivable', 'tax.withholding.payable'];

        return ['period' => 'calendar_month', 'accounts' => $accounts,
            'codes' => ['KE-VAT-STANDARD' => ['label' => 'Standard-rated'], 'KE-VAT-ZERO' => ['label' => 'Zero-rated'], 'KE-WH-VAT' => ['label' => 'Withholding VAT']],
            'payable_terms' => ['output_tax' => '1', 'input_claimed' => '-1', 'withholding_claimed' => '-1'],
            'reconciliations' => [
                'settlement' => ['roles' => ['tax.output', 'tax.reverse_charge.payable', 'tax.withholding.payable'], 'coefficient' => '0'],
                'reclassification' => ['roles' => $accounts, 'coefficient' => '0'],
                'opening' => ['roles' => $accounts, 'coefficient' => '0'],
                'output_adjustment' => ['roles' => ['tax.output'], 'coefficient' => '-1'],
                'input_adjustment' => ['roles' => ['tax.input', 'tax.withholding.receivable'], 'coefficient' => '-1'],
            ]];
    }

    public const VAT3_SECTIONS = ['B' => 'General rated sales', 'C' => 'Other rated sales', 'D' => 'Zero rated sales', 'E' => 'Exempt sales', 'F' => 'Purchases and input tax'];

    /** @param iterable<object> $register
     * @param  array<string, string>  $partyPins
     * @param  array<string, string>  $salesSections
     * @return array<string, array{label: string, with_pin: array{base_minor: string, tax_minor: string, documents: int}, without_pin: array{base_minor: string, tax_minor: string, documents: int}}>
     */
    public static function vat3Sections(iterable $register, array $partyPins = [], array $salesSections = ['KE-VAT-STANDARD' => 'B', 'KE-VAT-ZERO' => 'D', '' => 'E']): array
    {
        foreach ($salesSections as $section) {
            Assert::that(in_array($section, ['B', 'C', 'D', 'E'], true), 'Sales tax codes map to VAT3 sections B, C, D or E.');
        }
        $empty = ['base_minor' => BigInteger::zero(), 'tax_minor' => BigInteger::zero(), 'documents' => []];
        $totals = array_map(fn (): array => ['with_pin' => $empty, 'without_pin' => $empty], self::VAT3_SECTIONS);
        foreach ($register as $row) {
            $kind = $row->kind ?? null;
            $code = $row->tax_code ?? null;
            if (! is_string($code) || ! isset($salesSections[$code]) || ! in_array($kind, ['output', 'input'], true)) {
                continue;
            }
            $section = $kind === 'output' ? $salesSections[$code] : 'F';
            $parties = is_string($row->party_ids ?? null) ? json_decode($row->party_ids, true, 4, JSON_THROW_ON_ERROR) : [];
            $pinned = is_array($parties) && array_filter($parties, fn (mixed $party): bool => is_string($party) && isset($partyPins[$party])) !== [];
            $bucket = &$totals[$section][$pinned ? 'with_pin' : 'without_pin'];
            $bucket['base_minor'] = $bucket['base_minor']->plus(self::minor($row->base_minor ?? null));
            $bucket['tax_minor'] = $bucket['tax_minor']->plus(self::minor($row->tax_minor ?? null));
            $origin = $row->origin_id ?? null;
            $bucket['documents'][is_string($origin) ? $origin : ''] = true;
            unset($bucket);
        }
        $result = [];
        foreach ($totals as $section => $groups) {
            $result[$section] = ['label' => self::VAT3_SECTIONS[$section], 'with_pin' => self::vat3Group($groups['with_pin']), 'without_pin' => self::vat3Group($groups['without_pin'])];
        }

        return $result;
    }

    /** @param array{base_minor: BigInteger, tax_minor: BigInteger, documents: array<string, true>} $group
     * @return array{base_minor: string, tax_minor: string, documents: int}
     */
    private static function vat3Group(array $group): array
    {
        return ['base_minor' => (string) $group['base_minor'], 'tax_minor' => (string) $group['tax_minor'], 'documents' => count($group['documents'])];
    }

    private static function minor(mixed $value): BigInteger
    {
        Assert::that(is_string($value) || is_int($value), 'Tax register amounts are integer minor units.');

        return BigInteger::of((string) $value);
    }
}
