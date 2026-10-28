<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Tests\Support\PermitAll;

it('prevents cash transactions from being disguised as closing entries', function () {
    $amount = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fake-close', '2026-12-31', 'Invalid closing entry', [
        new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount),
    ], closing: true));
})->throws(QueryException::class, 'only transfer earnings to equity');

it('allows closing and reversing balances on an inactive historical income account', function () {
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $amount = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'historical-income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $this->ledger->catalog->setAccountActive($this->book, $this->income, false);
    $this->ledger->yearEnd->close($this->book, 'close-inactive', '2026-01-01', '2026-12-31', $retained, 'Annual close');
    $this->storage->run(Permission::Close, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    expect($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-12-31'))['equity']->decimal())->toBe('100.00');
});

it('preserves tiny foreign movements that round to zero functional units', function () {
    $jpy = Currency::iso('JPY');
    $usd = Currency::iso('USD');
    $book = $this->ledger->catalog->book($this->entity, 'JPY functional book', $jpy);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $first = $this->ledger->catalog->account($book, '1000', 'First USD account', AccountType::Asset, $usd);
    $second = $this->ledger->catalog->account($book, '1001', 'Second USD account', AccountType::Asset, $usd);
    $rate = new ExchangeRate($usd, $jpy, '1', '2026-01-01', 'precision-fixture', Rounding::HalfEven);
    $amount = Money::of('0.01', $usd);
    $this->ledger->journals->post(new JournalRequest($book, 'tiny-transfer', '2026-01-01', 'Tiny transfer', [new Posting($first, Side::Debit, $amount, $rate), new Posting($second, Side::Credit, $amount, $rate)]));
    $entries = $this->ledger->reports->generalLedger($this->ledger->reports->snapshot($book, '2026-01-31'));
    expect((string) $entries[0]->line->amount->minor)->toBe('1');
    expect((string) $entries[0]->line->functional->minor)->toBe('0');
    expect($entries[0]->line->amount->currency->code)->toBe('USD')
        ->and($entries[0]->line->functional->currency->code)->toBe('JPY');
});

it('stores the versions of applied host posting policies', function () {
    $policy = new class implements PostingPolicy
    {
        public function version(): string
        {
            return 'independent-review:v1';
        }

        public function validate(Scope $scope, JournalRequest $request): void
        {
            if (($request->metadata['approval_reference'] ?? null) !== 'APPROVED-1') {
                throw new RuntimeException('A reviewed approval is required.');
            }
        }
    };
    $ledger = (new Ledger($this->connection(), new PermitAll, [$policy]))->for($this->scope);
    $money = Money::of('100', $this->kes);
    $journal = $ledger->journals->post(new JournalRequest($this->book, 'reviewed', '2026-01-01', 'Reviewed receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)], ['approval_reference' => 'APPROVED-1']));
    $this->storage->run(Permission::Read, function () use ($journal): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        expect(json_decode(text($row->policy_versions), true, 512, JSON_THROW_ON_ERROR))->toBe(['independent-review:v1']);
        expect($row->actor_id)->toBe($this->scope->actorId);
        expect($row->firm_id)->toBe($this->scope->firmId);
    }, $this->book);
});

it('returns zero statements for an empty newly configured book', function () {
    $book = $this->ledger->catalog->book($this->entity, 'Empty book', $this->kes);
    $snapshot = $this->ledger->reports->snapshot($book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('0.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
});

it('rejects classifying an income account as cash', function () {
    $this->ledger->catalog->account($this->book, '4999', 'Invalid cash mapping', AccountType::Income, cashFlow: 'cash');
})->throws(QueryException::class, 'Cash classification requires');

it('requires supplier bills to use liability control accounts', function () {
    $party = $this->ledger->catalog->party($this->entity, 'supplier', 'Supplier');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expense', AccountType::Expense);
    $amount = Money::of('100', $this->kes);
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'wrong-control', '2026-01-01', new Source('supplier.bill', 'INVALID-1'), DocumentKind::PurchaseBill,
        $ar, [new Obligation($party, $amount, '2026-01-31')], [new Charge($expense, $amount, 'Supplies')]));
})->throws(LedgerException::class, 'purchase documents require a liability');
