<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class CommitmentEvent
{
    public function __construct(public string $id, public string $budgetId, public int $lineNo, public string $commitmentId,
        public ?string $reversesId, public ?string $journalId, public string $kind, public Money $amount, public string $effectiveOn,
        public string $reason, public string $policyId, public string $sequence, public string $recordedAt, public string $actorId, public ?string $firmId) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $id = Row::text($row, 'id');

        return new self($id, Row::text($row, 'budget_id'), (int) Row::text($row, 'line_no'), Row::nullable($row, 'commitment_id') ?? $id,
            Row::nullable($row, 'reverses_id'), Row::nullable($row, 'journal_id'), Row::text($row, 'kind'),
            Money::minor(Row::text($row, 'amount_minor'), $currency), Row::text($row, 'effective_on'), Row::text($row, 'reason'),
            Row::text($row, 'policy_id'), Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'));
    }
}
