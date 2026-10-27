<?php

declare(strict_types=1);

namespace Noria\Ledger\Scheduling;

use Brick\Math\BigRational;
use DateTimeImmutable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Support\Assert;

final class Schedule
{
    /** @param non-empty-list<string> $dates
     * @return non-empty-list<ScheduleLine>
     */
    public static function equal(Money $amount, array $dates): array
    {
        Assert::that($dates !== [], 'A schedule needs dates.');
        self::validateDates($dates);
        $parts = $amount->allocate(array_fill(0, count($dates), 1));
        $lines = [];

        foreach ($dates as $index => $date) {
            $lines[] = new ScheduleLine($date, $parts[$index]);
        }

        return $lines;
    }

    /** @return non-empty-list<ScheduleLine> */
    public static function actualDaysByMonth(Money $amount, string $startsOn, string $endsOn): array
    {
        Assert::date($startsOn);
        Assert::date($endsOn);
        Assert::that($startsOn <= $endsOn, 'Schedule ends before it starts.');
        $cursor = new DateTimeImmutable($startsOn);
        $end = new DateTimeImmutable($endsOn);
        $dates = [];
        $weights = [];

        do {
            $last = min($cursor->modify('last day of this month'), $end);
            $dates[] = $last->format('Y-m-d');
            $weights[] = (int) $cursor->diff($last)->format('%a') + 1;
            $cursor = $last->modify('+1 day');
        } while ($cursor <= $end);

        $parts = $amount->allocate($weights);
        $lines = [];

        foreach ($dates as $index => $date) {
            $lines[] = new ScheduleLine($date, $parts[$index]);
        }

        return $lines;
    }

    /** @param non-empty-list<string> $dates
     * @param  non-empty-list<int|string>  $weights
     * @return non-empty-list<ScheduleLine>
     */
    public static function weighted(Money $amount, array $dates, array $weights): array
    {
        self::validateDates($dates);
        Assert::that(count($dates) === count($weights) && array_is_list($weights), 'Schedule dates and weights must have matching ordered lengths.');
        $parts = $amount->allocate($weights);
        $lines = [];
        foreach ($dates as $index => $date) {
            $lines[] = new ScheduleLine($date, $parts[$index]);
        }

        return $lines;
    }

    /** @param non-empty-list<string> $dates
     * @return non-empty-list<ScheduleLine>
     */
    public static function decliningBalance(Money $carrying, Money $residual, string $ratePerSlot, array $dates, bool $switchToStraightLine = false, Rounding $rounding = Rounding::HalfEven): array
    {
        self::validateDates($dates);
        $remaining = $carrying->minus($residual);
        $rate = BigRational::of(Assert::decimal($ratePerSlot));
        Assert::that(! $remaining->minor->isNegative() && ! $residual->minor->isNegative() && $rate->isPositive() && $rate->isLessThanOrEqualTo(1), 'Invalid declining-balance carrying value, residual, or rate.');
        $lines = [];
        foreach ($dates as $index => $date) {
            $slots = count($dates) - $index;
            $amount = $slots === 1 ? $remaining : Money::rounded($carrying->rational()->multipliedBy($rate), $carrying->currency, $rounding);
            if ($switchToStraightLine && $slots > 1) {
                $straight = Money::rounded($remaining->rational()->dividedBy($slots), $carrying->currency, $rounding);
                if ($straight->minor->isGreaterThan($amount->minor)) {
                    $amount = $straight;
                }
            }
            if ($amount->minor->isGreaterThan($remaining->minor)) {
                $amount = $remaining;
            }
            $lines[] = new ScheduleLine($date, $amount);
            $carrying = $carrying->minus($amount);
            $remaining = $remaining->minus($amount);
        }

        return $lines;
    }

    /** @param non-empty-list<string> $dates */
    private static function validateDates(array $dates): void
    {
        Assert::that($dates !== [] && count($dates) <= 10000 && array_is_list($dates), 'A schedule requires between 1 and 10,000 ordered dates.');
        $previous = null;
        foreach ($dates as $date) {
            Assert::date($date);
            Assert::that($previous === null || $date > $previous, 'Schedule dates must strictly increase.');
            $previous = $date;
        }
    }
}
