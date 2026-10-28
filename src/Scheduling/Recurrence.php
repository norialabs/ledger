<?php

declare(strict_types=1);

namespace Noria\Ledger\Scheduling;

use DateTimeImmutable;
use JsonSerializable;
use Noria\Ledger\Support\Assert;

final readonly class Recurrence implements JsonSerializable
{
    public function __construct(public string $unit, public int $every, public string $startsOn, public ?string $endsOn = null, public ?int $occurrences = null)
    {
        Assert::that(in_array($unit, ['day', 'week', 'month', 'year'], true), 'Recurrence unit must be day, week, month or year.');
        Assert::that($every >= 1 && $every <= 1000, 'Recurrence interval must be between 1 and 1000.');
        Assert::date($startsOn);
        Assert::that($endsOn === null || Assert::date($endsOn) >= $startsOn, 'Recurrence ends before it starts.');
        Assert::that($occurrences === null || ($occurrences >= 1 && $occurrences <= 100000), 'Recurrence occurrences must be between 1 and 100,000.');
    }

    public static function once(string $on): self
    {
        return new self('day', 1, $on, occurrences: 1);
    }

    public static function monthly(string $startsOn, int $every = 1, ?string $endsOn = null, ?int $occurrences = null): self
    {
        return new self('month', $every, $startsOn, $endsOn, $occurrences);
    }

    public function dateOf(int $occurrence): string
    {
        Assert::that($occurrence >= 0, 'Occurrences start at zero.');
        $start = new DateTimeImmutable($this->startsOn);
        if ($this->unit === 'day' || $this->unit === 'week') {
            return $start->modify('+'.($occurrence * $this->every * ($this->unit === 'week' ? 7 : 1)).' days')->format('Y-m-d');
        }
        $month = $start->modify('first day of this month')->modify('+'.($occurrence * $this->every * ($this->unit === 'year' ? 12 : 1)).' months');

        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), min((int) $start->format('d'), (int) $month->format('t')))->format('Y-m-d');
    }

    public function includes(int $occurrence): bool
    {
        return ($this->occurrences === null || $occurrence < $this->occurrences) && ($this->endsOn === null || $this->dateOf($occurrence) <= $this->endsOn);
    }

    /** @return array{unit: string, every: int, starts_on: string, ends_on: ?string, occurrences: ?int} */
    public function jsonSerialize(): array
    {
        return ['unit' => $this->unit, 'every' => $this->every, 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn, 'occurrences' => $this->occurrences];
    }
}
