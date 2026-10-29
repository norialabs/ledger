<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class BudgetPolicy
{
    public function __construct(public string $id, public string $budgetId, public string $version, public bool $rejectOverBudget,
        public bool $rejectUnbudgeted, public string $sequence, public string $recordedAt, public string $actorId, public ?string $firmId) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'budget_id'), Row::text($row, 'version'), Row::boolean($row, 'reject_over_budget'),
            Row::boolean($row, 'reject_unbudgeted'), Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'));
    }
}
