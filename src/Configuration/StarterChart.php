<?php

declare(strict_types=1);

namespace Noria\Ledger\Configuration;

use Noria\Ledger\Reporting\StatementDefinition;

final class StarterChart
{
    public const NAME = 'ledger.starter';

    public const VERSION = '1';

    public static function pack(): Pack
    {
        $accounts = [
            ['code' => '1', 'name' => 'Assets', 'type' => 'asset', 'group' => true],
            ['code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'cash'],
            ['code' => '1010', 'name' => 'Cash on hand', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'cash'],
            ['code' => '1100', 'name' => 'Trade receivables', 'type' => 'asset', 'parent' => '1', 'control' => true, 'cash_flow' => 'operating'],
            ['code' => '1190', 'name' => 'Allowance for credit losses', 'type' => 'asset', 'parent' => '1', 'normal_side' => 'credit', 'cash_flow' => 'operating'],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'operating'],
            ['code' => '1300', 'name' => 'Input VAT', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'operating'],
            ['code' => '1310', 'name' => 'Withholding tax receivable', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'operating'],
            ['code' => '1320', 'name' => 'Import VAT pending claim', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'operating'],
            ['code' => '1400', 'name' => 'Prepayments', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'operating'],
            ['code' => '1500', 'name' => 'Property, plant and equipment', 'type' => 'asset', 'parent' => '1', 'cash_flow' => 'investing'],
            ['code' => '1590', 'name' => 'Accumulated depreciation', 'type' => 'asset', 'parent' => '1', 'normal_side' => 'credit', 'cash_flow' => 'investing'],
            ['code' => '1591', 'name' => 'Accumulated impairment', 'type' => 'asset', 'parent' => '1', 'normal_side' => 'credit', 'cash_flow' => 'investing'],
            ['code' => '2', 'name' => 'Liabilities', 'type' => 'liability', 'group' => true],
            ['code' => '2000', 'name' => 'Trade payables', 'type' => 'liability', 'parent' => '2', 'control' => true, 'cash_flow' => 'operating'],
            ['code' => '2100', 'name' => 'Output VAT', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'operating'],
            ['code' => '2110', 'name' => 'Withholding tax payable', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'operating'],
            ['code' => '2120', 'name' => 'Import VAT payable', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'operating'],
            ['code' => '2200', 'name' => 'Accruals', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'operating'],
            ['code' => '2300', 'name' => 'Deferred income', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'operating'],
            ['code' => '2400', 'name' => 'Customer deposits', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'operating'],
            ['code' => '2500', 'name' => 'Borrowings', 'type' => 'liability', 'parent' => '2', 'cash_flow' => 'financing'],
            ['code' => '3', 'name' => 'Equity', 'type' => 'equity', 'group' => true],
            ['code' => '3000', 'name' => 'Share capital', 'type' => 'equity', 'parent' => '3', 'cash_flow' => 'financing'],
            ['code' => '3100', 'name' => 'Retained earnings', 'type' => 'equity', 'parent' => '3', 'cash_flow' => 'financing'],
            ['code' => '3200', 'name' => 'Revaluation surplus', 'type' => 'equity', 'parent' => '3'],
            ['code' => '4', 'name' => 'Income', 'type' => 'income', 'group' => true],
            ['code' => '4000', 'name' => 'Sales', 'type' => 'income', 'parent' => '4', 'cash_flow' => 'operating'],
            ['code' => '4100', 'name' => 'Service income', 'type' => 'income', 'parent' => '4', 'cash_flow' => 'operating'],
            ['code' => '4800', 'name' => 'Other income', 'type' => 'income', 'parent' => '4', 'cash_flow' => 'operating'],
            ['code' => '4900', 'name' => 'Exchange gains', 'type' => 'income', 'parent' => '4', 'cash_flow' => 'operating'],
            ['code' => '4910', 'name' => 'Gains on disposal', 'type' => 'income', 'parent' => '4', 'cash_flow' => 'investing'],
            ['code' => '5', 'name' => 'Expenses', 'type' => 'expense', 'group' => true],
            ['code' => '5000', 'name' => 'Cost of sales', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5100', 'name' => 'Salaries and wages', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5200', 'name' => 'Rent', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5300', 'name' => 'Utilities', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5400', 'name' => 'Depreciation', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5410', 'name' => 'Impairment losses', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5500', 'name' => 'Bank charges', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5600', 'name' => 'Credit losses', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5700', 'name' => 'Exchange losses', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5710', 'name' => 'Losses on disposal', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'investing'],
            ['code' => '5800', 'name' => 'Rounding differences', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
            ['code' => '5900', 'name' => 'Other expenses', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
        ];
        $roles = ['cash' => '1000', 'petty_cash' => '1010', 'receivable' => '1100', 'payable' => '2000', 'inventory' => '1200',
            'tax.input' => '1300', 'tax.output' => '2100', 'tax.withholding.receivable' => '1310', 'tax.withholding.payable' => '2110',
            'tax.reverse_charge.input' => '1320', 'tax.reverse_charge.payable' => '2120', 'tax.irrecoverable' => '5900',
            'revenue' => '4000', 'service_revenue' => '4100', 'cost_of_sales' => '5000', 'accruals' => '2200', 'deferred_income' => '2300',
            'customer_deposits' => '2400', 'borrowings' => '2500', 'share_capital' => '3000', 'retained_earnings' => '3100', 'revaluation_surplus' => '3200',
            'asset.cost' => '1500', 'asset.depreciation' => '1590', 'asset.impairment' => '1591', 'asset.depreciation_expense' => '5400',
            'asset.impairment_expense' => '5410', 'asset.gain' => '4910', 'asset.loss' => '5710', 'credit_loss.allowance' => '1190',
            'credit_loss.expense' => '5600', 'fx.gain' => '4900', 'fx.loss' => '5700', 'rounding' => '5800', 'bank_charges' => '5500'];
        $movement = ['include_closing' => false, 'basis' => 'movement'];

        return new Pack(self::NAME, self::VERSION, $accounts, $roles, statements: [
            new StatementDefinition('Income statement', self::VERSION, [
                'income' => ['label' => 'Income', 'accounts' => ['4'], 'sign' => 'credit', ...$movement],
                'expenses' => ['label' => 'Expenses', 'accounts' => ['5'], ...$movement],
                'profit' => ['label' => 'Profit for the period', 'terms' => ['income' => '1', 'expenses' => '-1']],
            ], coverageTypes: ['income', 'expense']),
            new StatementDefinition('Statement of financial position', self::VERSION, [
                'assets' => ['label' => 'Total assets', 'accounts' => ['1'], 'basis' => 'closing'],
                'liabilities' => ['label' => 'Total liabilities', 'accounts' => ['2'], 'sign' => 'credit', 'basis' => 'closing'],
                'equity' => ['label' => 'Equity', 'accounts' => ['3'], 'sign' => 'credit', 'basis' => 'closing'],
                'earnings' => ['label' => 'Unclosed earnings', 'accounts' => ['4', '5'], 'sign' => 'credit', 'basis' => 'closing'],
            ], coverageTypes: ['asset', 'liability', 'equity']),
        ]);
    }
}
