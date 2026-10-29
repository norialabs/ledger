<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\RateType;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Scheduling\Recurrence;
use Noria\Ledger\Scheduling\Schedule;
use Noria\Ledger\Session;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tax\IncomeTaxRequest;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;

it('retains source reporting evidence across recurring occurrences and their automatic reversals', function () {
    $movement = new ReportingMovement('oci', 'reviewed:1', 'Reviewed recurring source', 'recyclable');
    $book = $this->ledger->catalog->book($this->entity, 'Monthly reporting', $this->kes);
    $this->ledger->periods->open($book, 'January', '2026-01-01', '2026-01-31');
    $this->ledger->periods->open($book, 'February', '2026-02-01', '2026-02-28');
    $cash = $this->ledger->catalog->account($book, '1000', 'Cash', AccountType::Asset);
    $reserve = $this->ledger->catalog->account($book, '3100', 'OCI reserve', AccountType::Equity);
    $this->ledger->schedules->recurring($book, 'reviewed-recurring', 'Reviewed recurring source', [
        new Posting($cash, Side::Debit, Money::of('10', $this->kes)), new Posting($reserve, Side::Credit, Money::of('10', $this->kes), reportingMovement: $movement),
    ], Recurrence::monthly('2026-01-31', occurrences: 1), autoReverse: true);
    $journals = $this->ledger->schedules->postRecurring($book, '2026-02-01');
    expect($journals)->toHaveCount(2);
    foreach ($journals as $journal) {
        expect($this->ledger->journals->lines($book, $journal->id)[1]->reportingMovement)->toEqual($movement);
    }
});

it('closes income and expense into retained earnings while preserving profit reports', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expenses', AccountType::Expense);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->book, 'year-activity', '2026-01-01', 'Activity', [
        new Posting($this->cash, Side::Debit, Money::of('70', $this->kes)),
        new Posting($expense, Side::Debit, Money::of('30', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $id = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Approved annual close');
    expect($this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Approved annual close'))->toBe($id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$this->income]->closing->decimal())->toBe('0.00');
    expect($balances[$expense]->closing->decimal())->toBe('0.00');
    expect($balances[$retained]->closing->decimal())->toBe('-70.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('70.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
});

it('posts an immutable revenue recognition schedule exactly once per period', function () {
    $deferred = $this->ledger->catalog->account($this->book, '2200', 'Deferred income', AccountType::Liability);
    $plan = Schedule::equal(Money::of('100', $this->kes), ['2026-01-31', '2026-02-28', '2026-03-31']);
    $id = $this->ledger->schedules->create($this->book, 'tuition-recognition', 'Recognize tuition', $deferred, $this->income, $plan, 'tuition:v1');
    expect($this->ledger->schedules->postDue($this->book, $id, '2026-02-28'))->toHaveCount(2);
    expect($this->ledger->schedules->postDue($this->book, $id, '2026-02-28'))->toBe([]);
    expect($this->ledger->schedules->postDue($this->book, $id, '2026-03-31'))->toHaveCount(1);
    $profit = $this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-03-31'), '2026-01-01');
    expect($profit['income']->decimal())->toBe('100.00');
});

/** @return array{string, string, string} */
function monthlyBook(Session $ledger, string $entity, Currency $currency): array
{
    $book = $ledger->catalog->book($entity, 'Monthly', $currency);
    foreach (['01' => '31', '02' => '28', '03' => '31', '04' => '30'] as $month => $last) {
        $ledger->periods->open($book, '2026-'.$month, '2026-'.$month.'-01', '2026-'.$month.'-'.$last);
    }

    return [$book, $ledger->catalog->account($book, '5100', 'Rent', AccountType::Expense), $ledger->catalog->account($book, '2100', 'Accrued rent', AccountType::Liability)];
}

/** @return array<string, array{check: string, status: string, count: int, items: list<string>}> */
function checks(Session $ledger, string $book, string $startsOn, string $endsOn): array
{
    return array_column($ledger->yearEnd->checklist($book, $startsOn, $endsOn)['checks'], null, 'check');
}

it('posts each recurring occurrence once on month-end anchored dates and stops at its occurrence limit', function () {
    $rent = $this->ledger->catalog->account($this->book, '5100', 'Rent', AccountType::Expense);
    $amount = Money::of('1000', $this->kes);
    $id = $this->ledger->schedules->recurring($this->book, 'rent', 'Monthly rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-31', occurrences: 3));
    expect($this->ledger->schedules->recurring($this->book, 'rent', 'Monthly rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-31', occurrences: 3)))->toBe($id);

    expect(array_map(fn ($journal) => $journal->effectiveOn, $this->ledger->schedules->postRecurring($this->book, '2026-02-28')))->toBe(['2026-01-31', '2026-02-28'])
        ->and($this->ledger->schedules->postRecurring($this->book, '2026-02-28'))->toBe([])
        ->and(array_map(fn ($journal) => $journal->effectiveOn, $this->ledger->schedules->postRecurring($this->book, '2026-12-31')))->toBe(['2026-03-31'])
        ->and($this->ledger->journals->findBySource($this->book, new Source('recurring:'.$id, '2'))?->effectiveOn)->toBe('2026-03-31');
    expect((new Recurrence('year', 1, '2024-02-29'))->dateOf(1))->toBe('2025-02-28')
        ->and((new Recurrence('week', 2, '2026-01-01'))->dateOf(3))->toBe('2026-02-12')
        ->and($this->storage->run(Permission::Read, fn () => row($this->storage->connection->selectOne("SELECT ledger_recurring_date('2024-02-29', 'year', 1, 1)::text AS d"))->d))->toBe('2025-02-28');
});

it('auto-reverses accruals on the first day after their accounting period', function () {
    [$book, $rent, $accrued] = monthlyBook($this->ledger, $this->entity, $this->kes);
    $amount = Money::of('250', $this->kes);
    $accrual = $this->ledger->schedules->accrue($book, 'jan-rent-accrual', '2026-01-31', 'Accrued January rent', [
        new Posting($rent, Side::Debit, $amount), new Posting($accrued, Side::Credit, $amount),
    ]);
    expect($accrual->effectiveOn)->toBe('2026-01-31')
        ->and($this->ledger->journals->reversalOf($book, $accrual->id))->toBeNull();

    $posted = $this->ledger->schedules->postRecurring($book, '2026-02-01');
    expect($posted)->toHaveCount(1)
        ->and($posted[0]->reversesId)->toBe($accrual->id)
        ->and($posted[0]->effectiveOn)->toBe('2026-02-01')
        ->and($this->ledger->schedules->postRecurring($book, '2026-03-31'))->toBe([]);
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($book, '2026-02-28')));
    expect($balances[$accrued]->closing->decimal())->toBe('0.00');
});

it('rejects templates whose line currency differs from the account currency', function () {
    $usd = new Currency('USD', 2);
    $receivable = $this->ledger->catalog->account($this->book, '1110', 'USD receivables', AccountType::Asset, $usd, control: true);
    $tenant = $this->ledger->catalog->party($this->entity, 'T-1', 'Tenant');
    $this->ledger->rates->record($usd, $this->kes, RateType::Spot, '2026-01-01', '130', 'CBK');
    $this->ledger->rates->record($usd, $this->kes, RateType::Spot, '2026-02-01', '131', 'CBK');
    $this->ledger->schedules->recurring($this->book, 'usd-rent', 'Rent billed in USD', [
        new Posting($receivable, Side::Debit, Money::of('10', $usd), partyId: $tenant),
        new Posting($this->income, Side::Credit, Money::of('10', $usd)),
    ], Recurrence::monthly('2026-01-01'), dueAfterDays: 14);
})->throws(QueryException::class, 'live posting accounts');

it('bills recurring foreign-currency receivables with stored rates and due dates', function () {
    $usd = new Currency('USD', 2);
    $receivable = $this->ledger->catalog->account($this->book, '1110', 'USD receivables', AccountType::Asset, $usd, control: true);
    $tenant = $this->ledger->catalog->party($this->entity, 'T-1', 'Tenant');
    $this->ledger->rates->record($usd, $this->kes, RateType::Spot, '2026-01-01', '130', 'CBK');
    $income = Money::of('1300', $this->kes);
    $id = $this->ledger->schedules->recurring($this->book, 'usd-rent', 'Rent billed in USD', [
        new Posting($receivable, Side::Debit, Money::of('10', $usd), partyId: $tenant),
        new Posting($this->income, Side::Credit, $income),
    ], Recurrence::monthly('2026-01-01', occurrences: 2), dueAfterDays: 14);

    $journals = $this->ledger->schedules->postRecurring($this->book, '2026-01-31');
    $line = $this->ledger->journals->lines($this->book, $journals[0]->id)[0];
    expect($line->dueOn)->toBe('2026-01-15')->and($line->rate)->toBe('130.000000000000000000')->and($line->rateSource)->toStartWith('ledger-rate:');

    $this->ledger->rates->record($usd, $this->kes, RateType::Spot, '2026-02-01', '131', 'CBK');
    expect(fn () => $this->ledger->schedules->postRecurring($this->book, '2026-02-28', $id))->toThrow(QueryException::class, 'Journal must balance exactly');
    expect($this->ledger->journals->search($this->book, sourceType: 'recurring:'.$id))->toHaveCount(1);
});

it('cancels future occurrences only after later occurrences are reversed', function () {
    $rent = $this->ledger->catalog->account($this->book, '5100', 'Rent', AccountType::Expense);
    $amount = Money::of('100', $this->kes);
    $id = $this->ledger->schedules->recurring($this->book, 'rent', 'Monthly rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-15'));
    $this->ledger->schedules->postRecurring($this->book, '2026-03-15');
    expect(fn () => $this->ledger->schedules->cancelRecurring($this->book, $id, '2026-03-01', 'Lease ended'))->toThrow(QueryException::class, 'Reverse occurrences');

    $this->ledger->schedules->cancelRecurring($this->book, $id, '2026-03-16', 'Lease ended');
    expect($this->ledger->schedules->postRecurring($this->book, '2026-12-31'))->toBe([])
        ->and($this->ledger->journals->search($this->book, sourceType: 'recurring:'.$id))->toHaveCount(3);
});

it('rejects recurring journals forged outside their template', function () {
    $rent = $this->ledger->catalog->account($this->book, '5100', 'Rent', AccountType::Expense);
    $amount = Money::of('100', $this->kes);
    $id = $this->ledger->schedules->recurring($this->book, 'rent', 'Monthly rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-15'));
    $forged = Money::of('90', $this->kes);
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'forged', '2026-01-15', 'Monthly rent', [
        new Posting($rent, Side::Debit, $forged), new Posting($this->cash, Side::Credit, $forged),
    ], source: new Source('recurring:'.$id, '0'))))->toThrow(QueryException::class, 'recorded subledger event');

    $this->storage->run(Permission::Post, function () use ($id, $rent, $forged): void {
        $this->storage->connection->statement('SET CONSTRAINTS ALL DEFERRED');
        $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-run', '2026-01-15', 'Monthly rent', [
            new Posting($rent, Side::Debit, $forged), new Posting($this->cash, Side::Credit, $forged),
        ], source: new Source('recurring:'.$id, '0')));
        $this->storage->insert('ledger_recurring_runs', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'recurring_id' => $id, 'occurrence' => 0, 'kind' => 'post', 'journal_id' => $journal->id]);
    }, $this->book);
})->throws(QueryException::class, 'immutable template');

it('restates a closed year through retained earnings while presenting the corrected prior-year profit', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expenses', AccountType::Expense);
    $payable = $this->ledger->catalog->account($this->book, '2000', 'Accrued liabilities', AccountType::Liability);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $this->ledger->journals->post(new JournalRequest($this->book, 'activity', '2026-06-01', 'Activity', [
        new Posting($this->cash, Side::Debit, Money::of('70', $this->kes)),
        new Posting($expense, Side::Debit, Money::of('30', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $closure = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Annual close');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');

    $as = [new Posting($expense, Side::Debit, Money::of('20', $this->kes)), new Posting($payable, Side::Credit, Money::of('20', $this->kes))];
    $id = $this->ledger->yearEnd->restate($this->book, 'missed-invoice', $closure, '2027-02-01', $as, $retained, 'restatement-policy:1', 'Unrecorded 2026 supplier invoice');
    expect($this->ledger->yearEnd->restate($this->book, 'missed-invoice', $closure, '2027-02-01', $as, $retained, 'restatement-policy:1', 'Unrecorded 2026 supplier invoice'))->toBe($id);
    $journal = $this->ledger->journals->search($this->book, sourceType: 'restatement')[0];
    expect(array_map(fn ($line) => [$line->accountId, $line->side, $line->amount->decimal()], $this->ledger->journals->lines($this->book, $journal->id)))
        ->toBe([[$payable, Side::Credit, '20.00'], [$retained, Side::Debit, '20.00']])
        ->and($this->ledger->journals->lines($this->book, $journal->id)[1]->reportingMovement)->toEqual(new ReportingMovement('opening_adjustment', 'restatement-policy:1', 'missed-invoice'))
        ->and(fn () => $this->ledger->yearEnd->restate($this->book, 'missed-invoice', $closure, '2027-02-01', $as, $retained, 'restatement-policy:2', 'Unrecorded 2026 supplier invoice'))->toThrow(LedgerException::class, 'different content');

    $prior = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->reports->incomeStatement($prior, '2026-01-01')['profit']->decimal())->toBe('70.00')
        ->and($this->ledger->reports->incomeStatement($prior, '2026-01-01', restated: true)['profit']->decimal())->toBe('50.00')
        ->and($this->ledger->reports->incomeStatement($prior, '2026-01-01', restated: true)['expenses']->decimal())->toBe('50.00')
        ->and($this->ledger->reports->balanceSheet($prior)['liabilities']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->balanceSheet($prior, restated: true)['liabilities']->decimal())->toBe('20.00')
        ->and($this->ledger->reports->balanceSheet($prior, restated: true)['equity']->decimal())->toBe('50.00')
        ->and($this->ledger->reports->balanceSheet($prior, restated: true)['difference']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($before, '2026-01-01', restated: true)['profit']->decimal())->toBe('70.00')
        ->and($this->ledger->reports->incomeStatement($prior, '2026-01-01', ['campus' => 'west'], restated: true)['expenses']->decimal())->toBe('0.00');

    $current = $this->ledger->reports->snapshot($this->book, '2027-12-31');
    expect($this->ledger->reports->incomeStatement($current, '2027-01-01')['profit']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($current, '2027-01-01', restated: true)['profit']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->balanceSheet($current)['equity']->decimal())->toBe('50.00')
        ->and($this->ledger->reports->balanceSheet($current, restated: true)['equity']->decimal())->toBe('50.00');
});

it('keeps host-supplied equity attribution on restatement lines and defaults the rest to the given policy', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expenses', AccountType::Expense);
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Reserve', AccountType::Equity);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $closure = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Annual close');
    $reviewed = new ReportingMovement('opening_adjustment', 'host-policy:1', 'reviewed-reserve-correction');
    $this->ledger->yearEnd->restate($this->book, 'reserve-correction', $closure, '2027-02-01', [
        new Posting($expense, Side::Debit, Money::of('20', $this->kes)), new Posting($reserve, Side::Credit, Money::of('20', $this->kes), reportingMovement: $reviewed),
    ], $retained, 'restatement-policy:1', 'Reserve correction');
    $journal = $this->ledger->journals->search($this->book, sourceType: 'restatement')[0];
    expect(array_map(fn ($line) => $line->reportingMovement, $this->ledger->journals->lines($this->book, $journal->id)))
        ->toEqual([$reviewed, new ReportingMovement('opening_adjustment', 'restatement-policy:1', 'reserve-correction')]);
});

it('rejects restatements inside the closed year and forged restatement journals', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expenses', AccountType::Expense);
    $payable = $this->ledger->catalog->account($this->book, '2000', 'Accrued liabilities', AccountType::Liability);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $closure = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Annual close');
    $as = [new Posting($expense, Side::Debit, Money::of('20', $this->kes)), new Posting($payable, Side::Credit, Money::of('20', $this->kes))];
    expect(fn () => $this->ledger->yearEnd->restate($this->book, 'inside', $closure, '2026-12-31', $as, $retained, 'restatement-policy:1', 'Wrong date'))->toThrow(QueryException::class);

    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fake-restatement', '2027-02-01', 'Fake', [
        new Posting($retained, Side::Debit, Money::of('20', $this->kes)), new Posting($payable, Side::Credit, Money::of('20', $this->kes)),
    ], source: new Source('restatement', (string) Str::uuid7())));
})->throws(QueryException::class, 'recorded subledger event');

it('retrospectively applies one policy across opening equity and two comparative years without repeating current expense', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $before = $this->ledger->reports->snapshot($this->book, '2025-12-31');
    $definition = new StatementDefinition('Comparative profit', '1', ['expense' => ['label' => 'Policy expense', 'accounts' => ['5100'], 'include_closing' => false]]);
    $saved = $this->ledger->reports->saveStatement($this->book, 'original-comparative', $definition, [new StatementColumn('prior', 'Prior', $before, '2025-01-01')]);
    $originalEvidence = $this->ledger->reports->statementEvidence($this->book, $saved);
    $apply = fn (string $key): string => $this->ledger->yearEnd->changePolicy($this->book, $key, '2026-02-01', $fixture['retained'], 'policy:2',
        $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Reviewed accounting policy change');
    $id = $apply('policy-change');
    expect($apply('policy-change'))->toBe($id);
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $id);
    expect(array_map(fn (array $row): string => $row['equityDelta']->decimal(), $evidence['comparatives']))->toBe(['-30.00', '-40.00', '-50.00'])
        ->and(array_map(fn (array $row): string => $row['profitDelta']->decimal(), $evidence['comparatives']))->toBe(['0.00', '-10.00', '-10.00']);
    foreach (['2023-12-31' => '70.00', '2024-12-31' => '160.00', '2025-12-31' => '250.00'] as $date => $equity) {
        $snapshot = $this->ledger->reports->snapshot($this->book, $date);
        expect($this->ledger->reports->balanceSheet($snapshot, restated: true)['equity']->decimal())->toBe($equity)
            ->and($this->ledger->reports->balanceSheet($snapshot, restated: true)['difference']->decimal())->toBe('0.00');
    }
    $after = $this->ledger->reports->snapshot($this->book, '2025-12-31');
    $current = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->reports->incomeStatement($after, '2025-01-01', restated: true)['profit']->decimal())->toBe('90.00')
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2024-12-31'), '2024-01-01', restated: true)['profit']->decimal())->toBe('90.00')
        ->and($this->ledger->reports->incomeStatement($before, '2025-01-01', restated: true)['profit']->decimal())->toBe('100.00')
        ->and($this->ledger->reports->incomeStatement($current, '2026-01-01')['profit']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($current, '2026-01-01', restated: true)['profit']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->balanceSheet($current)['equity']->decimal())->toBe('250.00')
        ->and($this->ledger->reports->balanceSheet($current, restated: true)['equity']->decimal())->toBe('250.00');
    $replacement = $this->ledger->reports->saveStatement($this->book, 'replacement-comparative', $definition,
        [new StatementColumn('prior', 'Prior restated', $after, '2025-01-01', restated: true)]);
    expect($this->ledger->reports->statementEvidence($this->book, $saved)['result_json'])->toBe($originalEvidence['result_json'])
        ->and($this->ledger->reports->statementEvidence($this->book, $replacement)['result']->rows['expense']['values']['prior']->decimal())->toBe('10.00');
    $correction = $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'correct-policy', '2026-03-01', 'Withdraw policy change');
    expect($this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'correct-policy', '2026-03-01', 'Withdraw policy change'))->toBe($correction)
        ->and($this->ledger->yearEnd->policyChangeEvidence($this->book, $correction)['reversesId'])->toBe($id)
        ->and($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-12-31'))['equity']->decimal())->toBe('300.00')
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2025-12-31'), '2025-01-01', restated: true)['profit']->decimal())->toBe('100.00')
        ->and($this->ledger->reports->incomeStatement($after, '2025-01-01', restated: true)['profit']->decimal())->toBe('90.00')
        ->and($this->ledger->yearEnd->policyChangeEvidence($this->book, $id))->toEqual($evidence)
        ->and($this->ledger->reports->statementEvidence($this->book, $replacement)['result']->rows['expense']['values']['prior']->decimal())->toBe('10.00');
});

it('links opening and comparative tax effects to the same exact policy correction', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes, true);
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'with-tax', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Reviewed tax effects');
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $id);
    expect(array_map(fn (array $row): string => $row['equityDelta']->decimal(), $evidence['comparatives']))->toBe(['-21.00', '-28.00', '-35.00'])
        ->and(array_map(fn (array $row): string => $row['taxEquityDelta']->decimal(), $evidence['comparatives']))->toBe(['9.00', '12.00', '15.00'])
        ->and(array_map(fn (array $row): string => $row['profitDelta']->decimal(), $evidence['comparatives']))->toBe(['0.00', '-7.00', '-7.00'])
        ->and(array_map(fn (array $row): string => $row['taxProfitDelta']->decimal(), $evidence['comparatives']))->toBe(['0.00', '3.00', '3.00']);
    foreach (['2023-12-31' => ['79.00', null], '2024-12-31' => ['172.00', '93.00'], '2025-12-31' => ['265.00', '93.00']] as $date => [$equity, $profit]) {
        $snapshot = $this->ledger->reports->snapshot($this->book, $date);
        expect($this->ledger->reports->balanceSheet($snapshot, restated: true)['equity']->decimal())->toBe($equity)
            ->and($this->ledger->reports->balanceSheet($snapshot, restated: true)['difference']->decimal())->toBe('0.00');
        if ($profit !== null) {
            expect($this->ledger->reports->incomeStatement($snapshot, substr($date, 0, 4).'-01-01', restated: true)['profit']->decimal())->toBe($profit);
        }
    }
    $current = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $balances = byAccount($this->ledger->reports->trialBalance($current));
    expect($balances[$fixture['retained']]->closing->decimal())->toBe('-265.00')
        ->and($balances[$fixture['taxPayable']]->closing->decimal())->toBe('15.00')
        ->and($balances[$fixture['taxExpense']]->closing->decimal())->toBe('0.00');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'correct-tax-policy', '2026-03-01', 'Correction');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-12-31')));
    expect($balances[$fixture['retained']]->closing->decimal())->toBe('-300.00')->and($balances[$fixture['taxPayable']]->closing->decimal())->toBe('0.00');
});

it('retains a reviewed earliest-practicable boundary and rejects a contradictory full-retrospective decision', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $decision = [...$fixture['decision'], 'impracticable' => true, 'earliest_comparative_on' => '2023-01-01',
        'explanation' => 'Reviewed evidence cannot establish 2023 period-specific effects; cumulative effects are determinable at 2024 opening'];
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'practicable-from-2024', '2026-02-01', $fixture['retained'], 'policy:2', $decision, $fixture['opening'], $fixture['periods'], 'Earliest practicable application');
    $this->storage->run(Permission::Read, function () use ($id, $decision): void {
        $stored = $this->storage->table('ledger_restatements')->where('id', $id)->first() ?? throw new RuntimeException('Missing policy decision.');
        expect(Row::json($stored, 'inputs')['decision'])->toEqual($decision);
    }, $this->book);
    expect($this->ledger->yearEnd->policyChangeEvidence($this->book, $id)['comparatives'][0]['on'])->toBe('2024-01-01')
        ->and($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2023-12-31'), restated: true)['equity']->decimal())->toBe('100.00')
        ->and(byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2024-12-31'), '2024-01-01', restated: true))[$fixture['retained']]->opening->decimal())->toBe('-70.00')
        ->and(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'contradiction', '2026-02-02', $fixture['retained'], 'policy:2',
            [...$decision, 'impracticable' => false], $fixture['opening'], $fixture['periods'], 'Invalid decision'))->toThrow(QueryException::class, 'earliest-practicable');
});

it('retains zero-net policy changes and corrects their historical effects without inventing a journal', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $opening = [...$fixture['opening'], 'postings' => []];
    $periods = $fixture['periods'];
    $second = $periods[1] ?? throw new RuntimeException('Missing second comparative period.');
    $periods[1] = [...$second, 'postings' => array_map(fn (Posting $posting): Posting => $posting->opposite(), $second['postings'])];
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'zero-net', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $opening, $periods, 'Offsetting comparative effects');
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $id);
    expect($evidence['journalId'])->toBeNull()
        ->and(array_map(fn (array $comparison): string => $comparison['equityDelta']->decimal(), $evidence['comparatives']))->toBe(['0.00', '-10.00', '0.00'])
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2024-12-31'), '2024-01-01', restated: true)['profit']->decimal())->toBe('90.00');
    $correction = $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'zero-net-correction', '2026-02-02', 'Restore comparative allocations');
    expect($this->ledger->yearEnd->policyChangeEvidence($this->book, $correction)['journalId'])->toBeNull()
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2024-12-31'), '2024-01-01', restated: true)['profit']->decimal())->toBe('100.00');
});

it('composes policy changes from pinned restated sources and restores them in dependency order', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $apply = fn (string $key, string $date, string $version): string => $this->ledger->yearEnd->changePolicy($this->book, $key, $date, $fixture['retained'], $version,
        $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Reviewed additional policy effect');
    $first = $apply('first', '2026-02-01', 'policy:2');
    expect(fn () => $apply('repeat', '2026-02-02', 'policy:2'))->toThrow(QueryException::class, 'one active retrospective change');
    $second = $apply('second', '2026-02-02', 'policy:3');
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $second);
    $asset = collect($evidence['comparatives'][2]['accounts'])->firstWhere('accountId', $fixture['asset']) ?? throw new RuntimeException('Missing comparative asset.');
    expect($asset['original']->decimal())->toBe('250.00')->and($asset['replacement']->decimal())->toBe('200.00')
        ->and(fn () => $this->ledger->yearEnd->reversePolicyChange($this->book, $first, 'too-early', '2026-03-01', 'Dependency violation'))->toThrow(QueryException::class, 'dependency order');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $second, 'restore-second', '2026-03-01', 'Restore later policy');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $first, 'restore-first', '2026-03-02', 'Restore earlier policy');
    expect($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2025-12-31'), restated: true)['equity']->decimal())->toBe('300.00');
});

it('rejects incomplete or inconsistent policy effects atomically', function (string $case, string $message) {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $decision = $fixture['decision'];
    $opening = $fixture['opening'];
    $periods = $fixture['periods'];
    match ($case) {
        'decision' => $decision['reference'] = '',
        'contradiction' => $decision['impracticable'] = true,
        'current-opening' => $decision['current_starts_on'] = '2025-01-01',
        'opening-income' => $opening['postings'] = $periods[0]['postings'],
        'tax-reference' => $periods[0]['tax_reference'] = '',
        'tax-balance' => $periods[0]['tax'] = [new Posting($fixture['taxExpense'], Side::Debit, Money::of('1', $this->kes))],
        'effect-balance' => $periods[0]['postings'] = [new Posting($fixture['expense'], Side::Debit, Money::of('1', $this->kes))],
        'period-gap' => $periods = [$periods[1]],
        'period-order' => $periods = array_reverse($periods),
        'dimension' => $opening['postings'] = [new Posting($fixture['retained'], Side::Debit, Money::of('30', $this->kes), dimensions: ['campus' => 'unknown']),
            new Posting($fixture['asset'], Side::Credit, Money::of('30', $this->kes), dimensions: ['campus' => 'unknown'])],
        default => throw new RuntimeException('Unknown test case'),
    };
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'invalid', '2026-02-01', $fixture['retained'], 'policy:2', $decision, $opening, $periods, 'Invalid inputs'))
        ->toThrow(QueryException::class, $message)
        ->and($this->ledger->events->cutoff($this->book))->toBe($before);
})->with([
    ['decision', 'impracticability decision'], ['contradiction', 'earliest-practicable'], ['current-opening', 'current-year opening'],
    ['opening-income', 'balance-sheet only'], ['tax-reference', 'explicit tax references'], ['tax-balance', 'balance independently'],
    ['effect-balance', 'balance independently'], ['period-gap', 'contiguous active closed'], ['period-order', 'contiguous active closed'], ['dimension', 'valid dimensions'],
]);

it('independently rejects forged replacement comparative evidence without changing financial state', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'original', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Original policy');
    $before = $this->ledger->events->cutoff($this->book);
    $cutoff = $this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence;
    expect(fn () => $this->storage->run(Permission::Reopen, function () use ($id, $cutoff): void {
        $original = $this->storage->find('ledger_restatements', $id, $this->book);
        $this->storage->insert('ledger_restatements', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'idempotency_key' => 'forged', 'fingerprint' => str_repeat('a', 64),
            'retained_account_id' => Row::text($original, 'retained_account_id'), 'attribution' => '[]', 'policy_version' => 'policy:3', 'effective_on' => '2026-02-02',
            'through_sequence' => $cutoff, 'inputs' => Row::text($original, 'inputs'), 'result' => '{}', 'reason' => 'Forged replacement']);
    }, $this->book))->toThrow(QueryException::class, 'independently measured')
        ->and($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('composes policy comparatives with an earlier single-period restatement', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $this->ledger->yearEnd->restate($this->book, 'prior-correction', $fixture['periods'][0]['closure_id'], '2026-01-01', [
        new Posting($fixture['expense'], Side::Debit, Money::of('5', $this->kes)), new Posting($fixture['asset'], Side::Credit, Money::of('5', $this->kes)),
    ], $fixture['retained'], 'error-correction:1', 'Correct prior error');
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'subsequent-policy', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Policy after correction');
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $id);
    $asset = collect($evidence['comparatives'][2]['accounts'])->firstWhere('accountId', $fixture['asset']) ?? throw new RuntimeException('Missing comparative asset.');
    expect($asset['original']->decimal())->toBe('295.00')->and($asset['replacement']->decimal())->toBe('245.00')
        ->and($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2025-12-31'), restated: true)['equity']->decimal())->toBe('245.00');
});

it('keeps policy effects in their original dimension buckets', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'north');
    $withDimension = fn (Posting $posting): Posting => new Posting($posting->accountId, $posting->side, $posting->amount, dimensions: ['campus' => 'north']);
    $opening = [...$fixture['opening'], 'postings' => array_map($withDimension, $fixture['opening']['postings'])];
    $periods = [];
    foreach ($fixture['periods'] as $period) {
        $periods[] = [...$period, 'postings' => array_map($withDimension, $period['postings'])];
    }
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'dimension-policy', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $opening, $periods, 'Reviewed north-campus policy');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2025-12-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2025-01-01', ['campus' => 'north'], restated: true)['profit']->decimal())->toBe('-10.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2025-01-01', ['campus' => 'south'], restated: true)['profit']->decimal())->toBe('0.00');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'dimension-correction', '2026-03-01', 'Restore dimension effects');
    expect($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2025-12-31'), '2025-01-01', ['campus' => 'north'], restated: true)['profit']->decimal())->toBe('0.00');
});

it('requires an open recognition period even for a policy with no journal', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $opening = [...$fixture['opening'], 'postings' => []];
    $periods = [];
    foreach ($fixture['periods'] as $period) {
        $periods[] = [...$period, 'postings' => []];
    }
    $this->ledger->periods->close($this->book, $this->period, 'Close current period');
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'closed-period', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $opening, $periods, 'No-journal policy'))
        ->toThrow(QueryException::class, 'open recognition period');
});

it('rejects historical policy effects on protected deferred tax balances even when their cumulative journal would be zero', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $asset = $this->ledger->configurations->account($this->book, $configuration, 'tax.deferred.asset');
    $opening = [...$fixture['opening'], 'postings' => []];
    $periods = $fixture['periods'];
    $first = $periods[0];
    $second = $periods[1] ?? throw new RuntimeException('Missing second period.');
    $effect = [new Posting($fixture['taxExpense'], Side::Debit, Money::of('10', $this->kes)), new Posting($asset, Side::Credit, Money::of('10', $this->kes))];
    $periods[0] = [...$first, 'postings' => $effect];
    $periods[1] = [...$second, 'postings' => array_map(fn (Posting $posting): Posting => $posting->opposite(), $effect)];
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'protected', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $opening, $periods, 'Unsupported protected history'))
        ->toThrow(QueryException::class, 'protected valuation, allowance or deferred-tax register');
});

it('rejects a policy change that skips a closed year between its last comparative and recognition', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'activity-2026', '2026-06-30', 'Activity', [
        new Posting($fixture['asset'], Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $closure2026 = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $fixture['retained'], 'Close');
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $periods2026 = [...$fixture['periods'], ['closure_id' => $closure2026, 'reference' => 'As-if 2026', 'tax_reference' => 'Reviewed: no tax effect', 'postings' => $fixture['periods'][0]['postings'], 'tax' => []]];
    $change = fn (string $currentStartsOn, bool $with2026): string => $this->ledger->yearEnd->changePolicy($this->book, 'after-'.$currentStartsOn.($with2026 ? '-2026' : ''), '2027-02-01',
        $fixture['retained'], 'policy:2', [...$fixture['decision'], 'current_starts_on' => $currentStartsOn], $fixture['opening'], $with2026 ? $periods2026 : $fixture['periods'], 'Reviewed policy change');
    expect(fn () => $change('2026-01-01', false))->toThrow(QueryException::class, 'current-year opening')
        ->and(fn () => $change('2027-01-01', false))->toThrow(QueryException::class, 'current-year opening');
    $id = $change('2027-01-01', true);
    expect(array_map(fn (array $row): string => $row['equityDelta']->decimal(), $this->ledger->yearEnd->policyChangeEvidence($this->book, $id)['comparatives']))
        ->toBe(['-30.00', '-40.00', '-50.00', '-60.00'])
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01', restated: true)['profit']->decimal())->toBe('90.00');
});

it('ties the comparatives to their closures and the earliest comparative to a closed fiscal year', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $capital = $this->ledger->catalog->account($this->book, '3001', 'Share capital', AccountType::Equity);
    $opening = [...$fixture['opening'], 'postings' => [new Posting($capital, Side::Debit, Money::of('30', $this->kes)), new Posting($fixture['asset'], Side::Credit, Money::of('30', $this->kes))]];
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'capital', '2026-02-01', $capital, 'policy:2', $fixture['decision'], $opening, $fixture['periods'], 'Wrong equity component'))
        ->toThrow(QueryException::class, 'close into its retained earnings')
        ->and(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'invented', '2026-02-01', $fixture['retained'], 'policy:2',
            [...$fixture['decision'], 'impracticable' => true, 'earliest_comparative_on' => '1900-06-15'], $fixture['opening'], $fixture['periods'], 'Invented comparative'))
        ->toThrow(QueryException::class, 'earliest originally presented comparative');
});

it('refuses to close a fiscal year that an active policy change recognized later has skipped', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'skipping', '2027-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Recognized in 2027');
    expect(fn () => $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $fixture['retained'], 'Close'))->toThrow(QueryException::class, 'skipped comparative');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'withdraw', '2027-02-02', 'Withdraw before closing');
    expect($this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $fixture['retained'], 'Close'))->toBeString();
});

it('refuses overlapping fiscal closures and recognition inside an active closed year', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    expect(fn () => $this->ledger->yearEnd->close($this->book, 'overlap', '2025-01-01', '2025-12-31', $fixture['retained'], 'Close again'))->toThrow(QueryException::class, 'overlap an active closure');
    $this->ledger->periods->reopen($this->book, collect($this->ledger->periods->list($this->book))->firstWhere('startsOn', '2025-01-01')->id ?? throw new RuntimeException('Missing 2025.'), 'Reopen');
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'inside', '2025-06-01', $fixture['retained'], 'policy:2', [...$fixture['decision'], 'current_starts_on' => '2025-01-01'],
        $fixture['opening'], [$fixture['periods'][0]], 'Inside a closed year'))->toThrow(QueryException::class, 'outside active closed fiscal years');
});

it('recloses a journal-free fiscal year after late activity and lets a prospective change leave comparatives reopenable', function () {
    $retained = $this->ledger->catalog->account($this->book, '3100', 'Retained earnings', AccountType::Equity);
    $period = $this->ledger->periods->open($this->book, '2025', '2025-01-01', '2025-12-31');
    $this->ledger->yearEnd->close($this->book, 'empty-2025', '2025-01-01', '2025-12-31', $retained, 'Nothing to close');
    $this->ledger->periods->reopen($this->book, $period, 'Late sale');
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-sale', '2025-06-30', 'Late sale', [
        new Posting($this->cash, Side::Debit, Money::of('50', $this->kes)), new Posting($this->income, Side::Credit, Money::of('50', $this->kes)),
    ]));
    expect($this->ledger->yearEnd->close($this->book, 'reclose-2025', '2025-01-01', '2025-12-31', $retained, 'Close late sale'))->toBeString();
    $this->ledger->yearEnd->changePolicy($this->book, 'prospective', '2026-03-02', $retained, 'policy:prospective',
        ['impracticable' => true, 'reference' => 'IAS8-review:prospective', 'explanation' => 'Prospective only', 'earliest_comparative_on' => '2025-01-01', 'application_from' => '2026-03-01', 'current_starts_on' => '2026-01-01'],
        ['reference' => 'None', 'tax_reference' => 'None', 'postings' => [], 'tax' => []], [], 'Prospective application');
    $this->ledger->periods->reopen($this->book, $period, 'Prospective change restates nothing');
    expect(collect($this->ledger->periods->list($this->book))->firstWhere('id', $period)?->closed)->toBeFalse();
});

it('composes impracticable openings on the same date and dates prospective evidence on application', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $decision = [...$fixture['decision'], 'impracticable' => true, 'earliest_comparative_on' => '2023-01-01', 'explanation' => 'Effects before 2024 are impracticable'];
    $this->ledger->yearEnd->changePolicy($this->book, 'first', '2026-02-01', $fixture['retained'], 'policy:2', $decision, $fixture['opening'], $fixture['periods'], 'First');
    $second = $this->ledger->yearEnd->changePolicy($this->book, 'second', '2026-02-02', $fixture['retained'], 'policy:3', $decision, $fixture['opening'], $fixture['periods'], 'Second');
    $asset = collect($this->ledger->yearEnd->policyChangeEvidence($this->book, $second)['comparatives'][0]['accounts'])->firstWhere('accountId', $fixture['asset'])
        ?? throw new RuntimeException('Missing asset evidence.');
    expect($asset['original']->decimal())->toBe('70.00')->and($asset['replacement']->decimal())->toBe('40.00');
    $prospective = $this->ledger->yearEnd->changePolicy($this->book, 'prospective', '2026-03-02', $fixture['retained'], 'policy:4',
        [...$decision, 'earliest_comparative_on' => '2024-01-01', 'application_from' => '2026-03-01', 'explanation' => 'Prospective'], [...$fixture['opening'], 'postings' => []], [], 'Prospective');
    expect($this->ledger->yearEnd->policyChangeEvidence($this->book, $prospective)['comparatives'][0]['on'])->toBe('2026-03-01');
});

it('ties the current opening of a book without closures to its first period and requires presented comparatives after a closure', function () {
    $retained = $this->ledger->catalog->account($this->book, '3190', 'First-year retained earnings', AccountType::Equity);
    $asset = $this->ledger->catalog->account($this->book, '1590', 'First-year policy asset', AccountType::Asset);
    $decision = ['impracticable' => false, 'reference' => 'IAS8-review:first-year', 'explanation' => 'First reporting year', 'earliest_comparative_on' => '2026-07-01',
        'application_from' => '2026-07-01', 'current_starts_on' => '2026-07-01'];
    $opening = ['reference' => 'Opening', 'tax_reference' => 'No tax', 'postings' => [new Posting($retained, Side::Debit, Money::of('10', $this->kes)), new Posting($asset, Side::Credit, Money::of('10', $this->kes))], 'tax' => []];
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'mid-year', '2026-08-01', $retained, 'policy:first', $decision, $opening, [], 'Arbitrary opening'))
        ->toThrow(QueryException::class, 'first accounting period of the book');
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $unpresented = [...$fixture['decision'], 'earliest_comparative_on' => '2026-01-01', 'application_from' => '2026-01-01'];
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'unpresented', '2026-02-01', $fixture['retained'], 'policy:2', $unpresented, $fixture['opening'], [], 'No comparatives'))
        ->toThrow(QueryException::class, 'earliest comparative originally presented');
});

it('rejects policy effects on cash, inventory and a measured credit-loss allowance', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $receivable = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $allowance = $this->ledger->catalog->account($this->book, '1190', 'Allowance for credit losses', AccountType::Asset, normalSide: Side::Credit);
    $loss = $this->ledger->catalog->account($this->book, '5810', 'Expected credit losses', AccountType::Expense);
    $party = $this->ledger->catalog->party($this->entity, 'CUSTOMER-1', 'Customer');
    $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-10', 'Invoice', [
        new Posting($receivable, Side::Debit, Money::of('1000', $this->kes), partyId: $party, dueOn: '2026-01-31'), new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)),
    ]));
    $this->ledger->settlements->provide($this->book, 'ecl', $receivable, $allowance, $loss, '2026-01-31', ['current' => '0.05', '1-30' => '0.05', '31-60' => '0.05', '61-90' => '0.05', '91+' => '0.05'], 'Matrix');
    $stock = $this->ledger->catalog->account($this->book, '1400', 'Inventory', AccountType::Asset);
    $this->ledger->inventory->pool($this->book, 'STORE', 'SKU', $stock, ValuationMethod::Fifo);
    $periods = $fixture['periods'];
    foreach ([$allowance, $this->cash, $stock] as $account) {
        $periods[0] = [...$fixture['periods'][0], 'postings' => [new Posting($fixture['expense'], Side::Debit, Money::of('10', $this->kes)), new Posting($account, Side::Credit, Money::of('10', $this->kes))]];
        expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'protected-'.$account, '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $periods, 'Bypass'))
            ->toThrow(QueryException::class, 'cash or a protected valuation, allowance');
    }
});

it('adjusts only the current opening when every comparative is impracticable and records prospective application without effects', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $decision = [...$fixture['decision'], 'impracticable' => true, 'application_from' => '2026-01-01',
        'explanation' => 'Period-specific effects cannot be determined for any comparative; the cumulative effect is determinable at the current opening'];
    $opening = [...$fixture['opening'], 'postings' => [new Posting($fixture['retained'], Side::Debit, Money::of('50', $this->kes)), new Posting($fixture['asset'], Side::Credit, Money::of('50', $this->kes))]];
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'current-only', '2026-02-01', $fixture['retained'], 'policy:2', $decision, $opening, [], 'Current-period opening adjustment');
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $id);
    $prior = $this->ledger->reports->snapshot($this->book, '2025-12-31');
    $current = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($evidence['comparatives'])->toHaveCount(1)->and($evidence['comparatives'][0]['equityDelta']->decimal())->toBe('-50.00')
        ->and($this->ledger->reports->balanceSheet($prior, restated: true)['equity']->decimal())->toBe('300.00')
        ->and($this->ledger->reports->incomeStatement($prior, '2025-01-01', restated: true)['profit']->decimal())->toBe('100.00')
        ->and($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-01-15'), restated: true)['equity']->decimal())->toBe('250.00')
        ->and($this->ledger->reports->balanceSheet($current, restated: true)['equity']->decimal())->toBe('250.00')
        ->and($this->ledger->reports->balanceSheet($current)['equity']->decimal())->toBe('250.00')
        ->and(byAccount($this->ledger->reports->trialBalance($current, '2026-01-01', restated: true))[$fixture['retained']]->opening->decimal())->toBe('-250.00')
        ->and(byAccount($this->ledger->reports->trialBalance($current, '2026-01-01'))[$fixture['retained']]->opening->decimal())->toBe('-300.00');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'current-only-correction', '2026-03-01', 'Withdraw');
    expect($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-12-31'))['equity']->decimal())->toBe('300.00');

    $prospective = [...$decision, 'application_from' => '2026-03-01', 'explanation' => 'Even the current-opening cumulative effect is impracticable; apply prospectively'];
    expect(fn () => $this->ledger->yearEnd->changePolicy($this->book, 'prospective-effects', '2026-03-02', $fixture['retained'], 'policy:3', $prospective, $opening, [], 'Prospective'))
        ->toThrow(QueryException::class, 'records no retrospective effects');
    $evidenceOnly = $this->ledger->yearEnd->changePolicy($this->book, 'prospective', '2026-03-02', $fixture['retained'], 'policy:3', $prospective,
        [...$opening, 'postings' => []], [], 'Prospective application');
    expect($this->ledger->yearEnd->policyChangeEvidence($this->book, $evidenceOnly)['journalId'])->toBeNull()
        ->and($this->ledger->reports->balanceSheet($this->ledger->reports->snapshot($this->book, '2026-12-31'), restated: true)['equity']->decimal())->toBe('300.00');
});

it('refuses restated interim comparatives and excludes a corrected policy from restated views between recognition and correction', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'policy', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Reviewed policy');
    $evidence = $this->ledger->yearEnd->policyChangeEvidence($this->book, $id);
    expect(array_map(fn (array $comparison): array => array_map(fn (array $account): string => $account['code'], $comparison['accounts']), $evidence['comparatives']))
        ->toBe(array_fill(0, 3, ['1500', '3100', '5100']))
        ->and(fn () => $this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2025-06-30'), '2025-01-01', restated: true))
        ->toThrow(LedgerException::class, 'fiscal-year boundaries')
        ->and(fn () => $this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2025-12-31'), '2025-07-01', restated: true))
        ->toThrow(LedgerException::class, 'fiscal-year boundaries')
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2025-06-30'), '2025-01-01')['profit']->decimal())->toBe('100.00');
    $this->ledger->yearEnd->reversePolicyChange($this->book, $id, 'withdraw', '2026-03-01', 'Withdraw policy');
    $interim = $this->ledger->reports->snapshot($this->book, '2026-02-15');
    expect($this->ledger->reports->balanceSheet($interim)['equity']->decimal())->toBe('250.00')
        ->and($this->ledger->reports->balanceSheet($interim, restated: true)['equity']->decimal())->toBe('300.00')
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2025-06-30'), '2025-01-01', restated: true)['profit']->decimal())->toBe('100.00');
});

it('refuses to claim a retrospective policy tax effect as a booked current-tax payment', function () {
    $fixture = retrospectivePolicyFixture($this->ledger, $this->book, $this->kes);
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.payable');
    $opening = [...$fixture['opening'], 'tax' => [new Posting($payable, Side::Debit, Money::of('9', $this->kes)), new Posting($fixture['retained'], Side::Credit, Money::of('9', $this->kes))]];
    $id = $this->ledger->yearEnd->changePolicy($this->book, 'taxed', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $opening, $fixture['periods'], 'Reviewed tax effect');
    $journal = text($this->ledger->yearEnd->policyChangeEvidence($this->book, $id)['journalId']);
    $line = collect($this->ledger->journals->lines($this->book, $journal))->firstWhere('accountId', $payable)->lineNo ?? throw new RuntimeException('Missing tax line.');
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'claim', $configuration, '2026-01-01', 'trade', [], [],
        [['journal_id' => $journal, 'line_no' => $line, 'amount_minor' => '900', 'kind' => 'instalment', 'reference' => 'Not a payment']], 'Reviewed claim')))
        ->toThrow(QueryException::class, 'booked tax payment');
});

it('commits a whole policy change and correction while rejecting journal-only correction at actual commit', function () {
    $scope = new Scope((string) Str::uuid7(), 'policy-reviewer');
    $ledger = (new Ledger(Database::connection(), new PermitAll))->for($scope);
    $entity = $ledger->catalog->entity('Committed policy change');
    $book = $ledger->catalog->book($entity, 'Statutory', $this->kes);
    $ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $fixture = retrospectivePolicyFixture($ledger, $book, $this->kes, true);
    $id = $ledger->yearEnd->changePolicy($book, 'committed', '2026-02-01', $fixture['retained'], 'policy:2', $fixture['decision'], $fixture['opening'], $fixture['periods'], 'Commit reviewed policy');
    $evidence = $ledger->yearEnd->policyChangeEvidence($book, $id);
    $before = $ledger->events->cutoff($book);
    expect(fn () => $ledger->journals->post(new JournalRequest($book, 'orphan-policy', '2026-02-01', 'Orphan policy journal', [
        new Posting($fixture['retained'], Side::Debit, Money::of('1', $this->kes)), new Posting($fixture['asset'], Side::Credit, Money::of('1', $this->kes)),
    ], source: new Source('policy_change', (string) Str::uuid7()))))->toThrow(PDOException::class, 'complete linked evidence')
        ->and($ledger->events->cutoff($book))->toBe($before);
    expect(fn () => $ledger->journals->reverse($book, text($evidence['journalId']), 'detached', '2026-03-01', 'Detached correction'))
        ->toThrow(PDOException::class, 'complete linked evidence')
        ->and($ledger->events->cutoff($book))->toBe($before);
    $storage = new ScopedDatabase(Database::connection(), $scope, new PermitAll);
    $closure2025 = ($fixture['periods'][1] ?? throw new RuntimeException('Missing 2025 comparative.'))['closure_id'];
    $closingJournal = text($storage->run(Permission::Read, fn () => $storage->table('ledger_year_closures')->where('id', $closure2025)->value('journal_id'), $book));
    expect(fn () => $ledger->periods->reopen($book, collect($ledger->periods->list($book))->firstWhere('startsOn', '2025-01-01')->id ?? throw new RuntimeException('Missing 2025 period.'), 'Reopen comparative'))
        ->toThrow(QueryException::class, 'before reopening it')
        ->and(fn () => $ledger->journals->reverse($book, $closingJournal, 'reopen-2025', '2026-03-01', 'Reverse restated comparative closing'))->toThrow(PDOException::class, 'before reopening it');
    $ledger->yearEnd->reversePolicyChange($book, $id, 'complete', '2026-03-01', 'Restore whole policy and tax');
    expect($ledger->reports->balanceSheet($ledger->reports->snapshot($book, '2026-12-31'))['equity']->decimal())->toBe('300.00')
        ->and($ledger->reports->incomeStatement($ledger->reports->snapshot($book, '2025-12-31'), '2025-01-01', restated: true)['profit']->decimal())->toBe('100.00');
});

it('reports a closing checklist and blocks a required close until failures are resolved', function () {
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $rent = $this->ledger->catalog->account($this->book, '5100', 'Rent', AccountType::Expense);
    $deferred = $this->ledger->catalog->account($this->book, '2200', 'Deferred income', AccountType::Liability);
    $amount = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'sale', '2026-03-01', 'Sale', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $this->ledger->schedules->recurring($this->book, 'rent', 'Rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)], Recurrence::monthly('2026-12-31', occurrences: 1));
    $schedule = $this->ledger->schedules->create($this->book, 'recognition', 'Recognize', $deferred, $this->income, Schedule::equal(Money::of('20', $this->kes), ['2026-11-30', '2027-01-31']), 'v1');
    $draft = $this->ledger->drafts->prepare(new JournalRequest($this->book, 'draft', '2026-12-15', 'Draft', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)]));

    $checklist = $this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31');
    $checks = array_column($checklist['checks'], null, 'check');
    $status = array_column($checklist['checks'], 'status', 'check');
    expect($checklist['ready'])->toBeFalse()
        ->and($status)->toMatchArray(['periods_covered' => 'pass', 'prior_earnings_closed' => 'pass', 'drafts_decided' => 'fail', 'recurring_posted' => 'fail',
            'schedules_posted' => 'fail', 'depreciation_posted' => 'pass', 'control_accounts_reconciled' => 'pass', 'balance_sheet_balances' => 'pass',
            'bank_statements_cover_period_end' => 'warning', 'cash_flow_classified' => 'pass'])
        ->and($checks['drafts_decided']['items'])->toBe([$draft->id])
        ->and($checks['schedules_posted']['items'])->toBe([$schedule.':1'])
        ->and(fn () => $this->ledger->yearEnd->close($this->book, 'close', '2026-01-01', '2026-12-31', $retained, 'Close', requireChecklist: true))
        ->toThrow(LedgerException::class, 'drafts_decided, recurring_posted, schedules_posted')
        ->and(checks($this->ledger, $this->book, '2026-01-01', '2027-03-31')['periods_covered']['items'])->toBe(['2027-01-01']);

    $this->ledger->drafts->withdraw($this->book, $draft->id, 'Not needed');
    $this->ledger->schedules->postRecurring($this->book, '2026-12-31');
    $this->ledger->schedules->postDue($this->book, $schedule, '2026-12-31');
    expect($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['ready'])->toBeTrue();
    $this->ledger->yearEnd->close($this->book, 'close', '2026-01-01', '2026-12-31', $retained, 'Close', requireChecklist: true);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    expect(checks($this->ledger, $this->book, '2027-01-01', '2027-12-31')['prior_earnings_closed']['status'])->toBe('pass');
});

it('cancels a recognition schedule once, idempotently, and refuses further postings', function () {
    $deferred = $this->ledger->catalog->account($this->book, '2200', 'Deferred income', AccountType::Liability);
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $deferred, 'campus');
    $this->ledger->catalog->requireDimension($this->book, $this->income, 'campus');
    $plan = Schedule::equal(Money::of('90', $this->kes), ['2026-01-31', '2026-02-28', '2026-03-31']);
    $id = $this->ledger->schedules->create($this->book, 'recognition', 'Recognize', $deferred, $this->income, $plan, 'v1', ['campus' => 'west']);
    expect($this->ledger->schedules->create($this->book, 'recognition', 'Recognize', $deferred, $this->income, $plan, 'v1', ['campus' => 'west']))->toBe($id)
        ->and(fn () => $this->ledger->schedules->create($this->book, 'recognition', 'Recognize', $deferred, $this->income, $plan, 'v2', ['campus' => 'west']))
        ->toThrow(LedgerException::class, 'different content');
    $posted = $this->ledger->schedules->postDue($this->book, $id, '2026-01-31');
    expect($this->ledger->journals->lines($this->book, $posted[0]->id)[0]->dimensions)->toBe(['campus' => 'west']);

    $this->ledger->schedules->cancel($this->book, $id, 'Student withdrew');
    $this->ledger->schedules->cancel($this->book, $id, 'Student withdrew');
    expect(fn () => $this->ledger->schedules->cancel($this->book, $id, 'Another reason'))->toThrow(LedgerException::class, 'another reason')
        ->and(fn () => $this->ledger->schedules->postDue($this->book, $id, '2026-03-31'))->toThrow(LedgerException::class, 'Schedule was cancelled.')
        ->and($this->ledger->schedules->dueSchedules($this->book, '2026-03-31'))->toBe([]);
});

it('lists due auto-reversals, replays an identical recurring cancellation and rejects a different one', function () {
    [$book, $rent, $accrued] = monthlyBook($this->ledger, $this->entity, $this->kes);
    $amount = Money::of('100', $this->kes);
    $id = $this->ledger->schedules->recurring($book, 'accrual', 'Accrued rent', [new Posting($rent, Side::Debit, $amount), new Posting($accrued, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-31'), autoReverse: true);
    $this->ledger->schedules->postRecurring($book, '2026-01-31');
    expect($this->ledger->schedules->dueRecurring($book, '2026-01-31'))->toBe([])
        ->and($this->ledger->schedules->dueRecurring($book, '2026-02-01'))->toBe([['recurring_id' => $id, 'occurrence' => 0, 'kind' => 'reverse', 'due_on' => '2026-02-01']]);
    $this->ledger->schedules->postRecurring($book, '2026-02-01');
    expect($this->ledger->schedules->dueRecurring($book, '2026-02-01'))->toBe([]);

    $this->ledger->schedules->cancelRecurring($book, $id, '2026-02-15', 'Lease ended');
    $this->ledger->schedules->cancelRecurring($book, $id, '2026-02-15', 'Lease ended');
    expect(fn () => $this->ledger->schedules->cancelRecurring($book, $id, '2026-02-20', 'Lease ended'))->toThrow(LedgerException::class, 'cancelled differently')
        ->and($this->ledger->schedules->dueRecurring($book, '2026-04-30'))->toBe([]);
});

it('closes a year whose accounts or dimension buckets net to zero and refuses one with unclosed earlier earnings', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expenses', AccountType::Expense);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $other = $this->ledger->catalog->account($this->book, '4100', 'Other income', AccountType::Income);
    $amount = Money::of('40', $this->kes);
    $sale = $this->ledger->journals->post(new JournalRequest($this->book, 'sale', '2026-02-01', 'Sale', [new Posting($this->cash, Side::Debit, $amount), new Posting($other, Side::Credit, $amount)]));
    $this->ledger->journals->reverse($this->book, $sale->id, 'cancel-sale', '2026-02-02', 'Cancelled');
    $this->ledger->journals->post(new JournalRequest($this->book, 'netting', '2026-03-01', 'Recharge', [new Posting($expense, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $closure = $this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Close');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->journals->search($this->book, sourceType: 'year_end'))->toHaveCount(1)
        ->and(byAccount($this->ledger->reports->trialBalance($snapshot))[$retained]->closing->decimal())->toBe('0.00')
        ->and($closure)->toBeString();

    $book = $this->ledger->catalog->book($this->entity, 'Unclosed', $this->kes);
    $this->ledger->periods->open($book, '2025', '2025-01-01', '2025-12-31');
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
    $income = $this->ledger->catalog->account($book, '4000', 'Income', AccountType::Income);
    $bookRetained = $this->ledger->catalog->account($book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($book, 'old-sale', '2025-06-01', 'Sale', [new Posting($cash, Side::Debit, $amount), new Posting($income, Side::Credit, $amount)]));
    $this->ledger->yearEnd->close($book, 'close-2026', '2026-01-01', '2026-12-31', $bookRetained, 'Close');
})->throws(LedgerException::class, 'Unclosed earnings exist before this fiscal year.');

it('flags period gaps, unclosed prior earnings, due depreciation and unrevalued foreign items in the checklist', function () {
    $book = $this->ledger->catalog->book($this->entity, 'Checklist', $this->kes);
    $this->ledger->periods->open($book, '2025', '2025-01-01', '2025-12-31');
    $this->ledger->periods->open($book, '2026-H1', '2026-01-01', '2026-03-31');
    $this->ledger->periods->open($book, '2026-H2', '2026-05-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
    $income = $this->ledger->catalog->account($book, '4000', 'Income', AccountType::Income);
    $usd = new Currency('USD', 2);
    $usdAr = $this->ledger->catalog->account($book, '1110', 'USD receivables', AccountType::Asset, $usd, control: true);
    $party = $this->ledger->catalog->party($this->entity, 'USD-1', 'Foreign customer');
    $amount = Money::of('50', $this->kes);
    $this->ledger->journals->post(new JournalRequest($book, 'old-sale', '2025-06-01', 'Sale', [new Posting($cash, Side::Debit, $amount), new Posting($income, Side::Credit, $amount)]));
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-02-01', 'treasury', Rounding::HalfEven);
    $this->ledger->journals->post(new JournalRequest($book, 'usd-sale', '2026-02-01', 'USD sale', [
        new Posting($usdAr, Side::Debit, Money::of('10', $usd), $rate, $party), new Posting($income, Side::Credit, $rate->convert(Money::of('10', $usd))),
    ]));
    $cost = $this->ledger->catalog->account($book, '1500', 'Equipment', AccountType::Asset, cashFlow: 'investing');
    $depreciation = $this->ledger->catalog->account($book, '1590', 'Accumulated depreciation', AccountType::Asset, normalSide: Side::Credit);
    $impairment = $this->ledger->catalog->account($book, '1591', 'Accumulated impairment', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($book, '5100', 'Depreciation', AccountType::Expense);
    $funding = $this->ledger->catalog->account($book, '2300', 'Capitalization clearing', AccountType::Liability);
    $this->ledger->assets->capitalize(new AssetRequest($book, 'van', 'VAN-1', '2026-01-01', '2026-01-01', Money::of('1200', $this->kes), Money::of('0', $this->kes),
        Schedule::equal(Money::of('1200', $this->kes), ['2026-01-31', '2026-02-28']), $cost, $depreciation, $impairment, $expense, $funding, 'straight-line:v1'));

    $checks = checks($this->ledger, $book, '2026-01-01', '2026-12-31');
    expect($checks['periods_covered']['items'])->toBe(['2026-04-01'])
        ->and($checks['prior_earnings_closed']['items'])->toBe(['4000'])
        ->and($checks['depreciation_posted']['items'])->toBe(['VAN-1'])
        ->and($checks['foreign_items_revalued']['status'])->toBe('warning')
        ->and($checks['foreign_items_revalued']['count'])->toBe(1)
        ->and($checks['control_accounts_reconciled']['status'])->toBe('pass');
    $this->ledger->assets->depreciateDue($book, '2026-12-31');
    expect(checks($this->ledger, $book, '2026-01-01', '2026-12-31')['depreciation_posted']['status'])->toBe('pass');
});
