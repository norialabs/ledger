<?php

declare(strict_types=1);

namespace Noria\Ledger\Consolidation;

use Noria\Ledger\Support\Row;
use stdClass;

/**
 * @phpstan-type Amount array{amount: string, currency: string, scale: int}
 * @phpstan-type Snapshot array{book_id: string, effective_on: string, through_sequence: string}
 * @phpstan-type Currency array{code: string, scale: int}
 * @phpstan-type Rate array{from: Currency, to: Currency, rate: string, as_of: string, source: string, rounding: string}
 * @phpstan-type FairValueData array{account_id: string, adjustment: Amount, tax_base_adjustment: Amount, tax_rate: string, deferred_tax_asset_account_id: ?string, deferred_tax_liability_account_id: ?string, recognize_deferred_tax_asset: bool, movements: list<array{effective_on: string, amount: Amount, counter_account_id: string, tax_base_delta?: Amount, tax_rate?: string, recognize_deferred_tax_asset?: bool, rate?: Rate}>}
 * @phpstan-type InvestmentData array{investee_book_id: string, investment_account_id: string, goodwill_account_id: string, bargain_account_id: string, acquisition_snapshot: Snapshot, consideration: Amount, acquisition_rate: ?Rate, fair_values?: list<FairValueData>, measured_nci?: ?Amount, goodwill_impairments?: array<string, Amount>, impairment_account_id?: ?string}
 * @phpstan-type Inputs array{target: Snapshot, members: list<array{snapshot: Snapshot, account_map: array<string, string>, closing_rate: ?Rate, income_rate: ?Rate, historical_equity_rates: array<string, Rate>, historical_equity_amounts: array<string, Amount>, ownership_fraction?: string}>, starts_on: string, translation_reserve_id: string, rounding_reserve_id: string, nci_account_id: ?string, retained_earnings_account_id: ?string, investments: ?list<InvestmentData>, policy_version: string}
 * @phpstan-type Result array{trial_balance: list<array{account_id: string, code: string, name: string, type: string, closing: Amount}>, income_statement: array{income: Amount, expenses: Amount, profit: Amount}, balance_sheet: array{assets: Amount, liabilities: Amount, equity: Amount, unclosed_earnings: Amount, difference: Amount}, translation_adjustment: Amount, rounding_adjustment: Amount, non_controlling_interest: Amount, goodwill: array<string, Amount>, goodwill_impairment: array<string, Amount>, bargain_purchase_gain: array<string, Amount>, profit_attribution: array{parent: Amount, non_controlling_interest: Amount}, acquisition_net_assets: array<string, Amount>, fair_value_adjustments: array<string, list<array{account_id: string, carrying_adjustment: Amount, deferred_tax_adjustment: Amount, deferred_tax_account_id: ?string}>>, nci_measurement: array<string, string>, source_snapshots: list<Snapshot>, elimination_snapshot: Snapshot, policy_version: string}
 */
final class ConsolidatedReport
{
    /** @return Result */
    public static function result(stdClass $r): array
    {
        $income = Row::object($r->income_statement);
        $balance = Row::object($r->balance_sheet);
        $profit = Row::object($r->profit_attribution);

        return ['trial_balance' => Row::objects($r->trial_balance, fn (stdClass $r): array => ['account_id' => Row::text($r, 'account_id'), 'code' => Row::text($r, 'code'), 'name' => Row::text($r, 'name'), 'type' => Row::text($r, 'type'), 'closing' => self::amount($r->closing)]),
            'income_statement' => ['income' => self::amount($income->income), 'expenses' => self::amount($income->expenses), 'profit' => self::amount($income->profit)],
            'balance_sheet' => ['assets' => self::amount($balance->assets), 'liabilities' => self::amount($balance->liabilities), 'equity' => self::amount($balance->equity), 'unclosed_earnings' => self::amount($balance->unclosed_earnings), 'difference' => self::amount($balance->difference)],
            'translation_adjustment' => self::amount($r->translation_adjustment), 'rounding_adjustment' => self::amount($r->rounding_adjustment),
            'non_controlling_interest' => self::amount($r->non_controlling_interest), 'goodwill' => Row::map($r->goodwill, self::amount(...)),
            'goodwill_impairment' => Row::map($r->goodwill_impairment, self::amount(...)), 'bargain_purchase_gain' => Row::map($r->bargain_purchase_gain, self::amount(...)),
            'profit_attribution' => ['parent' => self::amount($profit->parent), 'non_controlling_interest' => self::amount($profit->non_controlling_interest)],
            'acquisition_net_assets' => Row::map($r->acquisition_net_assets, self::amount(...)),
            'fair_value_adjustments' => Row::map($r->fair_value_adjustments, fn (mixed $v): array => Row::objects($v, fn (stdClass $r): array => ['account_id' => Row::text($r, 'account_id'), 'carrying_adjustment' => self::amount($r->carrying_adjustment), 'deferred_tax_adjustment' => self::amount($r->deferred_tax_adjustment), 'deferred_tax_account_id' => Row::nullable($r, 'deferred_tax_account_id')])),
            'nci_measurement' => Row::stringMap($r->nci_measurement), 'source_snapshots' => Row::objects($r->source_snapshots, self::snapshot(...)),
            'elimination_snapshot' => self::snapshot(Row::object($r->elimination_snapshot)), 'policy_version' => Row::text($r, 'policy_version')];
    }

    /** @return Inputs */
    public static function inputs(stdClass $r): array
    {
        return ['target' => self::snapshot(Row::object($r->target)), 'members' => Row::objects($r->members, fn (stdClass $r): array => [
            'snapshot' => self::snapshot(Row::object($r->snapshot)), 'account_map' => Row::stringMap($r->account_map),
            'closing_rate' => self::rate($r->closing_rate), 'income_rate' => self::rate($r->income_rate),
            'historical_equity_rates' => Row::map($r->historical_equity_rates, self::requiredRate(...)),
            'historical_equity_amounts' => Row::map($r->historical_equity_amounts, self::amount(...)), ...(! isset($r->ownership_fraction) ? [] : ['ownership_fraction' => Row::text($r, 'ownership_fraction')])]),
            'starts_on' => Row::text($r, 'starts_on'), 'translation_reserve_id' => Row::text($r, 'translation_reserve_id'),
            'rounding_reserve_id' => Row::text($r, 'rounding_reserve_id'), 'nci_account_id' => Row::nullable($r, 'nci_account_id'),
            'retained_earnings_account_id' => Row::nullable($r, 'retained_earnings_account_id'),
            'investments' => $r->investments === null ? null : Row::objects($r->investments, self::investment(...)), 'policy_version' => Row::text($r, 'policy_version')];
    }

    /** @return Amount */
    public static function amount(mixed $value): array
    {
        $r = Row::object($value);

        return ['amount' => Row::text($r, 'amount'), 'currency' => Row::text($r, 'currency'), 'scale' => (int) Row::text($r, 'scale')];
    }

    /** @return Snapshot */
    private static function snapshot(stdClass $r): array
    {
        return ['book_id' => Row::text($r, 'book_id'), 'effective_on' => Row::text($r, 'effective_on'), 'through_sequence' => Row::text($r, 'through_sequence')];
    }

    /** @return ?Rate */
    private static function rate(mixed $v): ?array
    {
        return $v === null ? null : self::requiredRate($v);
    }

    /** @return Rate */
    private static function requiredRate(mixed $v): array
    {
        $r = Row::object($v);
        $from = Row::object($r->from);
        $to = Row::object($r->to);

        return ['from' => ['code' => Row::text($from, 'code'), 'scale' => (int) Row::text($from, 'scale')], 'to' => ['code' => Row::text($to, 'code'), 'scale' => (int) Row::text($to, 'scale')],
            'rate' => Row::text($r, 'rate'), 'as_of' => Row::text($r, 'as_of'), 'source' => Row::text($r, 'source'), 'rounding' => Row::text($r, 'rounding')];
    }

    /** @return InvestmentData */
    private static function investment(stdClass $r): array
    {
        $result = ['investee_book_id' => Row::text($r, 'investee_book_id'), 'investment_account_id' => Row::text($r, 'investment_account_id'), 'goodwill_account_id' => Row::text($r, 'goodwill_account_id'), 'bargain_account_id' => Row::text($r, 'bargain_account_id'),
            'acquisition_snapshot' => self::snapshot(Row::object($r->acquisition_snapshot)), 'consideration' => self::amount($r->consideration), 'acquisition_rate' => self::rate($r->acquisition_rate)];
        if (isset($r->fair_values)) {
            $result += ['fair_values' => Row::objects($r->fair_values, self::fairValue(...)), 'measured_nci' => $r->measured_nci === null ? null : self::amount($r->measured_nci)];
        }
        if (isset($r->goodwill_impairments)) {
            $result += ['goodwill_impairments' => Row::map($r->goodwill_impairments, self::amount(...)), 'impairment_account_id' => Row::nullable($r, 'impairment_account_id')];
        }

        return $result;
    }

    /** @return FairValueData */
    private static function fairValue(stdClass $r): array
    {
        return ['account_id' => Row::text($r, 'account_id'), 'adjustment' => self::amount($r->adjustment), 'tax_base_adjustment' => self::amount($r->tax_base_adjustment), 'tax_rate' => Row::text($r, 'tax_rate'),
            'deferred_tax_asset_account_id' => Row::nullable($r, 'deferred_tax_asset_account_id'), 'deferred_tax_liability_account_id' => Row::nullable($r, 'deferred_tax_liability_account_id'), 'recognize_deferred_tax_asset' => Row::boolean($r, 'recognize_deferred_tax_asset'),
            'movements' => Row::objects($r->movements, function (stdClass $r): array {
                $v = ['effective_on' => Row::text($r, 'effective_on'), 'amount' => self::amount($r->amount), 'counter_account_id' => Row::text($r, 'counter_account_id')];
                if (isset($r->tax_base_delta)) {
                    $v['tax_base_delta'] = self::amount($r->tax_base_delta);
                }
                if (isset($r->tax_rate)) {
                    $v['tax_rate'] = Row::text($r, 'tax_rate');
                }
                if (isset($r->recognize_deferred_tax_asset)) {
                    $v['recognize_deferred_tax_asset'] = Row::boolean($r, 'recognize_deferred_tax_asset');
                }
                if (isset($r->rate)) {
                    $v['rate'] = self::requiredRate($r->rate);
                }

                return $v;
            })];
    }
}
