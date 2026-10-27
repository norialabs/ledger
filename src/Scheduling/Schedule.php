<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Scheduling;

use DateTimeImmutable;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Support\Assert;

final class Schedule
{
    /** @param non-empty-list<string> $dates
     * @return non-empty-list<ScheduleLine>
     */
    public static function equal(Money $amount, array $dates): array
    {
        Assert::that($dates !== [], 'A schedule needs dates.');
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
}
