<?php

declare(strict_types=1);

namespace Noria\Ledger\Support;

use stdClass;

final readonly class AuditEvent
{
    /** @param array<array-key, mixed>|null $before
     * @param  array<array-key, mixed>|null  $after
     */
    public function __construct(
        public string $sequence,
        public string $auditId,
        public string $tableName,
        public string $operation,
        public ?array $before,
        public ?array $after,
        public string $actorId,
        public ?string $firmId,
        public string $recordedAt,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'sequence'), Row::text($row, 'audit_id'), Row::text($row, 'table_name'), Row::text($row, 'operation'),
            Row::nullable($row, 'before_value') === null ? null : Row::json($row, 'before_value'),
            Row::nullable($row, 'after_value') === null ? null : Row::json($row, 'after_value'),
            Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'), Row::text($row, 'recorded_at'));
    }
}
