<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\RateType;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Scheduling\Recurrence;
use Noria\Ledger\Scheduling\Schedule;
use Noria\Ledger\Session;

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
    $status = collect($checklist['checks'])->pluck('status', 'check')->all();
    expect($checklist['ready'])->toBeFalse()
        ->and($status)->toMatchArray(['periods_covered' => 'pass', 'prior_earnings_closed' => 'pass', 'drafts_decided' => 'fail', 'recurring_posted' => 'fail',
            'schedules_posted' => 'fail', 'depreciation_posted' => 'pass', 'control_accounts_reconciled' => 'pass', 'balance_sheet_balances' => 'pass',
            'bank_statements_cover_period_end' => 'warning', 'cash_flow_classified' => 'pass'])
        ->and(data_get(collect($checklist['checks'])->firstWhere('check', 'drafts_decided'), 'items'))->toBe([$draft->id])
        ->and(data_get(collect($checklist['checks'])->firstWhere('check', 'schedules_posted'), 'items'))->toBe([$schedule.':1'])
        ->and(fn () => $this->ledger->yearEnd->close($this->book, 'close', '2026-01-01', '2026-12-31', $retained, 'Close', requireChecklist: true))
        ->toThrow(LedgerException::class, 'drafts_decided, recurring_posted, schedules_posted')
        ->and(data_get(collect($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2027-03-31')['checks'])->firstWhere('check', 'periods_covered'), 'items'))->toBe(['2027-01-01']);

    $this->ledger->drafts->withdraw($this->book, $draft->id, 'Not needed');
    $this->ledger->schedules->postRecurring($this->book, '2026-12-31');
    $this->ledger->schedules->postDue($this->book, $schedule, '2026-12-31');
    expect($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['ready'])->toBeTrue();
    $this->ledger->yearEnd->close($this->book, 'close', '2026-01-01', '2026-12-31', $retained, 'Close', requireChecklist: true);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    expect(data_get(collect($this->ledger->yearEnd->checklist($this->book, '2027-01-01', '2027-12-31')['checks'])->firstWhere('check', 'prior_earnings_closed'), 'status'))->toBe('pass');
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

    $checks = collect($this->ledger->yearEnd->checklist($book, '2026-01-01', '2026-12-31')['checks'])->keyBy('check');
    expect(data_get($checks['periods_covered'], 'items'))->toBe(['2026-04-01'])
        ->and(data_get($checks['prior_earnings_closed'], 'items'))->toBe(['4000'])
        ->and(data_get($checks['depreciation_posted'], 'items'))->toBe(['VAN-1'])
        ->and(data_get($checks['foreign_items_revalued'], 'status'))->toBe('warning')
        ->and(data_get($checks['foreign_items_revalued'], 'count'))->toBe(1)
        ->and(data_get($checks['control_accounts_reconciled'], 'status'))->toBe('pass');
    $this->ledger->assets->depreciateDue($book, '2026-12-31');
    expect(data_get(collect($this->ledger->yearEnd->checklist($book, '2026-01-01', '2026-12-31')['checks'])->firstWhere('check', 'depreciation_posted'), 'status'))->toBe('pass');
});
