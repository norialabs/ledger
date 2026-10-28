<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

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
}
