<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalLine;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\Source;
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
    /** @param list<PostingPolicy> $policies */
    public function __construct(private ScopedDatabase $db, private array $policies = []) {}

    public function post(JournalRequest $request): Journal
    {
        Assert::that($request->reversesId === null, 'Use reverse() to reverse a journal.');

        return $this->db->run($request->closing ? Permission::Close : Permission::Post, function () use ($request): Journal {
            $book = $this->db->lockBook($request->bookId);
            $fingerprint = Canonical::hash($request);
            $existing = $this->replay($request->bookId, $request->key, $fingerprint);

            if ($existing !== null) {
                return $existing;
            }

            $versions = [];

            foreach ($this->policies as $policy) {
                Assert::text($policy->version(), 128);
                $policy->validate($this->db->scope, $request);
                $versions[] = $policy->version();
            }

            Assert::that(count(array_unique($versions)) === count($versions), 'Posting policy versions must be unique.');
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $lines = array_values(array_map(fn (Posting $posting): array => $this->serializePosting($posting, $currency, $request->effectiveOn), $request->postings));

            return $this->insert($request->bookId, $request->key, $fingerprint, $request->effectiveOn, $request->description, $lines, $request->metadata, null, $request->closing, $request->source, $versions);
        }, $request->bookId);
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

                if (is_array($line)) {
                    $line['side'] = ($line['side'] ?? '') === 'debit' ? 'credit' : 'debit';
                    $line['dimensions'] = (object) ($line['dimensions'] ?? []);
                    $lines[] = $line;
                }
            }

            $metadata = ['reversal_reason' => $reason];
            $originalMetadata = Row::json($original, 'metadata');
            $cashFlow = $originalMetadata['cash_flow'] ?? null;

            if (is_array($cashFlow) && $cashFlow !== []) {
                $opposite = [];

                foreach ($cashFlow as $category => $minor) {
                    Assert::that(is_string($minor), 'Invalid original cash-flow allocation.');

                    if (is_string($minor)) {
                        $opposite[$category] = (string) BigInteger::of($minor)->negated();
                    }
                }

                $metadata['cash_flow'] = $opposite;
            }

            return $this->insert($bookId, $key, $fingerprint, $effectiveOn, $reason, $lines, $metadata, $journalId, Row::boolean($original, 'closing'), policyVersions: ['ledger.reversal:v1']);
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

    /** @return array<string, mixed> */
    private function serializePosting(Posting $posting, Currency $functional, string $effectiveOn): array
    {
        Assert::that($this->db->currency($posting->amount->currency->code) == $posting->amount->currency, 'Posting currency scale differs from the registered scale.');
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

    /** @param list<array<array-key, mixed>> $lines
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $policyVersions
     */
    private function insert(string $bookId, string $key, string $fingerprint, string $effectiveOn, string $description, array $lines, array $metadata, ?string $reversesId, bool $closing, ?Source $source = null, array $policyVersions = []): Journal
    {
        $id = (string) Str::uuid7();
        $this->db->insert('ledger_journals', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
            'effective_on' => $effectiveOn, 'description' => $description, 'postings' => json_encode($lines, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? '{}' : Canonical::json($metadata), 'reverses_id' => $reversesId, 'closing' => $closing,
            'source_type' => $source?->type, 'source_reference' => $source?->reference, 'policy_versions' => Canonical::json($policyVersions)]);

        return Journal::fromRow($this->db->find('ledger_journals', $id, $bookId));
    }
}
