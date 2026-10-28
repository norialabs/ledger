<?php

declare(strict_types=1);

namespace Noria\Ledger\Finance;

use Brick\Math\BigRational;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Support\Assert;

final class FinancialCalculator
{
    /** @param non-empty-list<array{due_on: string, payment: Money}> $payments
     * @return list<array{due_on: string, opening: Money, interest: Money, payment: Money, closing: Money, rounding_adjustment: Money}>
     */
    public function effectiveInterest(Money $opening, string $ratePerSlot, array $payments, Rounding $rounding = Rounding::HalfEven): array
    {
        $this->payments($payments, $opening->currency);
        $rate = $this->rate($ratePerSlot);
        Assert::that($opening->minor->isPositive(), 'Opening amortized cost must be positive.');

        return $this->rollForward($opening->rational(), $rate, $payments, $rounding, false);
    }

    /** @param non-empty-list<array{due_on: string, payment: Money}> $payments
     * @return array{liability: Money, commencement_payment: Money, rows: list<array{due_on: string, opening: Money, interest: Money, payment: Money, closing: Money, rounding_adjustment: Money}>}
     */
    public function lease(string $ratePerSlot, array $payments, bool $inAdvance = false, Rounding $rounding = Rounding::HalfEven): array
    {
        Assert::that($payments !== [], 'A lease needs payments.');
        $currency = $payments[0]['payment']->currency;
        $this->payments($payments, $currency);
        $rate = $this->rate($ratePerSlot);
        $factor = BigRational::one()->plus($rate);
        $present = BigRational::zero();
        foreach ($payments as $index => $payment) {
            $present = $present->plus($payment['payment']->rational()->dividedBy($factor->power($index + ($inAdvance ? 0 : 1))));
        }
        $commencement = $inAdvance ? $payments[0]['payment'] : Money::minor('0', $currency);
        $unpaid = $present->minus($commencement->rational());
        $future = $inAdvance ? array_slice($payments, 1) : $payments;

        return ['liability' => Money::rounded($unpaid, $currency, $rounding), 'commencement_payment' => $commencement,
            'rows' => $this->rollForward($unpaid, $rate, $future, $rounding, true)];
    }

    /** @param non-empty-list<array{period: int, amount: Money}> $contractual
     * @param  non-empty-list<array{weight: string, receipts: list<Money>}>  $scenarios
     * @return array{allowance: Money, scenarios: list<array{weight: string, shortfall: Money}>}
     */
    public function expectedCreditLoss(string $ratePerSlot, array $contractual, array $scenarios, Rounding $rounding = Rounding::HalfEven): array
    {
        Assert::that($contractual !== [] && $scenarios !== [] && array_is_list($contractual) && array_is_list($scenarios)
            && count($contractual) <= 1200 && count($scenarios) <= 100, 'ECL needs bounded ordered cash flows and scenarios.');
        $currency = $contractual[0]['amount']->currency;
        $factor = BigRational::one()->plus($this->rate($ratePerSlot));
        $weights = BigRational::zero();
        $weighted = BigRational::zero();
        $results = [];
        $previous = -1;
        foreach ($contractual as $flow) {
            Assert::that($flow['period'] > $previous && $flow['period'] <= 1200 && ! $flow['amount']->minor->isNegative(), 'ECL periods must increase from zero and amounts cannot be negative.');
            $contractual[0]['amount']->sameCurrency($flow['amount']);
            $previous = $flow['period'];
        }
        foreach ($scenarios as $scenario) {
            $weight = BigRational::of(Assert::decimal($scenario['weight']));
            Assert::that(! $weight->isNegative() && $weight->isLessThanOrEqualTo(1) && array_is_list($scenario['receipts'])
                && count($scenario['receipts']) === count($contractual), 'Invalid ECL probability or receipt count.');
            $weights = $weights->plus($weight);
            $shortfall = BigRational::zero();
            foreach ($contractual as $index => $flow) {
                $receipt = $scenario['receipts'][$index];
                $flow['amount']->sameCurrency($receipt);
                Assert::that(! $receipt->minor->isNegative(), 'Expected receipts cannot be negative.');
                $shortfall = $shortfall->plus($flow['amount']->minus($receipt)->rational()->dividedBy($factor->power($flow['period'])));
            }
            Assert::that(! $shortfall->isNegative(), 'Scenario receipts cannot exceed contractual present value.');
            $weighted = $weighted->plus($shortfall->multipliedBy($weight));
            $results[] = ['weight' => $scenario['weight'], 'shortfall' => Money::rounded($shortfall, $currency, $rounding)];
        }
        Assert::that($weights->isEqualTo(1), 'ECL scenario probabilities must sum to exactly one.');

        return ['allowance' => Money::rounded($weighted, $currency, $rounding), 'scenarios' => $results];
    }

    /** @param non-empty-list<array{reference: string, standalone_price: string}> $obligations
     * @return array<string, Money>
     */
    public function allocateRevenue(Money $transactionPrice, array $obligations): array
    {
        Assert::that(! $transactionPrice->minor->isNegative() && $obligations !== [] && array_is_list($obligations) && count($obligations) <= 1000, 'Revenue allocation needs a nonnegative price and bounded ordered obligations.');
        $references = [];
        $weights = [];
        foreach ($obligations as $obligation) {
            $reference = Assert::text($obligation['reference'], 120);
            Assert::that(! isset($references[$reference]) && preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $reference) === 1, 'Obligation references must be unique named identifiers.');
            $references[$reference] = true;
            $weight = Assert::decimal($obligation['standalone_price']);
            Assert::that(BigRational::of($weight)->isPositive(), 'Standalone selling prices must be positive.');
            $weights[] = $weight;
        }

        return array_combine(array_keys($references), $transactionPrice->allocate($weights));
    }

    private function rate(string $rate): BigRational
    {
        $value = BigRational::of(Assert::decimal($rate));
        Assert::that(! $value->isNegative(), 'The per-slot discount or effective rate cannot be negative.');

        return $value;
    }

    /** @param list<array{due_on: string, payment: Money}> $payments */
    private function payments(array $payments, Currency $currency): void
    {
        Assert::that($payments !== [] && count($payments) <= 1200 && array_is_list($payments), 'A financial plan needs between one and 1,200 ordered payments.');
        $previous = '';
        foreach ($payments as $payment) {
            Assert::date($payment['due_on']);
            Assert::that($payment['due_on'] > $previous && ! $payment['payment']->minor->isNegative(), 'Payment dates must increase and payments cannot be negative.');
            Assert::that($payment['payment']->currency == $currency, 'Payment currency or scale mismatch.');
            $previous = $payment['due_on'];
        }
    }

    /** @param list<array{due_on: string, payment: Money}> $payments
     * @return list<array{due_on: string, opening: Money, interest: Money, payment: Money, closing: Money, rounding_adjustment: Money}>
     */
    private function rollForward(BigRational $exact, BigRational $rate, array $payments, Rounding $rounding, bool $roundExact): array
    {
        $rows = [];
        foreach ($payments as $payment) {
            $currency = $payment['payment']->currency;
            $opening = Money::rounded($exact, $currency, $rounding);
            $ordinary = Money::rounded($opening->rational()->multipliedBy($rate), $currency, $rounding);
            $exact = $roundExact ? $exact->multipliedBy(BigRational::one()->plus($rate))->minus($payment['payment']->rational())
                : $opening->plus($ordinary)->minus($payment['payment'])->rational();
            Assert::that(! $exact->isNegative(), 'Payments exceed amortized carrying value.');
            $closing = Money::rounded($exact, $currency, $rounding);
            $interest = $closing->minus($opening)->plus($payment['payment']);
            $rows[] = ['due_on' => $payment['due_on'], 'opening' => $opening, 'interest' => $interest,
                'payment' => $payment['payment'], 'closing' => $closing, 'rounding_adjustment' => $interest->minus($ordinary)];
        }

        return $rows;
    }
}
