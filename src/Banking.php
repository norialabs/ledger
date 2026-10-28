<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Banking\BankLine;
use Noria\Ledger\Banking\Reconciliation;
use Noria\Ledger\Banking\StatementRequest;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Banking
{
    public function __construct(private ScopedDatabase $db, private ?Journals $journals = null) {}

    private function journals(): Journals
    {
        return $this->journals ?? throw new \LogicException('Posting bank lines requires the journals service.');
    }

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

    /** @return list<BankLine> */
    public function lines(string $bookId, string $statementId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $statementId): array {
            $statement = $this->db->find('ledger_bank_statements', $statementId, $bookId);
            $account = $this->db->find('ledger_accounts', Row::text($statement, 'account_id'), $bookId);
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($this->db->find('ledger_books', $bookId), 'currency_code'));

            return array_map(fn (stdClass $row): BankLine => BankLine::fromRow($row, $currency), array_values($this->db->table('ledger_bank_lines')->where('book_id', $bookId)->where('statement_id', $statementId)->orderBy('line_no')->get()->all()));
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

    /** @return list<array{bank_line_id: string, external_id: string, journal_id: string, line_no: int, amount: Money, score: int, reasons: list<string>}> */
    public function suggestMatches(Snapshot $snapshot, string $statementId, int $windowDays = 7): array
    {
        Assert::that($windowDays >= 0 && $windowDays <= 366, 'Matching window must be between 0 and 366 days.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $statementId, $windowDays): array {
            $book = $this->db->snapshotBook($snapshot);
            $statement = $this->db->find('ledger_bank_statements', $statementId, $snapshot->bookId);
            $account = $this->db->find('ledger_accounts', Row::text($statement, 'account_id'), $snapshot->bookId);
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            $rows = $this->db->connection->select(<<<'SQL'
                WITH matched_lines AS (
                    SELECT bank_line_id, sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END) AS amount FROM ledger_bank_matches
                    WHERE tenant_id = ? AND book_id = ? GROUP BY bank_line_id),
                matched_postings AS (
                    SELECT journal_id, line_no, sum(CASE WHEN reverses_id IS NULL THEN amount_minor ELSE -amount_minor END) AS amount FROM ledger_bank_matches
                    WHERE tenant_id = ? AND book_id = ? GROUP BY journal_id, line_no),
                lines AS (
                    SELECT l.id, l.external_id, l.value_on, l.amount_minor, l.description, abs(l.amount_minor) - coalesce(m.amount, 0) AS remaining
                    FROM ledger_bank_lines l LEFT JOIN matched_lines m ON m.bank_line_id = l.id
                    WHERE l.tenant_id = ? AND l.book_id = ? AND l.statement_id = ?)
                SELECT l.id AS bank_line_id, l.external_id, l.value_on::text AS value_on, l.description AS line_description, p.journal_id, p.line_no, l.remaining::text AS amount,
                    abs(j.effective_on - l.value_on) AS days, j.description AS journal_description, coalesce(j.source_reference, '') AS source_reference, p.description AS posting_description
                FROM lines l
                JOIN ledger_postings p ON p.tenant_id = ? AND p.book_id = ? AND p.account_id = ? AND NOT p.valuation
                    AND p.side = CASE WHEN l.amount_minor > 0 THEN 'debit' ELSE 'credit' END
                JOIN ledger_journals j ON j.tenant_id = p.tenant_id AND j.book_id = p.book_id AND j.id = p.journal_id
                LEFT JOIN matched_postings mp ON mp.journal_id = p.journal_id AND mp.line_no = p.line_no
                WHERE l.remaining > 0 AND p.amount_minor - coalesce(mp.amount, 0) = l.remaining
                  AND j.effective_on BETWEEN l.value_on - ?::integer AND l.value_on + ?::integer
                  AND j.effective_on <= ? AND j.sequence <= ?
                ORDER BY l.value_on, l.id, abs(j.effective_on - l.value_on), j.sequence, p.line_no
                SQL, [$this->db->scope->tenantId, $snapshot->bookId, $this->db->scope->tenantId, $snapshot->bookId, $this->db->scope->tenantId, $snapshot->bookId, $statementId,
                $this->db->scope->tenantId, $snapshot->bookId, Row::text($statement, 'account_id'), $windowDays, $windowDays, $snapshot->effectiveOn, $snapshot->throughSequence]);
            $suggestions = [];
            foreach ($rows as $row) {
                Assert::that($row instanceof stdClass, 'Invalid bank match suggestion.');
                $reasons = ['exact amount'];
                $score = 50;
                $haystack = strtolower(Row::text($row, 'journal_description').' '.Row::text($row, 'source_reference').' '.Row::text($row, 'posting_description'));
                foreach ([Row::text($row, 'external_id')] as $needle) {
                    if (strlen($needle) >= 4 && str_contains($haystack, strtolower($needle))) {
                        $score += 30;
                        $reasons[] = 'reference '.$needle.' found';
                    }
                }
                $days = (int) Row::text($row, 'days');
                $score += max(0, 20 - 3 * $days);
                $reasons[] = $days === 0 ? 'same date' : $days.' days apart';
                $suggestions[] = ['bank_line_id' => Row::text($row, 'bank_line_id'), 'external_id' => Row::text($row, 'external_id'), 'journal_id' => Row::text($row, 'journal_id'),
                    'line_no' => (int) Row::text($row, 'line_no'), 'amount' => Money::minor(Row::text($row, 'amount'), $currency), 'score' => $score, 'reasons' => $reasons];
            }
            usort($suggestions, fn (array $a, array $b): int => [$a['bank_line_id'], -$a['score']] <=> [$b['bank_line_id'], -$b['score']]);

            return $suggestions;
        }, $snapshot->bookId);
    }

    /** @return list<string> */
    public function autoMatch(Snapshot $snapshot, string $statementId, string $effectiveOn, int $minimumScore = 60, int $windowDays = 7): array
    {
        Assert::date($effectiveOn);

        return $this->db->run(Permission::Reconcile, function () use ($snapshot, $statementId, $effectiveOn, $minimumScore, $windowDays): array {
            $byLine = [];
            $byPosting = [];
            foreach ($this->suggestMatches($snapshot, $statementId, $windowDays) as $suggestion) {
                if ($suggestion['score'] >= $minimumScore) {
                    $byLine[$suggestion['bank_line_id']][] = $suggestion;
                    $byPosting[$suggestion['journal_id'].':'.$suggestion['line_no']][] = $suggestion['bank_line_id'];
                }
            }
            $matches = [];
            foreach ($byLine as $lineId => $candidates) {
                $best = $candidates[0];
                $tied = count(array_filter($candidates, fn (array $candidate): bool => $candidate['score'] === $best['score'])) > 1;
                if ($tied || count(array_unique($byPosting[$best['journal_id'].':'.$best['line_no']])) > 1) {
                    continue;
                }
                $matches[] = $this->match($snapshot->bookId, 'auto-match:'.$lineId.':'.$best['journal_id'].':'.$best['line_no'], $lineId, $best['journal_id'], $best['line_no'],
                    $best['amount'], $effectiveOn, 'Automatic match: '.implode(', ', $best['reasons']));
            }

            return $matches;
        }, $snapshot->bookId);
    }

    /** @param array<string, string> $dimensions */
    public function postLine(string $bookId, string $bankLineId, string $counterAccountId, string $reason, ?string $partyId = null, array $dimensions = [], ?string $cashFlow = null, ?ReportingMovement $reportingMovement = null): string
    {
        Assert::text($reason, 1024);
        Assert::that($cashFlow === null || in_array($cashFlow, ['operating', 'investing', 'financing'], true), 'Cash-flow class must be operating, investing or financing.');

        return $this->db->run(Permission::Reconcile, function () use ($bookId, $bankLineId, $counterAccountId, $reason, $partyId, $dimensions, $cashFlow, $reportingMovement): string {
            $book = $this->db->lockBook($bookId);
            $line = $this->db->find('ledger_bank_lines', $bankLineId, $bookId);
            $account = $this->db->find('ledger_accounts', Row::text($line, 'account_id'), $bookId);
            $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
            Assert::that($currency->code === Row::text($book, 'currency_code'), 'Post foreign-currency bank lines with an explicit journal and exchange rate.');
            $signed = Money::minor(Row::text($line, 'amount_minor'), $currency);
            $amount = Money::minor((string) $signed->minor->abs(), $currency);
            $inflow = $signed->minor->isPositive();
            $existing = $this->db->table('ledger_journals')->where('book_id', $bookId)->where('source_type', 'bank_line')->where('source_reference', $bankLineId)->value('id');
            if (is_string($existing)) {
                return $existing;
            }
            $metadata = $cashFlow === null ? [] : ['cash_flow' => [$cashFlow => (string) $signed->minor]];
            $journal = $this->journals()->post(new JournalRequest($bookId, 'bank-line:'.$bankLineId, Row::text($line, 'value_on'), $reason, [
                new Posting(Row::text($line, 'account_id'), $inflow ? Side::Debit : Side::Credit, $amount, dimensions: $dimensions, description: Row::text($line, 'description')),
                new Posting($counterAccountId, $inflow ? Side::Credit : Side::Debit, $amount, partyId: $partyId, dimensions: $dimensions, reportingMovement: $reportingMovement),
            ], $metadata, source: new Source('bank_line', $bankLineId)));
            $this->match($bookId, 'bank-line-match:'.$bankLineId, $bankLineId, $journal->id, 1, $amount, Row::text($line, 'value_on'), $reason);

            return $journal->id;
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
            $this->db->snapshotBook($snapshot);
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
