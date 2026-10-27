<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Banking\Reconciliation;
use Noria\Ledger\Banking\StatementRequest;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Banking
{
    public function __construct(private ScopedDatabase $db) {}

    public function import(StatementRequest $request): string
    {
        return $this->db->run(Permission::Reconcile, function () use ($request): string {
            $book = $this->db->lockBook($request->bookId);
            $account = $this->db->find('ledger_accounts', $request->accountId, $request->bookId);
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            Assert::that($currency == $request->opening->currency, 'Bank statement currency or scale mismatch.');
            $fingerprint = Canonical::hash($request);
            $existing = $this->db->table('ledger_bank_statements')->where('book_id', $request->bookId)->where('account_id', $request->accountId)->where('reference', $request->reference)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Statement reference was already imported with different content.');

                return Row::text($existing, 'id');
            }

            $id = (string) Str::uuid7();
            $this->db->insert('ledger_bank_statements', ['id' => $id, 'book_id' => $request->bookId, 'account_id' => $request->accountId,
                'reference' => $request->reference, 'fingerprint' => $fingerprint, 'starts_on' => $request->startsOn, 'effective_on' => $request->endsOn,
                'opening_minor' => (string) $request->opening->minor, 'closing_minor' => (string) $request->closing->minor, 'movements' => Canonical::json($request->lines)]);

            return $id;
        }, $request->bookId);
    }

    /** @return list<stdClass> */
    public function lines(string $bookId, string $statementId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $statementId): array {
            $this->db->find('ledger_bank_statements', $statementId, $bookId);

            return array_values($this->db->table('ledger_bank_lines')->where('book_id', $bookId)->where('statement_id', $statementId)->orderBy('line_no')->get()->all());
        }, $bookId);
    }

    public function match(string $bookId, string $key, string $bankLineId, string $journalId, int $lineNo, Money $amount, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        Assert::that($amount->minor->isPositive() && $lineNo > 0, 'Match requires a positive amount and posting line number.');

        return $this->db->run(Permission::Reconcile, function () use ($bookId, $key, $bankLineId, $journalId, $lineNo, $amount, $effectiveOn, $reason): string {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$bankLineId, $journalId, $lineNo, $amount, $effectiveOn, $reason]);
            $replayed = $this->replay($bookId, $key, $fingerprint);

            if ($replayed !== null) {
                return $replayed;
            }

            $line = $this->db->find('ledger_bank_lines', $bankLineId, $bookId);
            $account = $this->db->find('ledger_accounts', Row::text($line, 'account_id'), $bookId);
            Assert::that($this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code')) == $amount->currency, 'Match currency or scale mismatch.');
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_bank_matches', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'bank_line_id' => $bankLineId, 'journal_id' => $journalId, 'line_no' => $lineNo, 'amount_minor' => (string) $amount->minor,
                'effective_on' => $effectiveOn, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    public function release(string $bookId, string $matchId, string $key, string $effectiveOn, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Reconcile, function () use ($bookId, $matchId, $key, $effectiveOn, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$matchId, $effectiveOn, $reason]);
            $replayed = $this->replay($bookId, $key, $fingerprint);

            if ($replayed !== null) {
                return $replayed;
            }

            $original = $this->db->find('ledger_bank_matches', $matchId, $bookId);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_bank_matches', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'bank_line_id' => Row::text($original, 'bank_line_id'), 'journal_id' => Row::text($original, 'journal_id'),
                'line_no' => Row::text($original, 'line_no'), 'amount_minor' => Row::text($original, 'amount_minor'),
                'effective_on' => $effectiveOn, 'reason' => $reason, 'reverses_id' => $matchId]);

            return $id;
        }, $bookId);
    }

    public function reconciliation(Snapshot $snapshot, string $statementId): Reconciliation
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $statementId): Reconciliation {
            $statement = $this->db->find('ledger_bank_statements', $statementId, $snapshot->bookId);
            Assert::that(Row::text($statement, 'effective_on') === $snapshot->effectiveOn, 'Reconciliation snapshot must end on the statement closing date.');
            Assert::that((int) Row::text($statement, 'sequence') <= (int) $snapshot->throughSequence, 'Statement was not known at this snapshot.');
            Assert::that(! $this->db->table('ledger_bank_voids')->where('book_id', $snapshot->bookId)->where('statement_id', $statementId)->where('sequence', '<=', $snapshot->throughSequence)->exists(), 'Statement was voided at this snapshot.');
            $account = $this->db->find('ledger_accounts', Row::text($statement, 'account_id'), $snapshot->bookId);
            $book = $this->db->find('ledger_books', $snapshot->bookId);
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            $matches = $this->db->table('ledger_bank_matches')->where('book_id', $snapshot->bookId)->where('effective_on', '<=', $snapshot->effectiveOn)->where('sequence', '<=', $snapshot->throughSequence);
            $matches->whereNotExists(function (Builder $query) use ($snapshot): void {
                $query->selectRaw('1')->from('ledger_bank_voids AS v')->join('ledger_bank_lines AS bl', function (JoinClause $join): void {
                    $join->on('bl.tenant_id', '=', 'v.tenant_id')->on('bl.book_id', '=', 'v.book_id')->on('bl.statement_id', '=', 'v.statement_id');
                })->whereColumn('bl.tenant_id', 'ledger_bank_matches.tenant_id')->whereColumn('bl.book_id', 'ledger_bank_matches.book_id')
                    ->whereColumn('bl.id', 'ledger_bank_matches.bank_line_id')->where('v.sequence', '<=', $snapshot->throughSequence);
            });
            $byPosting = (clone $matches)->select('journal_id', 'line_no')->selectRaw('sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END) AS amount')->groupBy('journal_id', 'line_no');
            $byBank = (clone $matches)->select('bank_line_id')->selectRaw('sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END) AS amount')->groupBy('bank_line_id');
            $ledgerTotals = $this->db->table('ledger_postings')->join('ledger_journals AS j', function (JoinClause $join): void {
                $join->on('j.tenant_id', '=', 'ledger_postings.tenant_id')->on('j.book_id', '=', 'ledger_postings.book_id')->on('j.id', '=', 'ledger_postings.journal_id');
            })->leftJoinSub($byPosting, 'm', function (JoinClause $join): void {
                $join->on('m.journal_id', '=', 'ledger_postings.journal_id')->on('m.line_no', '=', 'ledger_postings.line_no');
            })->where('ledger_postings.book_id', $snapshot->bookId)->where('ledger_postings.account_id', Row::text($statement, 'account_id'))
                ->where('j.effective_on', '<=', $snapshot->effectiveOn)->where('j.sequence', '<=', $snapshot->throughSequence)
                ->selectRaw("coalesce(sum(CASE WHEN side = 'debit' THEN amount_minor ELSE -amount_minor END), 0)::text AS balance")
                ->selectRaw("coalesce(sum(CASE WHEN side = 'debit' THEN amount_minor - coalesce(m.amount, 0) ELSE -amount_minor + coalesce(m.amount, 0) END), 0)::text AS unmatched")->first();
            $bankTotals = $this->db->table('ledger_bank_lines')->join('ledger_bank_statements AS s', function (JoinClause $join): void {
                $join->on('s.tenant_id', '=', 'ledger_bank_lines.tenant_id')->on('s.book_id', '=', 'ledger_bank_lines.book_id')->on('s.id', '=', 'ledger_bank_lines.statement_id');
            })->leftJoinSub($byBank, 'm', fn (JoinClause $join) => $join->on('m.bank_line_id', '=', 'ledger_bank_lines.id'))
                ->where('ledger_bank_lines.book_id', $snapshot->bookId)->where('ledger_bank_lines.account_id', Row::text($statement, 'account_id'))
                ->where('ledger_bank_lines.value_on', '<=', $snapshot->effectiveOn)->where('s.sequence', '<=', $snapshot->throughSequence)
                ->whereNotExists(function (Builder $query) use ($snapshot): void {
                    $query->selectRaw('1')->from('ledger_bank_voids AS v')->whereColumn('v.tenant_id', 's.tenant_id')->whereColumn('v.book_id', 's.book_id')
                        ->whereColumn('v.statement_id', 's.id')->where('v.sequence', '<=', $snapshot->throughSequence);
                })
                ->selectRaw('coalesce(sum(ledger_bank_lines.amount_minor - sign(ledger_bank_lines.amount_minor) * coalesce(m.amount, 0)), 0)::text AS unmatched')->first();
            $balance = Money::minor($ledgerTotals instanceof stdClass ? Row::text($ledgerTotals, 'balance') : '0', $currency);
            $statementBalance = Money::minor(Row::text($statement, 'closing_minor'), $currency);
            $unmatchedLedger = Money::minor($ledgerTotals instanceof stdClass ? Row::text($ledgerTotals, 'unmatched') : '0', $currency);
            $unmatchedBank = Money::minor($bankTotals instanceof stdClass ? Row::text($bankTotals, 'unmatched') : '0', $currency);

            return new Reconciliation($balance, $statementBalance, $unmatchedLedger, $unmatchedBank, $balance->minus($statementBalance)->minus($unmatchedLedger)->plus($unmatchedBank));
        }, $snapshot->bookId);
    }

    public function voidStatement(string $bookId, string $statementId, string $effectiveOn, string $reason): string
    {
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Reconcile, function () use ($bookId, $statementId, $effectiveOn, $reason): string {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_bank_statements', $statementId, $bookId);
            $existing = $this->db->table('ledger_bank_voids')->where('book_id', $bookId)->where('statement_id', $statementId)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'reason') === $reason && Row::text($existing, 'effective_on') === $effectiveOn, 'Statement was already voided with another reason or date.');

                return Row::text($existing, 'id');
            }

            $id = (string) Str::uuid7();
            $this->db->insert('ledger_bank_voids', ['id' => $id, 'book_id' => $bookId, 'statement_id' => $statementId, 'effective_on' => $effectiveOn, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    private function replay(string $bookId, string $key, string $fingerprint): ?string
    {
        $row = $this->db->table('ledger_bank_matches')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        Assert::that(Row::text($row, 'fingerprint') === $fingerprint, 'Bank match key was already used with different content.');

        return Row::text($row, 'id');
    }
}
