<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalLine;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\JournalRun;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Journals
{
    private const RUN_CHUNK = 1000;

    /** @param list<PostingPolicy> $policies */
    public function __construct(private ScopedDatabase $db, private array $policies = []) {}

    public function post(JournalRequest $request): Journal
    {
        Assert::that(! str_starts_with($request->key, 'draft:'), 'Journal keys starting with draft: are reserved for approved drafts.');

        return $this->record($request, $request->closing ? Permission::Close : Permission::Post);
    }

    public function postApprovedDraft(JournalRequest $request): Journal
    {
        Assert::that(str_starts_with($request->key, 'draft:') && ! $request->closing, 'Approved drafts post with their draft key.');

        return $this->record($request, Permission::Approve);
    }

    private function record(JournalRequest $request, Permission $permission): Journal
    {
        Assert::that($request->reversesId === null, 'Use reverse() to reverse a journal.');

        return $this->db->run($permission, function () use ($request): Journal {
            $book = $this->db->lockBook($request->bookId);
            $fingerprint = Canonical::hash($request);
            $existing = $this->replay($request->bookId, $request->key, $fingerprint);

            if ($existing !== null) {
                return $existing;
            }

            $currency = $this->db->currency(Row::text($book, 'currency_code'));

            return $this->insert($this->requestRow($request, $fingerprint, $currency));
        }, $request->bookId);
    }

    /** @param iterable<JournalRequest> $requests */
    public function postRun(string $bookId, iterable $requests): JournalRun
    {
        Assert::uuid($bookId);

        return $this->db->run(Permission::Post, function () use ($bookId, $requests): JournalRun {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $before = Row::text($book, 'next_sequence');
            $journalIds = [];
            $chunk = [];
            $registered = [];
            foreach ($requests as $request) {
                Assert::that($request->bookId === $bookId && ! $request->closing && ! str_starts_with($request->key, 'draft:'), 'A posting run holds ordinary journals for its own book.');
                Assert::that(! isset($journalIds[$request->key]) && ! isset($chunk[$request->key]), 'A posting run cannot repeat a journal key: '.$request->key);
                $chunk[$request->key] = $request;
                if (count($chunk) === self::RUN_CHUNK) {
                    $journalIds += $this->postChunk($bookId, $currency, $chunk, $registered);
                    $chunk = [];
                }
            }
            $journalIds += $this->postChunk($bookId, $currency, $chunk, $registered);
            Assert::that($journalIds !== [], 'A posting run requires at least one journal.');
            $after = $this->db->find('ledger_books', $bookId);

            $through = Row::text($after, 'next_sequence');
            $posted = (int) BigInteger::of($through)->minus($before)->toInt();

            return new JournalRun($bookId, $journalIds, $posted, count($journalIds) - $posted, $before, $through);
        }, $bookId);
    }

    public function reverse(string $bookId, string $journalId, string $key, string $effectiveOn, string $reason): Journal
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Reverse, function () use ($bookId, $journalId, $key, $effectiveOn, $reason): Journal {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['reverse' => $journalId, 'effective_on' => $effectiveOn, 'reason' => $reason]);
            $existing = $this->replay($bookId, $key, $fingerprint);

            if ($existing !== null) {
                return $existing;
            }

            $original = $this->db->find('ledger_journals', $journalId, $bookId);
            $lines = [];

            foreach (Row::json($original, 'postings') as $line) {
                Assert::that(is_array($line), 'Invalid stored posting.');

                $line['side'] = ($line['side'] ?? '') === 'debit' ? 'credit' : 'debit';
                $line['dimensions'] = (object) ($line['dimensions'] ?? []);
                $lines[] = $line;
            }

            $metadata = ['reversal_reason' => $reason];
            $originalMetadata = Row::json($original, 'metadata');
            $cashFlow = $originalMetadata['cash_flow'] ?? null;

            if (is_array($cashFlow) && $cashFlow !== []) {
                $opposite = [];

                foreach ($cashFlow as $category => $minor) {
                    Assert::that(is_string($minor), 'Invalid original cash-flow allocation.');

                    $opposite[$category] = (string) BigInteger::of($minor)->negated();
                }

                $metadata['cash_flow'] = $opposite;
            }

            return $this->insert($this->journalRow($bookId, $key, $fingerprint, $effectiveOn, $reason, $lines, $metadata, $journalId, Row::boolean($original, 'closing'), policyVersions: ['ledger.reversal:v1']));
        }, $bookId);
    }

    public function get(string $bookId, string $journalId): Journal
    {
        return $this->db->run(Permission::Read, fn (): Journal => Journal::fromRow($this->db->find('ledger_journals', $journalId, $bookId)), $bookId);
    }

    /** @return list<JournalLine> */
    public function lines(string $bookId, string $journalId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $journalId): array {
            $book = $this->db->find('ledger_books', $bookId);
            $this->db->find('ledger_journals', $journalId, $bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $currencies = [];
            $lines = [];
            foreach ($this->db->table('ledger_postings')->where('book_id', $bookId)->where('journal_id', $journalId)->orderBy('line_no')->get() as $row) {
                $code = Row::text($row, 'currency_code');
                $currencies[$code] ??= $this->db->currency($code);
                $lines[] = JournalLine::fromRow($row, $currencies[$code], $functional);
            }

            return $lines;
        }, $bookId);
    }

    /** @return list<Journal> */
    public function search(
        string $bookId,
        ?string $from = null,
        ?string $to = null,
        ?string $accountId = null,
        ?string $partyId = null,
        ?string $sourceType = null,
        ?string $text = null,
        ?bool $reversals = null,
        int $limit = 100,
        ?string $afterSequence = null,
        bool $newestFirst = false,
    ): array {
        Assert::that($limit >= 1 && $limit <= 1000, 'Invalid journal page size.');
        Assert::that($afterSequence === null || preg_match('/^\d{1,19}$/D', $afterSequence) === 1, 'Invalid journal cursor.');

        return $this->db->run(Permission::Read, function () use ($bookId, $from, $to, $accountId, $partyId, $sourceType, $text, $reversals, $limit, $afterSequence, $newestFirst): array {
            $this->db->find('ledger_books', $bookId);
            $query = $this->db->table('ledger_journals')->where('book_id', $bookId)->limit($limit)->orderBy('sequence', $newestFirst ? 'desc' : 'asc');
            if ($from !== null) {
                $query->where('effective_on', '>=', Assert::date($from));
            }
            if ($to !== null) {
                $query->where('effective_on', '<=', Assert::date($to));
            }
            if ($afterSequence !== null) {
                $query->where('sequence', $newestFirst ? '<' : '>', $afterSequence);
            }
            if ($sourceType !== null) {
                $query->where('source_type', Assert::text($sourceType, 64));
            }
            if ($reversals !== null) {
                $reversals ? $query->whereNotNull('reverses_id') : $query->whereNull('reverses_id');
            }
            if ($text !== null) {
                $pattern = '%'.addcslashes(Assert::text($text), '\\%_').'%';
                $query->where(fn (Builder $inner) => $inner->where('description', 'ilike', $pattern)->orWhere('source_reference', 'ilike', $pattern));
            }
            if ($accountId !== null || $partyId !== null) {
                $postings = $this->db->table('ledger_postings')->where('book_id', $bookId)->select('journal_id');
                if ($accountId !== null) {
                    $postings->where('account_id', Assert::uuid($accountId));
                }
                if ($partyId !== null) {
                    $postings->where('party_id', Assert::uuid($partyId));
                }
                $query->whereIn('id', $postings);
            }

            return array_values($query->get()->map(fn (stdClass $row): Journal => Journal::fromRow($row))->all());
        }, $bookId);
    }

    public function findBySource(string $bookId, Source $source): ?Journal
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $source): ?Journal {
            $row = $this->db->table('ledger_journals')->where('book_id', Assert::uuid($bookId))
                ->where('source_type', $source->type)->where('source_reference', $source->reference)->first();

            return $row instanceof stdClass ? Journal::fromRow($row) : null;
        }, $bookId);
    }

    public function reversalOf(string $bookId, string $journalId): ?Journal
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $journalId): ?Journal {
            $this->db->find('ledger_journals', $journalId, $bookId);
            $row = $this->db->table('ledger_journals')->where('book_id', $bookId)->where('reverses_id', $journalId)->first();

            return $row instanceof stdClass ? Journal::fromRow($row) : null;
        }, $bookId);
    }

    /** @param array<string, Currency> $registered
     * @return array<string, mixed>
     */
    private function serializePosting(Posting $posting, Currency $functional, string $effectiveOn, array &$registered = []): array
    {
        $code = $posting->amount->currency->code;
        Assert::that(($registered[$code] ??= $this->db->currency($code)) == $posting->amount->currency, 'Posting currency scale differs from the registered scale.');
        $rate = $posting->exchangeRate;

        if ($rate === null) {
            Assert::that($posting->amount->currency == $functional, 'Foreign-currency postings require an explicit exchange rate.');
            $rate = new ExchangeRate($functional, $functional, '1', $effectiveOn, 'identity', Rounding::HalfEven);
        }

        Assert::that($rate->from == $posting->amount->currency && $rate->to == $functional, 'Exchange rate currencies do not match this posting and book.');
        $converted = $posting->valuation ?? $rate->convert($posting->amount);
        Assert::that($converted->currency == $functional, 'Valuation currency differs from the book currency.');

        return ['account_id' => $posting->accountId, 'side' => $posting->side->value, 'amount_minor' => (string) $posting->amount->minor,
            'functional_minor' => (string) $converted->minor, 'currency_code' => $posting->amount->currency->code, 'rate' => $rate->rate,
            'rate_as_of' => $rate->asOf, 'rate_source' => $rate->source, 'rounding' => $rate->rounding->value,
            'party_id' => $posting->partyId, 'due_on' => $posting->dueOn, 'dimensions' => (object) $posting->dimensions,
            'description' => $posting->description, 'valuation' => $posting->valuation !== null];
    }

    private function replay(string $bookId, string $key, string $fingerprint): ?Journal
    {
        $existing = $this->db->table('ledger_journals')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $existing instanceof stdClass) {
            return null;
        }

        Assert::that(hash_equals(Row::text($existing, 'fingerprint'), $fingerprint), 'Idempotency key was already used for a different journal.');

        return Journal::fromRow($existing);
    }

    /** @param array<string, JournalRequest> $chunk
     * @param  array<string, Currency>  $registered
     * @return array<string, string>
     */
    private function postChunk(string $bookId, Currency $currency, array $chunk, array &$registered): array
    {
        $existing = $this->db->table('ledger_journals')->where('book_id', $bookId)->whereIn('idempotency_key', array_map('strval', array_keys($chunk)))
            ->get(['id', 'idempotency_key', 'fingerprint'])->keyBy('idempotency_key');
        $journalIds = [];
        $rows = [];
        foreach ($chunk as $key => $request) {
            $fingerprint = Canonical::hash($request);
            $found = $existing->get($key);
            if ($found instanceof stdClass) {
                Assert::that(hash_equals(Row::text($found, 'fingerprint'), $fingerprint), 'Idempotency key was already used for a different journal: '.$key);
                $journalIds[$key] = Row::text($found, 'id');
            } else {
                $row = $this->requestRow($request, $fingerprint, $currency, $registered);
                $rows[] = $row;
                $journalIds[$key] = $row['id'];
            }
        }
        if ($rows !== []) {
            $reservation = (string) Str::uuid7();
            $this->db->insert('ledger_sequence_reservations', ['id' => $reservation, 'book_id' => $bookId, 'size' => count($rows)]);
            $sequence = BigInteger::of(Row::text($this->db->find('ledger_sequence_reservations', $reservation, $bookId), 'after_sequence'));
            foreach ($rows as $index => $row) {
                $rows[$index]['sequence'] = (string) $sequence->plus($index + 1);
            }
            $this->insertRun($rows);
        }

        return $journalIds;
    }

    /** @param array<string, Currency> $registered
     * @return array{id: string, book_id: string, idempotency_key: string}&array<string, mixed>
     */
    private function requestRow(JournalRequest $request, string $fingerprint, Currency $currency, array &$registered = []): array
    {
        $versions = [];

        foreach ($this->policies as $policy) {
            Assert::text($policy->version(), 128);
            $policy->validate($this->db->scope, $request);
            $versions[] = $policy->version();
        }

        Assert::that(count(array_unique($versions)) === count($versions), 'Posting policy versions must be unique.');
        $lines = [];
        foreach ($request->postings as $posting) {
            $lines[] = $this->serializePosting($posting, $currency, $request->effectiveOn, $registered);
        }

        return $this->journalRow($request->bookId, $request->key, $fingerprint, $request->effectiveOn, $request->description, $lines, $request->metadata, null, $request->closing, $request->source, $versions);
    }

    /** @param list<array<array-key, mixed>> $lines
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $policyVersions
     * @return array{id: string, book_id: string, idempotency_key: string}&array<string, mixed>
     */
    private function journalRow(string $bookId, string $key, string $fingerprint, string $effectiveOn, string $description, array $lines, array $metadata, ?string $reversesId, bool $closing, ?Source $source = null, array $policyVersions = []): array
    {
        return ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
            'effective_on' => $effectiveOn, 'description' => $description, 'postings' => json_encode($lines, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? '{}' : Canonical::json($metadata), 'reverses_id' => $reversesId, 'closing' => $closing,
            'source_type' => $source?->type, 'source_reference' => $source?->reference, 'policy_versions' => Canonical::json($policyVersions)];
    }

    /** @param array{id: string, book_id: string, idempotency_key: string}&array<string, mixed> $row */
    private function insert(array $row): Journal
    {
        $this->db->insert('ledger_journals', $row);

        return Journal::fromRow($this->db->find('ledger_journals', $row['id'], $row['book_id']));
    }

    /** @param list<array{id: string, book_id: string, idempotency_key: string}&array<string, mixed>> $rows */
    private function insertRun(array $rows): void
    {
        try {
            $this->db->connection->transaction(fn () => $this->db->insertMany('ledger_journals', $rows));
        } catch (QueryException) {
            foreach ($rows as $row) {
                try {
                    $this->db->connection->transaction(fn () => $this->db->insert('ledger_journals', $row));
                } catch (QueryException $rejected) {
                    throw new LedgerException('The database rejected journal '.$row['idempotency_key'].'; no journal in the run was posted.', previous: $rejected);
                }
            }
        }
    }
}
