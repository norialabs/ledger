<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Brick\Math\BigInteger;
use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\Journal;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\PostingPolicy;
use NoriaLabs\Ledger\Accounting\Source;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Rounding;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Journals
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
        Assert::text($reason, 1000);

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
        $id = (string) Str::uuid();
        $this->db->insert('ledger_journals', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
            'effective_on' => $effectiveOn, 'description' => $description, 'postings' => json_encode($lines, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? '{}' : Canonical::json($metadata), 'reverses_id' => $reversesId, 'closing' => $closing,
            'source_type' => $source?->type, 'source_reference' => $source?->reference, 'policy_versions' => Canonical::json($policyVersions)]);

        return Journal::fromRow($this->db->find('ledger_journals', $id, $bookId));
    }
}
