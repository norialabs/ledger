<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Database\Query\JoinClause;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\AuditEvent;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Events
{
    public function __construct(private ScopedDatabase $db) {}

    public function cutoff(string $bookId): string
    {
        return $this->db->run(Permission::Read, function () use ($bookId): string {
            $this->db->lockBook($bookId);
            $row = $this->db->table('ledger_events')->where('book_id', $bookId)->selectRaw('coalesce(max(sequence), 0)::text AS sequence')->first();

            return $row instanceof stdClass ? Row::text($row, 'sequence') : '0';
        }, $bookId);
    }

    /** @return list<AuditEvent> */
    public function page(string $bookId, string $afterSequence = '0', int $limit = 500, ?string $throughSequence = null): array
    {
        new Snapshot($bookId, '0001-01-01', $afterSequence);
        if ($throughSequence !== null) {
            new Snapshot($bookId, '0001-01-01', $throughSequence);
        }
        Assert::that($limit >= 1 && $limit <= 10000, 'Invalid event page size.');

        return $this->db->run(Permission::Read, function () use ($bookId, $afterSequence, $limit, $throughSequence): array {
            $this->db->find('ledger_books', $bookId);
            $query = $this->db->table('ledger_events')->join('ledger_audit_events AS a', function (JoinClause $join): void {
                $join->on('a.tenant_id', '=', 'ledger_events.tenant_id')->on('a.id', '=', 'ledger_events.audit_id');
            })->where('ledger_events.book_id', $bookId)->where('ledger_events.sequence', '>', $afterSequence)
                ->selectRaw('ledger_events.sequence::text, ledger_events.audit_id::text, a.table_name, a.operation, a.before_value, a.after_value, a.actor_id, a.firm_id, a.recorded_at')
                ->orderBy('ledger_events.sequence')->limit($limit);
            if ($throughSequence !== null) {
                $query->where('ledger_events.sequence', '<=', $throughSequence);
            }

            return array_map(fn (stdClass $row): AuditEvent => AuditEvent::fromRow($row), array_values($query->get()->all()));
        }, $bookId);
    }
}
