<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Catalog\Period;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use stdClass;

readonly class Periods
{
    public function __construct(private ScopedDatabase $db) {}

    public function open(string $bookId, string $name, string $startsOn, string $endsOn): string
    {
        Assert::text($name, 128);
        Assert::date($startsOn);
        Assert::date($endsOn);
        Assert::that($startsOn <= $endsOn, 'Period ends before it starts.');

        return $this->db->run(Permission::Configure, function () use ($bookId, $name, $startsOn, $endsOn): string {
            $this->db->lockBook($bookId);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_periods', ['id' => $id, 'book_id' => $bookId, 'name' => $name, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);

            return $id;
        }, $bookId);
    }

    public function close(string $bookId, string $periodId, string $reason = 'Period closed'): void
    {
        Assert::text($reason, 1024);
        $this->setClosed($bookId, $periodId, true, Permission::Close, $reason);
    }

    public function reopen(string $bookId, string $periodId, string $reason): void
    {
        Assert::text($reason, 1024);
        $this->setClosed($bookId, $periodId, false, Permission::Reopen, $reason);
    }

    /** @return list<Period> */
    public function list(string $bookId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId): array {
            $this->db->find('ledger_books', $bookId);

            return array_values($this->db->table('ledger_periods')->where('book_id', $bookId)->orderBy('starts_on')->get()
                ->map(fn (stdClass $row): Period => Period::fromRow($row))->all());
        }, $bookId);
    }

    public function covering(string $bookId, string $date): ?Period
    {
        Assert::date($date);

        return $this->db->run(Permission::Read, function () use ($bookId, $date): ?Period {
            $this->db->find('ledger_books', $bookId);
            $row = $this->db->table('ledger_periods')->where('book_id', $bookId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();

            return $row instanceof stdClass ? Period::fromRow($row) : null;
        }, $bookId);
    }

    private function setClosed(string $bookId, string $periodId, bool $closed, Permission $permission, string $reason): void
    {
        $this->db->run($permission, function () use ($bookId, $periodId, $closed, $reason): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_periods', $periodId, $bookId);
            $this->db->table('ledger_periods')->where('id', $periodId)->update(['closed' => $closed, 'change_reason' => $reason]);
        }, $bookId);
    }
}
