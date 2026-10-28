<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Monetary\Valuation;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class MonetaryAccounts
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    public function register(string $bookId, string $accountId, string $policyVersion): string
    {
        Assert::text($policyVersion, 128);

        return $this->db->run(Permission::Configure, function () use ($bookId, $accountId, $policyVersion): string {
            $this->db->lockBook($bookId);
            $existing = $this->db->table('ledger_monetary_accounts')->where('book_id', $bookId)->where('account_id', $accountId)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'policy_version') === $policyVersion, 'Monetary registration cannot change its eligibility policy.');

                return Row::text($existing, 'id');
            }

            $id = (string) Str::uuid7();
            $this->db->insert('ledger_monetary_accounts', ['id' => $id, 'book_id' => $bookId, 'account_id' => $accountId, 'policy_version' => $policyVersion]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, string> $dimensions */
    public function revalue(string $bookId, string $accountId, string $key, string $date, ExchangeRate $rate, string $gainAccountId, string $lossAccountId, string $reason, array $dimensions = []): Valuation
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Revalue, function () use ($bookId, $accountId, $key, $date, $rate, $gainAccountId, $lossAccountId, $reason, $dimensions): Valuation {
            $book = $this->db->lockBook($bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $account = $this->db->find('ledger_accounts', $accountId, $bookId);
            $source = $this->db->currency(Row::text($account, 'currency_code'));
            $registration = $this->registration($bookId, $accountId);
            Assert::that($rate->from == $source && $rate->to == $functional && $rate->asOf <= $date, 'Monetary valuation requires the correct approved foreign-to-functional rate.');
            $fingerprint = Canonical::hash([$accountId, $date, $rate, $gainAccountId, $lossAccountId, $reason, $dimensions]);
            $existing = $this->replay($bookId, $key, $fingerprint, $source, $functional);

            if ($existing !== null) {
                return $existing;
            }

            $quote = $this->quote($bookId, $accountId, $date, $dimensions);
            $sourceBalance = Money::minor(Row::text($quote, 'source_minor'), $source);
            $before = Money::minor(Row::text($quote, 'before_minor'), $functional);
            $delta = $rate->convert($sourceBalance)->minus($before);
            $id = (string) Str::uuid7();
            $journalId = null;

            if (! $delta->minor->isZero()) {
                $gain = $delta->minor->isPositive();
                $counterId = $gain ? $gainAccountId : $lossAccountId;
                $counter = $this->db->find('ledger_accounts', $counterId, $bookId);
                Assert::that(Row::text($counter, 'type') === ($gain ? 'income' : 'expense'), 'Monetary valuation requires the correct gain or loss account.');
                $absolute = Money::minor((string) $delta->minor->abs(), $functional);
                $metadata = ['monetary_valuation_id' => $id, 'eligibility_policy' => Row::text($registration, 'policy_version')];

                if (Row::nullable($account, 'cash_flow') === 'cash') {
                    $metadata['cash_flow'] = ['exchange' => (string) $delta->minor];
                }

                $journalId = $this->journals->post(new JournalRequest($bookId, 'monetary:'.hash('sha256', $key), $date, $reason, [
                    new Posting($accountId, $gain ? Side::Debit : Side::Credit, Money::minor('0', $source), $rate, dimensions: $dimensions, valuation: $absolute),
                    new Posting($counterId, $gain ? Side::Credit : Side::Debit, $absolute, dimensions: $dimensions),
                ], $metadata, source: new Source('monetary.valuation', $id)))->id;
            }

            $this->db->insert('ledger_monetary_valuations', ['id' => $id, 'book_id' => $bookId, 'account_id' => $accountId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $date, 'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions),
                'source_minor' => (string) $sourceBalance->minor, 'before_minor' => (string) $before->minor, 'delta_minor' => (string) $delta->minor,
                'rate' => $rate->rate, 'rate_as_of' => $rate->asOf, 'rate_source' => $rate->source, 'rounding' => $rate->rounding->value, 'journal_id' => $journalId, 'reason' => $reason]);

            return new Valuation($id, $journalId, $sourceBalance, $before, $delta);
        }, $bookId);
    }

    /** @param array<string, string> $dimensions */
    public function realize(string $bookId, string $journalId, string $accountId, string $gainAccountId, string $lossAccountId, array $dimensions = []): ?Valuation
    {
        return $this->db->run(Permission::Revalue, function () use ($bookId, $journalId, $accountId, $gainAccountId, $lossAccountId, $dimensions): ?Valuation {
            $book = $this->db->lockBook($bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $account = $this->db->find('ledger_accounts', $accountId, $bookId);
            $source = $this->db->currency(Row::text($account, 'currency_code'));
            $registration = $this->registration($bookId, $accountId);
            $journal = $this->db->find('ledger_journals', $journalId, $bookId);
            ksort($dimensions);
            $key = 'realized:'.hash('sha256', $journalId.':'.$accountId.':'.Canonical::json($dimensions));
            $fingerprint = Canonical::hash(['realize', $journalId, $accountId, $gainAccountId, $lossAccountId, $dimensions]);
            $existing = $this->replay($bookId, $key, $fingerprint, $source, $functional);
            if ($existing !== null) {
                return $existing;
            }
            $bucket = fn (): Builder => $this->db->connection->table('ledger_postings AS p')
                ->join('ledger_journals AS j', fn (JoinClause $join) => $join->on('j.tenant_id', '=', 'p.tenant_id')->on('j.book_id', '=', 'p.book_id')->on('j.id', '=', 'p.journal_id'))
                ->where('p.tenant_id', $this->db->scope->tenantId)->where('p.book_id', $bookId)->where('p.account_id', $accountId)
                ->whereRaw('p.dimensions = ?::jsonb', [$dimensions === [] ? '{}' : Canonical::json($dimensions)])
                ->selectRaw("coalesce(sum(CASE WHEN p.side = 'debit' THEN p.amount_minor ELSE -p.amount_minor END), 0)::text AS source")
                ->selectRaw("coalesce(sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END), 0)::text AS functional");
            $prior = $bucket()->where(fn (Builder $q) => $q->where('j.effective_on', '<', Row::text($journal, 'effective_on'))
                ->orWhere(fn (Builder $q) => $q->where('j.effective_on', Row::text($journal, 'effective_on'))->where('j.sequence', '<', Row::text($journal, 'sequence'))))->first();
            $moved = $bucket()->where('p.journal_id', $journalId)->first();
            $priorSource = BigInteger::of($prior instanceof stdClass ? Row::text($prior, 'source') : '0');
            $priorFunctional = BigInteger::of($prior instanceof stdClass ? Row::text($prior, 'functional') : '0');
            $movedSource = BigInteger::of($moved instanceof stdClass ? Row::text($moved, 'source') : '0');
            $movedFunctional = BigInteger::of($moved instanceof stdClass ? Row::text($moved, 'functional') : '0');
            if ($priorSource->isZero() || $movedSource->isZero() || $priorSource->getSign() === $movedSource->getSign()) {
                return null;
            }
            Assert::that($movedSource->abs()->isLessThanOrEqualTo($priorSource->abs()), 'The outflow exceeds the foreign balance it draws on.');
            $share = BigRational::of($priorFunctional)->multipliedBy($movedSource)->dividedBy($priorSource)->toScale(0, Rounding::HalfEven->brick())->toBigInteger();
            $delta = Money::minor((string) $share->minus($movedFunctional), $functional);
            $line = $this->db->table('ledger_postings')->where('book_id', $bookId)->where('journal_id', $journalId)->where('account_id', $accountId)->orderBy('line_no')->first();
            Assert::that($line instanceof stdClass, 'The journal does not move this monetary account.');
            $line = $line instanceof stdClass ? $line : new stdClass;
            $rate = new ExchangeRate($source, $functional, Row::text($line, 'rate'), Row::text($line, 'rate_as_of'), Row::text($line, 'rate_source'), Rounding::from(Row::text($line, 'rounding')));
            $date = Row::text($journal, 'effective_on');
            $id = (string) Str::uuid7();
            $valuationJournal = null;
            if (! $delta->minor->isZero()) {
                $gain = $delta->minor->isPositive();
                $counterId = $gain ? $gainAccountId : $lossAccountId;
                $counter = $this->db->find('ledger_accounts', $counterId, $bookId);
                Assert::that(Row::text($counter, 'type') === ($gain ? 'income' : 'expense'), 'Monetary valuation requires the correct gain or loss account.');
                $absolute = Money::minor((string) $delta->minor->abs(), $functional);
                $metadata = ['monetary_valuation_id' => $id, 'eligibility_policy' => Row::text($registration, 'policy_version'), 'realized_from_journal_id' => $journalId];
                if (Row::nullable($account, 'cash_flow') === 'cash') {
                    $metadata['cash_flow'] = ['exchange' => (string) $delta->minor];
                }
                $valuationJournal = $this->journals->post(new JournalRequest($bookId, 'monetary-realized:'.hash('sha256', $key), $date, 'Realized exchange difference', [
                    new Posting($accountId, $gain ? Side::Debit : Side::Credit, Money::minor('0', $source), $rate, dimensions: $dimensions, valuation: $absolute),
                    new Posting($counterId, $gain ? Side::Credit : Side::Debit, $absolute, dimensions: $dimensions),
                ], $metadata, source: new Source('monetary.valuation', $id)))->id;
            }
            $this->db->insert('ledger_monetary_valuations', ['id' => $id, 'book_id' => $bookId, 'account_id' => $accountId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $date, 'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions),
                'source_minor' => (string) $priorSource, 'before_minor' => (string) $priorFunctional, 'delta_minor' => (string) $delta->minor,
                'rate' => $rate->rate, 'rate_as_of' => $rate->asOf, 'rate_source' => $rate->source, 'rounding' => $rate->rounding->value,
                'journal_id' => $valuationJournal, 'kind' => 'realized', 'trigger_journal_id' => $journalId, 'reason' => 'Realized exchange difference']);

            return $this->fromRow($this->db->find('ledger_monetary_valuations', $id, $bookId), $source, $functional);
        }, $bookId);
    }

    /** @return array{journal: Journal, valuations: list<Valuation>} */
    public function postRealized(JournalRequest $request, string $gainAccountId, string $lossAccountId): array
    {
        return $this->db->run(Permission::Revalue, function () use ($request, $gainAccountId, $lossAccountId): array {
            $journal = $this->journals->post($request);
            $valuations = [];
            $buckets = [];
            foreach ($request->postings as $posting) {
                $dimensions = $posting->dimensions;
                ksort($dimensions);
                $buckets[$posting->accountId.'|'.Canonical::json($dimensions)] = [$posting->accountId, $dimensions];
            }
            foreach ($buckets as [$accountId, $dimensions]) {
                if ($this->db->table('ledger_monetary_accounts')->where('book_id', $request->bookId)->where('account_id', $accountId)->exists()) {
                    $valuation = $this->realize($request->bookId, $journal->id, $accountId, $gainAccountId, $lossAccountId, $dimensions);
                    if ($valuation !== null) {
                        $valuations[] = $valuation;
                    }
                }
            }

            return ['journal' => $journal, 'valuations' => $valuations];
        }, $request->bookId);
    }

    public function reverse(string $bookId, string $valuationId, string $key, string $date, string $reason): Valuation
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Revalue, function () use ($bookId, $valuationId, $key, $date, $reason): Valuation {
            $book = $this->db->lockBook($bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $original = $this->db->find('ledger_monetary_valuations', $valuationId, $bookId);
            $accountId = Row::text($original, 'account_id');
            $account = $this->db->find('ledger_accounts', $accountId, $bookId);
            $source = $this->db->currency(Row::text($account, 'currency_code'));
            $fingerprint = Canonical::hash(['reverse', $valuationId, $date, $reason]);
            $existing = $this->replay($bookId, $key, $fingerprint, $source, $functional);

            if ($existing !== null) {
                return $existing;
            }

            $dimensions = Row::json($original, 'dimensions');
            $quote = $this->quote($bookId, $accountId, $date, $dimensions);
            $delta = Money::minor(Row::text($original, 'delta_minor'), $functional)->negated();
            $journalId = Row::nullable($original, 'journal_id');
            $journal = $journalId === null ? null : $this->journals->reverse($bookId, $journalId, 'monetary-reverse:'.hash('sha256', $key), $date, $reason);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_monetary_valuations', ['id' => $id, 'book_id' => $bookId, 'account_id' => $accountId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $date, 'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions),
                'source_minor' => Row::text($quote, 'source_minor'), 'before_minor' => Row::text($quote, 'before_minor'), 'delta_minor' => (string) $delta->minor,
                'rate' => Row::text($original, 'rate'), 'rate_as_of' => Row::text($original, 'rate_as_of'), 'rate_source' => Row::text($original, 'rate_source'),
                'rounding' => Row::text($original, 'rounding'), 'journal_id' => $journal?->id, 'reason' => $reason, 'reverses_id' => $valuationId,
                'kind' => Row::text($original, 'kind'), 'trigger_journal_id' => Row::nullable($original, 'trigger_journal_id')]);

            return $this->fromRow($this->db->find('ledger_monetary_valuations', $id, $bookId), $source, $functional);
        }, $bookId);
    }

    /** @param array<array-key, mixed> $dimensions */
    private function quote(string $bookId, string $accountId, string $date, array $dimensions): stdClass
    {
        $quote = $this->db->connection->selectOne('SELECT ledger_monetary_quote(?::uuid, ?::uuid, ?::uuid, ?::date, ?::jsonb, NULL)::text AS quote',
            [$this->db->scope->tenantId, $bookId, $accountId, $date, $dimensions === [] ? '{}' : Canonical::json($dimensions)]);
        Assert::that($quote instanceof stdClass, 'Monetary balance quote is missing.');

        return (object) ($quote instanceof stdClass ? Row::json($quote, 'quote') : []);
    }

    private function registration(string $bookId, string $accountId): stdClass
    {
        $row = $this->db->table('ledger_monetary_accounts')->where('book_id', $bookId)->where('account_id', $accountId)->first();
        Assert::that($row instanceof stdClass, 'The account requires an explicit monetary eligibility policy.');

        return $row instanceof stdClass ? $row : new stdClass;
    }

    private function replay(string $bookId, string $key, string $fingerprint, Currency $source, Currency $functional): ?Valuation
    {
        $existing = $this->db->table('ledger_monetary_valuations')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $existing instanceof stdClass) {
            return null;
        }

        Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Monetary valuation key was already used with different content.');

        return $this->fromRow($existing, $source, $functional);
    }

    private function fromRow(stdClass $row, Currency $source, Currency $functional): Valuation
    {
        return new Valuation(Row::text($row, 'id'), Row::nullable($row, 'journal_id'), Money::minor(Row::text($row, 'source_minor'), $source),
            Money::minor(Row::text($row, 'before_minor'), $functional), Money::minor(Row::text($row, 'delta_minor'), $functional));
    }
}
