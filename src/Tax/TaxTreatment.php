<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Brick\Math\BigRational;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Configurations;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;

final readonly class TaxTreatment implements PostingTreatment
{
    public function __construct(private ScopedDatabase $db, private Configurations $configurations) {}

    public function name(): string
    {
        return 'ledger.tax';
    }

    public function version(): string
    {
        return '1';
    }

    public function prepare(Scope $scope, TreatmentRequest $request, array $accounts): JournalRequest
    {
        $input = (object) $request->inputs;
        $operation = Row::text($input, 'operation');
        Assert::text(Row::text($input, 'basis_reference'), 256);
        Assert::text(Row::text($input, 'policy_version'), 128);
        $book = $this->db->find('ledger_books', $request->bookId);
        $currency = $this->db->currency(Row::text($book, 'currency_code'));
        $rounding = Rounding::from(Row::text($input, 'rounding'));
        $code = Row::text($input, 'tax_code');
        $allowed = $this->configurations->get($request->bookId, $request->configurationId, $request->effectiveOn)['definition']->settings['tax_treatment_codes'] ?? null;
        if ($allowed !== null) {
            Assert::that(is_array($allowed) && is_array($allowed[$operation] ?? null) && in_array($code, $allowed[$operation], true), 'Tax code is not configured for this treatment operation.');
        }
        $rule = null;
        foreach ($this->configurations->taxRules($request->bookId, $request->configurationId, $request->effectiveOn) as $candidate) {
            if ($candidate->code === $code) {
                $rule = $candidate;
            }
        }
        Assert::that($rule instanceof TaxRule && ! $rule->inclusive && ! $rule->compound, 'Tax treatment requires a configured exclusive noncompound rule.');
        $base = Money::of(Row::text($input, 'tax_base'), $currency);
        Assert::that($base->minor->isPositive(), 'Tax base must be positive.');
        $tax = (new TaxCalculator)->calculate($base->decimal(), '1', $currency, $request->effectiveOn, [$rule], $rounding)->taxes[$code];
        Assert::that($tax->minor->isPositive(), 'Zero tax does not create a tax-treatment journal.');
        $dimensions = [];
        $values = $request->inputs['dimensions'] ?? [];
        Assert::that(is_array($values), 'Tax dimensions must be a string map.');
        foreach ($values as $key => $value) {
            Assert::that(is_string($key) && is_string($value), 'Tax dimensions must be a string map.');
            $dimensions[$key] = $value;
        }
        $postings = [];
        $add = function (string $role, string $type, Side $side, Money $amount, bool $control = false) use ($request, $accounts, $currency, $dimensions, $input, &$postings): void {
            Assert::that(isset($accounts[$role]), 'Missing tax account role: '.$role);
            $account = $this->db->find('ledger_accounts', $accounts[$role], $request->bookId);
            Assert::that(Row::text($account, 'type') === $type && Row::boolean($account, 'is_control') === $control && ! Row::boolean($account, 'is_group')
                && (Row::nullable($account, 'currency_code') ?? $currency->code) === $currency->code, 'Tax roles require compatible functional accounts: '.$role);
            Assert::that($role === 'cash' ? Row::nullable($account, 'cash_flow') === 'cash' : Row::nullable($account, 'cash_flow') !== 'cash', 'Tax cash classification is incompatible: '.$role);
            if (! $amount->minor->isZero()) {
                $postings[] = new Posting($accounts[$role], $side, $amount, partyId: $control ? Row::text($input, 'party_id') : null,
                    dimensions: $dimensions);
            }
        };
        $evidence = ['operation' => $operation, 'rule' => $rule, 'base' => $base, 'tax' => $tax];
        $cashFlow = [];
        if ($operation === 'withholding.payment' || $operation === 'withholding.receipt') {
            $gross = Money::of(Row::text($input, 'settlement'), $currency);
            Assert::that($gross->minor->isPositive() && $base->minor->isLessThanOrEqualTo($gross->minor) && $tax->minor->isLessThanOrEqualTo($gross->minor), 'Withholding base and tax cannot exceed settlement.');
            $net = $gross->minus($tax);
            $category = Row::text($input, 'cash_flow');
            Assert::that(in_array($category, ['operating', 'investing', 'financing'], true), 'Withholding requires an explicit cash-flow classification.');
            if ($operation === 'withholding.payment') {
                $add('payable', 'liability', Side::Debit, $gross, true);
                $add('cash', 'asset', Side::Credit, $net);
                $add('tax.withholding.payable', 'liability', Side::Credit, $tax);
                $cashFlow = [$category => (string) $net->minor->negated()];
            } else {
                $add('receivable', 'asset', Side::Credit, $gross, true);
                $add('cash', 'asset', Side::Debit, $net);
                $add('tax.withholding.receivable', 'asset', Side::Debit, $tax);
                $cashFlow = [$category => (string) $net->minor];
            }
            $evidence['settlement'] = $gross;
            $evidence['cash'] = $net;
        } elseif ($operation === 'reverse_charge') {
            $fraction = BigRational::of(Assert::decimal(Row::text($input, 'recoverable_fraction')));
            Assert::that(! $fraction->isNegative() && $fraction->isLessThanOrEqualTo(1), 'Tax recoverability must be between zero and one.');
            $recoverable = Money::rounded($tax->rational()->multipliedBy($fraction), $currency, $rounding);
            $irrecoverable = $tax->minus($recoverable);
            $add(isset($accounts['tax.reverse_charge.input']) ? 'tax.reverse_charge.input' : 'tax.input', 'asset', Side::Debit, $recoverable);
            $add('tax.irrecoverable', 'expense', Side::Debit, $irrecoverable);
            $add('tax.reverse_charge.payable', 'liability', Side::Credit, $tax);
            $evidence['recoverable'] = $recoverable;
            $evidence['irrecoverable'] = $irrecoverable;
        } else {
            throw new LedgerException('Unknown tax treatment operation.');
        }

        return new JournalRequest($request->bookId, $request->key, $request->effectiveOn, $operation.' '.$request->source->reference,
            $postings, ['tax_treatment' => $evidence, ...($cashFlow === [] ? [] : ['cash_flow' => $cashFlow])], source: $request->source);
    }
}
