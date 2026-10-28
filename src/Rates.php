<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\RateType;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Money\StoredRate;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Rates
{
    public function __construct(private ScopedDatabase $db, private Catalog $catalog) {}

    public function record(Currency $base, Currency $quote, RateType $type, string $effectiveOn, string $rate, string $source): StoredRate
    {
        $normalized = $this->validate($base, $quote, $effectiveOn, $rate, $source);

        return $this->db->run(Permission::Configure, function () use ($base, $quote, $type, $effectiveOn, $normalized, $source): StoredRate {
            $this->catalog->registerCurrency($base);
            $this->catalog->registerCurrency($quote);
            $this->lock($base, $quote, $type, $effectiveOn);
            $current = $this->current($base, $quote, $type, $effectiveOn);
            if ($current !== null) {
                Assert::that(BigDecimal::of($current->rate)->isEqualTo($normalized) && $current->source === $source, 'A rate already exists for this date; correct it explicitly.');

                return $current;
            }

            return $this->insert($base, $quote, $type, $effectiveOn, $normalized, $source, null);
        });
    }

    public function correct(Currency $base, Currency $quote, RateType $type, string $effectiveOn, string $rate, string $source, string $reason): StoredRate
    {
        $normalized = $this->validate($base, $quote, $effectiveOn, $rate, $source);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Configure, function () use ($base, $quote, $type, $effectiveOn, $normalized, $source, $reason): StoredRate {
            $this->lock($base, $quote, $type, $effectiveOn);
            $current = $this->current($base, $quote, $type, $effectiveOn);
            Assert::that($current !== null, 'There is no recorded rate to correct.');
            if ($current !== null && $current->reason === $reason && $current->source === $source && BigDecimal::of($current->rate)->isEqualTo($normalized)) {
                return $current;
            }

            return $this->insert($base, $quote, $type, $effectiveOn, $normalized, $source, $reason);
        });
    }

    public function find(Currency $base, Currency $quote, RateType $type, string $date, ?int $maxAgeDays = null, Rounding $rounding = Rounding::HalfEven): ExchangeRate
    {
        Assert::date($date);
        Assert::that($maxAgeDays === null || $maxAgeDays >= 0, 'Maximum rate age cannot be negative.');

        return $this->db->run(Permission::Read, function () use ($base, $quote, $type, $date, $maxAgeDays, $rounding): ExchangeRate {
            $row = $this->db->table('ledger_exchange_rates')->where('base_code', $base->code)->where('quote_code', $quote->code)
                ->where('rate_type', $type->value)->where('effective_on', '<=', $date)
                ->orderByDesc('effective_on')->orderByDesc('revision')->first();
            Assert::that($row instanceof stdClass, 'No '.$type->value.' rate is recorded for '.$base->code.'/'.$quote->code.' on or before '.$date.'.');
            $stored = $this->stored($row instanceof stdClass ? $row : new stdClass);
            $age = (int) (new DateTimeImmutable($stored->effectiveOn))->diff(new DateTimeImmutable($date))->format('%a');
            Assert::that($maxAgeDays === null || $age <= $maxAgeDays, 'The latest '.$type->value.' rate for '.$base->code.'/'.$quote->code.' is older than '.$maxAgeDays.' days.');

            return $stored->exchangeRate($rounding);
        });
    }

    public function get(string $rateId): StoredRate
    {
        return $this->db->run(Permission::Read, function () use ($rateId): StoredRate {
            $row = $this->db->table('ledger_exchange_rates')->where('id', Assert::uuid($rateId))->first();
            Assert::that($row instanceof stdClass, 'Ledger record not found.');

            return $this->stored($row instanceof stdClass ? $row : new stdClass);
        });
    }

    /** @return list<StoredRate> */
    public function history(Currency $base, Currency $quote, RateType $type, string $from, string $to, bool $includeCorrected = false): array
    {
        Assert::date($from);
        Assert::date($to);

        return $this->db->run(Permission::Read, function () use ($base, $quote, $type, $from, $to, $includeCorrected): array {
            $rows = $this->db->table('ledger_exchange_rates')->where('base_code', $base->code)->where('quote_code', $quote->code)
                ->where('rate_type', $type->value)->whereBetween('effective_on', [$from, $to])->orderBy('effective_on')->orderBy('revision')->get();
            $result = [];
            foreach ($rows as $row) {
                $stored = $this->stored($row);
                $result[$includeCorrected ? $stored->id : $stored->effectiveOn] = $stored;
            }

            return array_values($result);
        });
    }

    private function validate(Currency $base, Currency $quote, string $effectiveOn, string $rate, string $source): string
    {
        Assert::that($base->code !== $quote->code, 'A stored rate requires two different currencies.');
        Assert::date($effectiveOn);
        Assert::text($source);
        $normalized = (string) (new ExchangeRate($base, $quote, $rate, $effectiveOn, $source, Rounding::HalfEven))->rate;
        Assert::that(BigDecimal::of($normalized)->isLessThan(BigDecimal::of('100000000000000000000')), 'Exchange rate is outside supported precision.');

        return $normalized;
    }

    private function lock(Currency $base, Currency $quote, RateType $type, string $effectiveOn): void
    {
        $this->db->connection->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$this->db->scope->tenantId.$base->code.$quote->code.$type->value.$effectiveOn]);
    }

    private function current(Currency $base, Currency $quote, RateType $type, string $effectiveOn): ?StoredRate
    {
        $row = $this->db->table('ledger_exchange_rates')->where('base_code', $base->code)->where('quote_code', $quote->code)
            ->where('rate_type', $type->value)->where('effective_on', $effectiveOn)->orderByDesc('revision')->first();

        return $row instanceof stdClass ? $this->stored($row) : null;
    }

    private function insert(Currency $base, Currency $quote, RateType $type, string $effectiveOn, string $rate, string $source, ?string $reason): StoredRate
    {
        $id = $this->db->connection->selectOne('SELECT uuidv7()::text AS id');
        Assert::that($id instanceof stdClass, 'Cannot allocate a rate identifier.');
        $id = $id instanceof stdClass ? Row::text($id, 'id') : '';
        $this->db->insert('ledger_exchange_rates', ['id' => $id, 'base_code' => $base->code, 'quote_code' => $quote->code, 'rate_type' => $type->value,
            'effective_on' => $effectiveOn, 'revision' => 0, 'rate' => $rate, 'source' => $source, 'reason' => $reason,
            'recorded_at' => $this->db->connection->raw('clock_timestamp()'), 'actor_id' => $this->db->scope->actorId]);

        return $this->get($id);
    }

    private function stored(stdClass $row): StoredRate
    {
        return new StoredRate(Row::text($row, 'id'), $this->db->currency(Row::text($row, 'base_code')), $this->db->currency(Row::text($row, 'quote_code')),
            RateType::from(Row::text($row, 'rate_type')), Row::text($row, 'effective_on'), (int) Row::text($row, 'revision'),
            (string) BigDecimal::of(Row::text($row, 'rate'))->strippedOfTrailingZeros(), Row::text($row, 'source'), Row::nullable($row, 'reason'),
            Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'));
    }
}
