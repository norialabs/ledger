<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\Journal;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class JournalBatches
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    /** @param non-empty-list<JournalRequest> $requests
     * @return list<Journal>
     */
    public function post(string $key, array $requests, string $description): array
    {
        Assert::text($key, 128);
        Assert::text($description, 1000);

        return $this->db->run(Permission::Intercompany, function () use ($key, $requests, $description): array {
            $books = array_values(array_unique(array_map(fn (JournalRequest $request): string => $request->bookId, $requests)));
            Assert::that(count($books) >= 2, 'A cross-book batch requires at least two distinct books.');
            $this->lockBooks($books, Permission::Post);
            $fingerprint = Canonical::hash([$requests, $description]);
            $existing = $this->replay($key, $fingerprint, Permission::Post);

            if ($existing !== null) {
                return $existing;
            }

            foreach ($requests as $request) {
                Assert::that(! $this->db->table('ledger_journals')->where('book_id', $request->bookId)->where('idempotency_key', $request->key)->exists(), 'A new batch cannot adopt a previously posted journal.');
            }

            $journals = array_map($this->journals->post(...), $requests);
            $this->record($key, $fingerprint, $description, $journals);

            return $journals;
        });
    }

    /** @return list<Journal> */
    public function reverse(string $batchKey, string $key, string $effectiveOn, string $reason): array
    {
        Assert::text($batchKey, 128);
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1000);

        return $this->db->run(Permission::Intercompany, function () use ($batchKey, $key, $effectiveOn, $reason): array {
            $batch = $this->db->table('ledger_batches')->where('idempotency_key', $batchKey)->first();
            Assert::that($batch instanceof stdClass, 'Journal batch not found.');
            $members = $batch instanceof stdClass ? Row::json($batch, 'members') : [];
            $books = [];

            foreach ($members as $member) {
                $books[] = Row::text((object) $member, 'book_id');
            }

            $this->lockBooks($books, Permission::Reverse);
            $fingerprint = Canonical::hash(['reverse' => $batchKey, 'effective_on' => $effectiveOn, 'reason' => $reason]);
            $existing = $this->replay($key, $fingerprint, Permission::Reverse);

            if ($existing !== null) {
                return $existing;
            }

            $journals = [];

            foreach ($members as $index => $member) {
                $row = (object) $member;
                $journals[] = $this->journals->reverse(Row::text($row, 'book_id'), Row::text($row, 'journal_id'), 'batch-reversal:'.hash('sha256', $key.':'.$index), $effectiveOn, $reason);
            }

            $this->record($key, $fingerprint, $reason, $journals, $batch instanceof stdClass ? Row::text($batch, 'id') : null);

            return $journals;
        });
    }

    /** @param list<string> $bookIds */
    private function lockBooks(array $bookIds, Permission $permission): void
    {
        sort($bookIds, SORT_STRING);

        foreach (array_unique($bookIds) as $bookId) {
            $this->db->authorize($permission, $bookId);
            $this->db->lockBook($bookId);
        }
    }

    /** @return list<Journal>|null */
    private function replay(string $key, string $fingerprint, Permission $permission): ?array
    {
        $batch = $this->db->table('ledger_batches')->where('idempotency_key', $key)->first();

        if (! $batch instanceof stdClass) {
            return null;
        }

        Assert::that(Row::text($batch, 'fingerprint') === $fingerprint, 'Batch key was already used with different content.');
        $journals = [];

        foreach (Row::json($batch, 'members') as $member) {
            $row = (object) $member;
            $bookId = Row::text($row, 'book_id');
            $this->db->authorize($permission, $bookId);
            $journals[] = Journal::fromRow($this->db->find('ledger_journals', Row::text($row, 'journal_id'), $bookId));
        }

        return $journals;
    }

    /** @param list<Journal> $journals */
    private function record(string $key, string $fingerprint, string $description, array $journals, ?string $reversesId = null): void
    {
        $this->db->insert('ledger_batches', ['id' => (string) Str::uuid(), 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
            'description' => $description, 'members' => Canonical::json(array_map(fn (Journal $journal): array => ['book_id' => $journal->bookId, 'journal_id' => $journal->id], $journals)), 'reverses_id' => $reversesId]);
    }
}
