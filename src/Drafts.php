<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalDraft;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Drafts
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    public function prepare(JournalRequest $request, ?string $supersedesId = null): JournalDraft
    {
        Assert::that($request->reversesId === null && ! $request->closing, 'Drafts cannot reverse or close; use the dedicated commands.');

        return $this->db->run(Permission::Prepare, function () use ($request, $supersedesId): JournalDraft {
            $this->db->lockBook($request->bookId);
            $fingerprint = Canonical::hash($request);
            $existing = $this->db->table('ledger_journal_drafts')->where('book_id', $request->bookId)->where('idempotency_key', $request->key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint && Row::nullable($existing, 'supersedes_id') === $supersedesId,
                    'Idempotency key was already used for a different draft.');

                return $this->load($request->bookId, Row::text($existing, 'id'));
            }
            if ($supersedesId !== null) {
                Assert::that($this->load($request->bookId, $supersedesId)->status === 'pending', 'Only a pending draft can be revised.');
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_journal_drafts', ['id' => $id, 'book_id' => $request->bookId, 'idempotency_key' => $request->key,
                'fingerprint' => $fingerprint, 'effective_on' => $request->effectiveOn, 'description' => $request->description,
                'request' => Canonical::json($this->serialize($request)), 'supersedes_id' => $supersedesId]);

            return $this->load($request->bookId, $id);
        }, $request->bookId);
    }

    public function approve(string $bookId, string $draftId, string $reason = 'Approved'): Journal
    {
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Approve, function () use ($bookId, $draftId, $reason): Journal {
            $this->db->lockBook($bookId);
            $draft = $this->load($bookId, $draftId);
            if ($draft->status === 'approved' && $draft->journalId !== null) {
                return $this->journals->get($bookId, $draft->journalId);
            }
            Assert::that($draft->status === 'pending', 'Only a pending draft can be approved.');
            $request = $draft->request;
            $journal = $this->journals->postApprovedDraft(new JournalRequest($bookId, 'draft:'.$draftId, $request->effectiveOn, $request->description,
                $request->postings, $request->metadata, source: $request->source));
            $this->decide($bookId, $draftId, 'approved', $reason, $journal->id);

            return $journal;
        }, $bookId);
    }

    public function reject(string $bookId, string $draftId, string $reason): JournalDraft
    {
        return $this->conclude($bookId, $draftId, 'rejected', $reason, Permission::Approve);
    }

    public function withdraw(string $bookId, string $draftId, string $reason): JournalDraft
    {
        return $this->conclude($bookId, $draftId, 'withdrawn', $reason, Permission::Prepare);
    }

    public function get(string $bookId, string $draftId): JournalDraft
    {
        return $this->db->run(Permission::Read, fn (): JournalDraft => $this->load($bookId, $draftId), $bookId);
    }

    /** @return list<JournalDraft> */
    public function list(string $bookId, ?string $status = 'pending', int $limit = 100): array
    {
        Assert::that($status === null || in_array($status, ['pending', 'superseded', 'approved', 'rejected', 'withdrawn'], true), 'Unknown draft status.');
        Assert::that($limit >= 1 && $limit <= 1000, 'Invalid draft page size.');

        return $this->db->run(Permission::Read, function () use ($bookId, $status, $limit): array {
            $this->db->find('ledger_books', $bookId);
            $drafts = [];
            foreach ($this->db->table('ledger_journal_drafts')->where('book_id', $bookId)->orderBy('sequence')->pluck('id') as $id) {
                $draft = $this->load($bookId, is_string($id) ? $id : '');
                if ($status === null || $draft->status === $status) {
                    $drafts[] = $draft;
                }
                if (count($drafts) === $limit) {
                    break;
                }
            }

            return $drafts;
        }, $bookId);
    }

    private function conclude(string $bookId, string $draftId, string $decision, string $reason, Permission $permission): JournalDraft
    {
        Assert::text($reason, 1024);

        return $this->db->run($permission, function () use ($bookId, $draftId, $decision, $reason): JournalDraft {
            $this->db->lockBook($bookId);
            $draft = $this->load($bookId, $draftId);
            if ($draft->status === $decision) {
                return $draft;
            }
            Assert::that($draft->status === 'pending', 'Only a pending draft can be '.$decision.'.');
            $this->decide($bookId, $draftId, $decision, $reason, null);

            return $this->load($bookId, $draftId);
        }, $bookId);
    }

    private function decide(string $bookId, string $draftId, string $decision, string $reason, ?string $journalId): void
    {
        $this->db->insert('ledger_draft_decisions', ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'draft_id' => $draftId,
            'decision' => $decision, 'journal_id' => $journalId, 'reason' => $reason]);
    }

    private function load(string $bookId, string $draftId): JournalDraft
    {
        $row = $this->db->find('ledger_journal_drafts', $draftId, $bookId);
        $decision = $this->db->table('ledger_draft_decisions')->where('book_id', $bookId)->where('draft_id', $draftId)->first();
        $superseded = $this->db->table('ledger_journal_drafts')->where('book_id', $bookId)->where('supersedes_id', $draftId)->exists();
        $status = $decision instanceof stdClass ? Row::text($decision, 'decision') : ($superseded ? 'superseded' : 'pending');
        $request = $this->deserialize($bookId, Row::text($row, 'idempotency_key'), Row::jsonObject($row, 'request'));
        Assert::that(Canonical::hash($request) === Row::text($row, 'fingerprint'), 'Stored draft does not match its fingerprint.');

        return new JournalDraft($draftId, $bookId, $status, $request, Row::nullable($row, 'supersedes_id'), Row::text($row, 'actor_id'),
            Row::nullable($row, 'firm_id'), Row::text($row, 'recorded_at'),
            $decision instanceof stdClass ? Row::text($decision, 'actor_id') : null, $decision instanceof stdClass ? Row::text($decision, 'recorded_at') : null,
            $decision instanceof stdClass ? Row::text($decision, 'reason') : null, $decision instanceof stdClass ? Row::nullable($decision, 'journal_id') : null);
    }

    /** @return array<string, mixed> */
    private function serialize(JournalRequest $request): array
    {
        return ['effective_on' => $request->effectiveOn, 'description' => $request->description, 'metadata' => $request->metadata,
            'source' => $request->source, 'postings' => array_map(fn (Posting $posting): array => [
                'account_id' => $posting->accountId, 'side' => $posting->side->value, 'currency' => $posting->amount->currency->code,
                'amount_minor' => (string) $posting->amount->minor, 'party_id' => $posting->partyId, 'due_on' => $posting->dueOn,
                'dimensions' => $posting->dimensions, 'description' => $posting->description,
                'valuation_minor' => $posting->valuation === null ? null : (string) $posting->valuation->minor,
                'rate' => $posting->exchangeRate === null ? null : ['to' => $posting->exchangeRate->to->code, 'rate' => $posting->exchangeRate->rate,
                    'as_of' => $posting->exchangeRate->asOf, 'source' => $posting->exchangeRate->source, 'rounding' => $posting->exchangeRate->rounding->value],
            ], array_values($request->postings))];
    }

    /** @param array<string, mixed> $data */
    private function deserialize(string $bookId, string $key, array $data): JournalRequest
    {
        $currencies = [];
        $currency = function (string $code) use (&$currencies): Currency {
            return $currencies[$code] ??= $this->db->currency($code);
        };
        $postings = [];
        foreach (is_array($data['postings'] ?? null) ? $data['postings'] : [] as $line) {
            Assert::that(is_array($line), 'Invalid stored draft.');
            $line = (object) $line;
            $from = $currency(Row::text($line, 'currency'));
            $rate = is_array($line->rate ?? null) ? (object) $line->rate : null;
            $dimensions = Row::stringMap($line->dimensions ?? null);
            $valuation = Row::nullable($line, 'valuation_minor');
            $postings[] = new Posting(Row::text($line, 'account_id'), Side::from(Row::text($line, 'side')), Money::minor(Row::text($line, 'amount_minor'), $from),
                $rate === null ? null : new ExchangeRate($from, $currency(Row::text($rate, 'to')), Row::text($rate, 'rate'), Row::text($rate, 'as_of'), Row::text($rate, 'source'), Rounding::from(Row::text($rate, 'rounding'))),
                Row::nullable($line, 'party_id'), Row::nullable($line, 'due_on'), $dimensions, Row::text($line, 'description'),
                $valuation === null || $rate === null ? null : Money::minor($valuation, $currency(Row::text($rate, 'to'))));
        }
        $source = is_array($data['source'] ?? null) ? (object) $data['source'] : null;
        $metadata = [];
        foreach (is_array($data['metadata'] ?? null) ? $data['metadata'] : [] as $name => $value) {
            if (is_string($name)) {
                $metadata[$name] = $value;
            }
        }

        return new JournalRequest($bookId, $key, is_string($data['effective_on'] ?? null) ? $data['effective_on'] : '', is_string($data['description'] ?? null) ? $data['description'] : '',
            $postings, $metadata, source: $source === null ? null : new Source(Row::text($source, 'type'), Row::text($source, 'reference')));
    }
}
