<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class IncomeTaxEvent
{
    /** @param array<array-key, mixed> $evidence */
    public function __construct(public string $id, public string $computationId, public string $kind, public string $effectiveOn,
        public string $reason, public array $evidence, public ?string $journalId, public ?string $reversesId, public string $sequence,
        public string $recordedAt, public string $actorId, public ?string $firmId) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'computation_id'), Row::text($row, 'kind'), Row::text($row, 'effective_on'),
            Row::text($row, 'reason'), Row::json($row, 'evidence'), Row::nullable($row, 'journal_id'), Row::nullable($row, 'reverses_id'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'));
    }
}
