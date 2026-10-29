<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class IncomeTaxLoss
{
    public function __construct(public string $id, public string $source, public string $vintageOn, public Money $amount, public Money $used,
        public Money $remaining, public string $reference, public ?string $computationId, public ?string $reversesId, public string $sequence,
        public string $recordedAt, public string $actorId, public ?string $firmId) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $amount = Money::minor(Row::text($row, 'amount_minor'), $currency);
        $used = Money::minor(Row::text($row, 'used_minor'), $currency);
        $remaining = Money::minor(Row::text($row, 'remaining_minor'), $currency);

        return new self(Row::text($row, 'id'), Row::text($row, 'source'), Row::text($row, 'vintage_on'), $amount, $used, $remaining,
            Row::text($row, 'reference'), Row::nullable($row, 'computation_id'), Row::nullable($row, 'reverses_id'), Row::text($row, 'sequence'),
            Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'));
    }
}
