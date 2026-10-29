<?php

declare(strict_types=1);

namespace Noria\Ledger\Finance;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Support\Assert;

final class FinancialCalculator
{
    /** @param non-empty-list<array{due_on: string, payment: Money}> $payments */
    public function effectiveRate(Money $amortizedCost, array $payments): string
    {
        $this->payments($payments, $amortizedCost->currency);
        Assert::that($amortizedCost->minor->isPositive(), 'Effective-rate measurement requires positive amortized cost.');
        $precision = BigInteger::of('1000000000000000000');
        $cost = $amortizedCost->minor;
        $amounts = array_map(fn (array $payment): BigInteger => $payment['payment']->minor, $payments);
        $scale = BigInteger::ten()->power(60);
        $comparePresentValue = function (BigInteger $units, ?BigInteger $denominator = null) use ($amounts, $precision, $cost, $scale): int {
            $denominator ??= $precision;
            $base = $denominator->plus($units);
            $low = $high = $cost->multipliedBy($scale);
            foreach ($amounts as $amount) {
                $low = $low->multipliedBy($base)->dividedBy($denominator, RoundingMode::Floor)->minus($amount->multipliedBy($scale));
                $high = $high->multipliedBy($base)->dividedBy($denominator, RoundingMode::Ceiling)->minus($amount->multipliedBy($scale));
            }
            if ($high->isNegative()) {
                return 1;
            }
            if ($low->isPositive()) {
                return -1;
            }
            $scaled = BigInteger::zero();
            $power = BigInteger::one();
            foreach ($amounts as $amount) {
                $power = $power->multipliedBy($denominator);
                $scaled = $scaled->multipliedBy($base)->plus($amount->multipliedBy($power));
            }

            return $scaled->compareTo($cost->multipliedBy($base->power(count($amounts))));
        };
        $terminal = function (BigInteger $units) use ($amounts, $precision, $cost): ?BigInteger {
            $balance = $cost;
            foreach ($amounts as $amount) {
                [$interest, $remainder] = $balance->multipliedBy($units)->quotientAndRemainder($precision);
                $twice = $remainder->multipliedBy(2)->compareTo($precision);
                if ($twice > 0 || $twice === 0 && $interest->isOdd()) {
                    $interest = $interest->plus(1);
                }
                $balance = $balance->plus($interest)->minus($amount);
                if ($balance->isNegative()) {
                    return null;
                }
            }

            return $balance;
        };
        $first = function (callable $holds) use ($precision): ?BigInteger {
            if (! $holds($precision)) {
                return null;
            }
            $low = BigInteger::of(-1);
            $high = $precision;
            while ($high->minus($low)->isGreaterThan(1)) {
                $middle = $low->plus($high)->quotient(2);
                [$low, $high] = $holds($middle) ? [$low, $middle] : [$middle, $high];
            }

            return $high;
        };
        $undiscounted = array_reduce($amounts, fn (BigInteger $total, BigInteger $amount): BigInteger => $total->plus($amount), BigInteger::zero());
        $ceiling = $first(fn (BigInteger $units): bool => $comparePresentValue($units) <= 0);
        Assert::that($ceiling !== null && $undiscounted->isGreaterThanOrEqualTo($cost), 'Effective rate lies outside the supported zero-to-one range.');
        $root = $comparePresentValue($ceiling) === 0 || $ceiling->isZero() || $comparePresentValue($ceiling->multipliedBy(2)->minus(1), $precision->multipliedBy(2)) > 0
            ? $ceiling : $ceiling->minus(1);
        $lowest = $first(fn (BigInteger $units): bool => $terminal($units) !== null);
        if ($lowest === null || ! ($terminal($lowest)?->isZero() ?? false)) {
            throw new LedgerException('No bounded effective rate conserves this rounded payment plan; review the contractual rounding basis.');
        }
        $beyond = $first(fn (BigInteger $units): bool => $terminal($units)?->isPositive() ?? false);
        $highest = $beyond === null ? $precision : $beyond->minus(1);
        $nearest = $root->isLessThan($lowest) ? $lowest : ($root->isGreaterThan($highest) ? $highest : $root);

        return (string) BigRational::of($nearest)->dividedBy($precision)->toScale(18);
    }

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
            && count($contractual) <= 600 && count($scenarios) <= 100, 'ECL needs bounded ordered cash flows and scenarios.');
        $currency = $contractual[0]['amount']->currency;
        $factor = BigRational::one()->plus($this->rate($ratePerSlot));
        $weights = BigRational::zero();
        $weighted = BigRational::zero();
        $results = [];
        $previous = -1;
        foreach ($contractual as $flow) {
            Assert::that($flow['period'] > $previous && $flow['period'] <= 600 && ! $flow['amount']->minor->isNegative(), 'ECL periods must increase from zero and amounts cannot be negative.');
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
        Assert::that($payments !== [] && count($payments) <= 600 && array_is_list($payments), 'A financial plan needs between one and 600 ordered payments.');
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
