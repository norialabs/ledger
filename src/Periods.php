<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;

final readonly class Periods
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
            $id = (string) Str::uuid();
            $this->db->insert('ledger_periods', ['id' => $id, 'book_id' => $bookId, 'name' => $name, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);

            return $id;
        }, $bookId);
    }

    public function close(string $bookId, string $periodId, string $reason = 'Period closed'): void
    {
        Assert::text($reason, 1000);
        $this->setClosed($bookId, $periodId, true, Permission::Close, $reason);
    }

    public function reopen(string $bookId, string $periodId, string $reason): void
    {
        Assert::text($reason, 1000);
        $this->setClosed($bookId, $periodId, false, Permission::Reopen, $reason);
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
