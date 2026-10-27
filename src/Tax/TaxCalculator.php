<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Brick\Math\BigRational;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Support\Assert;

final class TaxCalculator
{
    /** @param list<TaxRule> $rules */
    public function calculate(string $price, string $quantity, Currency $currency, string $effectiveOn, array $rules, Rounding $rounding, string $discountFraction = '0'): TaxResult
    {
        Assert::date($effectiveOn);
        $discount = BigRational::of(Assert::decimal($discountFraction));
        $quantityValue = BigRational::of(Assert::decimal($quantity));
        $priceValue = BigRational::of(Assert::decimal($price));
        Assert::that(! $discount->isNegative() && $discount->isLessThanOrEqualTo(1) && ! $quantityValue->isNegative() && ! $priceValue->isNegative(), 'Invalid quantity, price, or discount fraction.');
        $input = $priceValue->multipliedBy($quantityValue)->multipliedBy(BigRational::one()->minus($discount));
        $coefficients = [];
        $all = BigRational::zero();
        $included = BigRational::one();

        foreach ($rules as $rule) {
            Assert::that($effectiveOn >= $rule->validFrom && ($rule->validUntil === null || $effectiveOn <= $rule->validUntil), 'Tax rule is not effective on the document date.');
            Assert::that(! isset($coefficients[$rule->code]), 'Tax codes must be unique within a calculation.');
            $coefficient = BigRational::of($rule->rate)->multipliedBy($rule->compound ? BigRational::one()->plus($all) : BigRational::one());
            $coefficients[$rule->code] = $coefficient;
            $all = $all->plus($coefficient);

            if ($rule->inclusive) {
                $included = $included->plus($coefficient);
            }
        }

        $netExact = $input->dividedBy($included);
        $net = Money::rounded($netExact, $currency, $rounding);
        $gross = Money::rounded($netExact->multipliedBy(BigRational::one()->plus($all)), $currency, $rounding);
        $taxes = [];
        $components = $net;

        foreach ($coefficients as $code => $coefficient) {
            $taxes[$code] = Money::rounded($netExact->multipliedBy($coefficient), $currency, $rounding);
            $components = $components->plus($taxes[$code]);
        }

        return new TaxResult($net, $taxes, $gross, $gross->minus($components));
    }
}
