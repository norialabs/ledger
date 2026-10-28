<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\BudgetLine;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;

it('reproduces reports after later backdated entries', function () {
    $post = function (string $key, string $amount): void {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $key, '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    };
    $post('first', '100');
    $frozen = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $post('backdated', '50');
    expect($this->ledger->reports->incomeStatement($frozen, '2026-01-01')['profit']->decimal())->toBe('100.00');
    $latest = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($latest, '2026-01-01')['profit']->decimal())->toBe('150.00');
    $sheet = $this->ledger->reports->balanceSheet($latest);
    expect($sheet['assets']->decimal())->toBe('150.00');
    expect($sheet['unclosed_earnings']->decimal())->toBe('150.00');
    expect($sheet['difference']->decimal())->toBe('0.00');
});

it('separates opening balances from reporting-period movement', function () {
    foreach (['2026-01-01' => '100', '2026-02-01' => '50'] as $date => $amount) {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $date, $date, 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    }
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01'))->keyBy('accountId');
    expect($balances[$this->cash]->opening->decimal())->toBe('100.00');
    expect($balances[$this->cash]->debits->decimal())->toBe('50.00');
    expect($balances[$this->cash]->closing->decimal())->toBe('150.00');
});

it('rejects future knowledge cutoffs across live financial reports', function (string $report) {
    $snapshot = new Snapshot($this->book, '2026-01-31', '1');
    match ($report) {
        'trial' => $this->ledger->reports->trialBalance($snapshot),
        'income' => $this->ledger->reports->incomeStatement($snapshot, '2026-01-01'),
        'balance' => $this->ledger->reports->balanceSheet($snapshot),
        'cash' => $this->ledger->reports->cashFlow($snapshot, '2026-01-01'),
        'items' => $this->ledger->reports->openItems($snapshot),
        'ledger' => $this->ledger->reports->generalLedger($snapshot),
    };
})->with(['trial', 'income', 'balance', 'cash', 'items', 'ledger'])->throws(LedgerException::class, 'future knowledge cutoff');

it('filters dimensions and paginates a fixed general-ledger snapshot', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $this->income, 'campus');
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'dimensioned', '2026-01-01', 'Fees', [
        new Posting($this->cash, Side::Debit, $money, dimensions: ['campus' => 'west']),
        new Posting($this->income, Side::Credit, $money, dimensions: ['campus' => 'west']),
    ]));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $first = $this->ledger->reports->generalLedger($snapshot, limit: 1);
    $second = $this->ledger->reports->generalLedger($snapshot, limit: 1, afterSequence: (string) $first[0]->sequence, afterLine: $first[0]->line_no);
    expect($first[0]->line_no)->toBe(1);
    expect($second[0]->line_no)->toBe(2);
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01', ['campus' => 'west'])['income']->decimal())->toBe('100.00');
});

it('renders comparative statements with group selectors and forward-referenced exact formulas', function () {
    $group = $this->ledger->catalog->account($this->book, '4900', 'Other revenue', AccountType::Income, group: true);
    $otherIncome = $this->ledger->catalog->account($this->book, '4910', 'Other income', AccountType::Income, parentId: $group);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Operating costs', AccountType::Expense);
    foreach ([['2026-01-01', '100', '30', $this->income], ['2026-02-01', '70', '20', $otherIncome]] as [$date, $revenue, $cost, $incomeAccount]) {
        $this->ledger->journals->post(new JournalRequest($this->book, 'income-'.$date, $date, 'Earned income', [
            new Posting($this->cash, Side::Debit, Money::of($revenue, $this->kes)), new Posting($incomeAccount, Side::Credit, Money::of($revenue, $this->kes)),
        ]));
        $this->ledger->journals->post(new JournalRequest($this->book, 'cost-'.$date, $date, 'Operating cost', [
            new Posting($expense, Side::Debit, Money::of($cost, $this->kes)), new Posting($this->cash, Side::Credit, Money::of($cost, $this->kes)),
        ]));
    }
    $definition = new StatementDefinition('Management profit', '2026.1', [
        'profit' => ['label' => 'Net profit', 'terms' => ['revenue' => '1', 'cost' => '-1'], 'note' => 'Reviewed example layout'],
        'revenue' => ['label' => 'Total revenue', 'accounts' => ['4000', '4900', '4910'], 'sign' => 'credit', 'include_closing' => false],
        'cost' => ['label' => 'Operating costs', 'accounts' => ['5000'], 'include_closing' => false],
        'cash' => ['label' => 'Closing cash', 'accounts' => ['1000'], 'basis' => 'closing'],
        'opening_cash' => ['label' => 'Opening cash', 'accounts' => ['1000'], 'basis' => 'opening'],
    ], ['income', 'expense']);
    $columns = [
        new StatementColumn('jan', 'January', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01'),
        new StatementColumn('feb', 'February', $this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01'),
    ];
    $report = $this->ledger->reports->statement($definition, $columns);
    expect($report['rows']['profit']['values']['jan']->decimal())->toBe('70.00');
    expect($report['rows']['profit']['values']['feb']->decimal())->toBe('50.00');
    expect($report['rows']['revenue']['values']['feb']->decimal())->toBe('70.00');
    expect($report['rows']['cash']['values']['feb']->decimal())->toBe('120.00');
    expect($report['rows']['opening_cash']['values']['feb']->decimal())->toBe('70.00');
    $id = $this->ledger->reports->saveStatement($this->book, 'management-2026', $definition, $columns);
    $frozen = $this->ledger->reports->statementEvidence($this->book, $id);
    expect(hash('sha256', $frozen['result_json']))->toBe($frozen['result_hash']);
    expect($frozen['result']['rows']['profit']['values']['feb']['amount'])->toBe('50.00');
    expect($frozen['result']['row_order'])->toBe(['profit', 'revenue', 'cost', 'cash', 'opening_cash']);
    expect(array_keys(StatementDefinition::fromArray($frozen['result']['definition'])->rows))->toBe(['profit', 'revenue', 'cost', 'cash', 'opening_cash']);
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-income', '2026-01-15', 'Backdated income', [
        new Posting($this->cash, Side::Debit, Money::of('15', $this->kes)), new Posting($this->income, Side::Credit, Money::of('15', $this->kes)),
    ]));
    expect($this->ledger->reports->saveStatement($this->book, 'management-2026', $definition, $columns))->toBe($id);
    expect($this->ledger->reports->statementEvidence($this->book, $id))->toBe($frozen);
    $this->storage->run(Permission::Read, fn () => $this->storage->table('ledger_statement_runs')->where('id', $id)->update(['result_json' => '{}']), $this->book);
})->throws(QueryException::class, 'append-only');

it('rejects incomplete statement mappings instead of silently hiding active accounts', function () {
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'unmapped-income', '2026-01-01', 'Revenue', [
        new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money),
    ]));
    $definition = new StatementDefinition('Incomplete', 'v1', ['profit' => ['label' => 'Profit', 'accounts' => []]], ['income']);
    $this->ledger->reports->statement($definition, [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')]);
})->throws(LedgerException::class, 'omits an active account: 4000');

it('rejects cyclic statement formulas before evaluating a report', function () {
    new StatementDefinition('Cyclic', 'v1', [
        'profit' => ['label' => 'Profit', 'terms' => ['subtotal' => '1']],
        'subtotal' => ['label' => 'Subtotal', 'terms' => ['profit' => '1']],
    ]);
})->throws(LedgerException::class, 'cannot contain cycles');

it('keeps dimension-filtered comparative columns separate and excludes fiscal closing from income rows', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'east');
    foreach (['west' => '100', 'east' => '200'] as $campus => $value) {
        $amount = Money::of($value, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $campus, '2026-01-01', 'Campus income', [
            new Posting($this->cash, Side::Debit, $amount, dimensions: ['campus' => $campus]),
            new Posting($this->income, Side::Credit, $amount, dimensions: ['campus' => $campus]),
        ]));
    }
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->yearEnd->close($this->book, 'close', '2026-01-01', '2026-12-31', $retained, 'Year close');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $definition = new StatementDefinition('Campus revenue', 'v1', ['revenue' => [
        'label' => 'Revenue', 'accounts' => ['4000'], 'sign' => 'credit', 'include_closing' => false,
    ]]);
    $report = $this->ledger->reports->statement($definition, [
        new StatementColumn('west', 'West', $snapshot, '2026-01-01', ['campus' => 'west']),
        new StatementColumn('east', 'East', $snapshot, '2026-01-01', ['campus' => 'east']),
    ]);
    expect($report['rows']['revenue']['values']['west']->decimal())->toBe('100.00');
    expect($report['rows']['revenue']['values']['east']->decimal())->toBe('200.00');
});

it('produces a party statement with opening balance, running balances and a fixed knowledge cutoff', function () {
    $party = $this->ledger->catalog->party($this->entity, 'G-1', 'Guardian');
    $other = $this->ledger->catalog->party($this->entity, 'G-2', 'Other guardian');
    $receivable = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $post = function (string $key, string $date, Side $side, string $amount, string $partyId, string $description) use ($receivable): void {
        $money = Money::of($amount, $this->kes);
        $counter = $side === Side::Debit ? $this->income : $this->cash;
        $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, $description, [
            new Posting($receivable, $side, $money, partyId: $partyId, dueOn: $side === Side::Debit ? '2026-02-28' : null),
            new Posting($counter, $side->opposite(), $money),
        ]));
    };
    $post('opening-invoice', '2026-01-10', Side::Debit, '300', $party, 'January fees');
    $post('february-invoice', '2026-02-01', Side::Debit, '500', $party, 'February fees');
    $post('other-invoice', '2026-02-02', Side::Debit, '999', $other, 'Other fees');
    $post('february-receipt', '2026-02-15', Side::Credit, '450', $party, 'Receipt');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $post('late-backdated', '2026-02-20', Side::Debit, '25', $party, 'Late fee');

    $statement = $this->ledger->reports->partyStatement($snapshot, $receivable, $party, '2026-02-01');
    expect($statement->opening->decimal())->toBe('300.00')
        ->and(array_map(fn ($line) => [$line['description'], $line['debit']->decimal(), $line['credit']->decimal(), $line['balance']->decimal()], $statement->lines))
        ->toBe([['February fees', '500.00', '0.00', '800.00'], ['Receipt', '0.00', '450.00', '350.00']])
        ->and($statement->closing->decimal())->toBe('350.00')
        ->and($statement->functionalClosing->decimal())->toBe('350.00')
        ->and($this->ledger->reports->partyStatement($this->ledger->reports->snapshot($this->book, '2026-02-28'), $receivable, $party)->closing->decimal())->toBe('375.00');
});

it('compares versioned budgets with actual movement by account and dimension', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'east');
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $salaries = $this->ledger->catalog->account($this->book, '5000', 'Salaries', AccountType::Expense);
    $travel = $this->ledger->catalog->account($this->book, '5100', 'Travel', AccountType::Expense);
    $lines = [];
    foreach (['01' => '31', '02' => '28', '03' => '31'] as $month => $last) {
        $lines[] = new BudgetLine($salaries, '2026-'.$month.'-01', '2026-'.$month.'-'.$last, Money::of('1000', $this->kes), ['campus' => 'east']);
        $lines[] = new BudgetLine($this->income, '2026-'.$month.'-01', '2026-'.$month.'-'.$last, Money::of('-3000', $this->kes));
    }
    $budget = $this->ledger->budgets->create($this->book, 'Operating', '2026.1', $lines);
    expect($this->ledger->budgets->create($this->book, 'Operating', '2026.1', array_reverse($lines)))->toBe($budget)
        ->and(fn () => $this->ledger->budgets->create($this->book, 'Operating', '2026.1', [$lines[0]]))->toThrow(LedgerException::class, 'new version');
    $post = function (string $key, string $date, string $account, string $amount, array $dimensions = []): void {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, $key, [
            new Posting($account, Side::Debit, $money, dimensions: $dimensions), new Posting($this->cash, Side::Credit, $money),
        ]));
    };
    $post('east-jan', '2026-01-25', $salaries, '900', ['campus' => 'east']);
    $post('east-feb', '2026-02-25', $salaries, '1250', ['campus' => 'east']);
    $post('west-feb', '2026-02-25', $salaries, '400', ['campus' => 'west']);
    $post('travel', '2026-02-10', $travel, '75');
    $money = Money::of('5500', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fees', '2026-02-01', 'Fees', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));

    $rows = $this->ledger->reports->budgetVariance($this->ledger->reports->snapshot($this->book, '2026-02-28'), $budget, '2026-01-01', includeUnbudgeted: true);
    expect(array_map(fn ($row) => [$row['code'], $row['dimensions'], $row['budget']->decimal(), $row['actual']->decimal(), $row['variance']->decimal()], $rows))->toBe([
        ['4000', [], '-6000.00', '-5500.00', '500.00'],
        ['5000', ['campus' => 'east'], '2000.00', '2150.00', '150.00'],
        ['5100', [], '0.00', '75.00', '75.00'],
    ])->and(fn () => $this->ledger->reports->budgetVariance($this->ledger->reports->snapshot($this->book, '2026-02-15'), $budget, '2026-01-01'))
        ->toThrow(LedgerException::class, 'partially overlaps')
        ->and($this->ledger->budgets->get($this->book, $budget)['lines'])->toHaveCount(6)
        ->and(array_column($this->ledger->budgets->list($this->book), 'version'))->toBe(['2026.1']);
});

it('rejects overlapping budget lines for the same account and dimensions', function () {
    $this->ledger->budgets->create($this->book, 'Overlap', '1', [
        new BudgetLine($this->income, '2026-01-01', '2026-01-31', Money::of('-10', $this->kes)),
        new BudgetLine($this->income, '2026-01-15', '2026-02-15', Money::of('-10', $this->kes)),
    ]);
})->throws(QueryException::class, 'cannot overlap');

it('recognizes cash-basis income when cash moves or invoices are settled, conserving every minor unit', function () {
    $party = $this->ledger->catalog->party($this->entity, 'C-1', 'Customer');
    $supplier = $this->ledger->catalog->party($this->entity, 'S-1', 'Supplier');
    $receivable = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $payable = $this->ledger->catalog->account($this->book, '2100', 'Payables', AccountType::Liability, control: true);
    $services = $this->ledger->catalog->account($this->book, '4100', 'Services', AccountType::Income);
    $supplies = $this->ledger->catalog->account($this->book, '5000', 'Supplies', AccountType::Expense);
    $depreciation = $this->ledger->catalog->account($this->book, '5900', 'Depreciation', AccountType::Expense);
    $equipment = $this->ledger->catalog->account($this->book, '1500', 'Accumulated depreciation', AccountType::Asset);
    $post = fn (string $key, string $date, array $postings) => $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, $key, $postings));
    $kes = fn (string $amount) => Money::of($amount, $this->kes);
    $item = fn (string $journalId) => collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-12-31')))->firstWhere('journalId', $journalId)->id;
    $allocate = fn (string $key, string $debit, string $credit, string $amount, string $date) => $this->ledger->settlements->allocate(
        new AllocationRequest($this->book, $key, $debit, $credit, $kes($amount), $date, 'Apply'));

    $post('cash-sale', '2026-01-05', [new Posting($this->cash, Side::Debit, $kes('50')), new Posting($this->income, Side::Credit, $kes('50'))]);
    $invoice = $post('invoice', '2026-01-10', [new Posting($receivable, Side::Debit, $kes('100'), partyId: $party),
        new Posting($this->income, Side::Credit, $kes('33.33')), new Posting($services, Side::Credit, $kes('66.67'))])->id;
    $post('depreciation', '2026-01-31', [new Posting($depreciation, Side::Debit, $kes('10')), new Posting($equipment, Side::Credit, $kes('10'))]);
    $mixed = $post('mixed', '2026-02-01', [new Posting($this->cash, Side::Debit, $kes('40')), new Posting($receivable, Side::Debit, $kes('60'), partyId: $party),
        new Posting($services, Side::Credit, $kes('100'))])->id;
    $credit = $post('credit-note', '2026-02-02', [new Posting($services, Side::Debit, $kes('20')), new Posting($receivable, Side::Credit, $kes('20'), partyId: $party)])->id;
    $allocate('credit-mixed', $item($mixed), $item($credit), '20', '2026-02-02');
    foreach (['1' => '2026-02-10', '2' => '2026-03-10', '3' => '2026-03-20'] as $n => $date) {
        $receipt = $post('receipt-'.$n, $date, [new Posting($this->cash, Side::Debit, $kes('33.33')), new Posting($receivable, Side::Credit, $kes('33.33'), partyId: $party)])->id;
        $allocate('apply-'.$n, $item($invoice), $item($receipt), '33.33', $date);
    }
    $final = $post('receipt-4', '2026-03-25', [new Posting($this->cash, Side::Debit, $kes('0.01')), new Posting($receivable, Side::Credit, $kes('0.01'), partyId: $party)])->id;
    $allocate('apply-4', $item($invoice), $item($final), '0.01', '2026-03-25');
    $bill = $post('bill', '2026-02-15', [new Posting($supplies, Side::Debit, $kes('90')), new Posting($payable, Side::Credit, $kes('90'), partyId: $supplier)])->id;
    $payment = $post('payment', '2026-03-05', [new Posting($payable, Side::Debit, $kes('90'), partyId: $supplier), new Posting($this->cash, Side::Credit, $kes('90'))])->id;
    $allocate('pay-bill', $item($payment), $item($bill), '90', '2026-03-05');

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $month = fn (string $from, string $to) => $this->ledger->reports->cashBasisIncomeStatement($this->ledger->reports->snapshot($this->book, $to), $from);
    $january = $month('2026-01-01', '2026-01-31');
    $february = $month('2026-02-01', '2026-02-28');
    $march = $month('2026-03-01', '2026-03-31');
    $quarter = $this->ledger->reports->cashBasisIncomeStatement($snapshot, '2026-01-01');
    expect([$january['income']->decimal(), $january['expenses']->decimal()])->toBe(['50.00', '0.00'])
        ->and([$february['income']->decimal(), $february['expenses']->decimal()])->toBe(['73.33', '0.00'])
        ->and([$march['income']->decimal(), $march['expenses']->decimal()])->toBe(['66.67', '90.00'])
        ->and($quarter['profit']->decimal())->toBe('100.00')
        ->and($quarter['accounts'][$this->income]->decimal())->toBe('-83.33')
        ->and($quarter['accounts'][$services]->decimal())->toBe('-106.67')
        ->and(isset($quarter['accounts'][$depreciation]))->toBeFalse()
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('130.00');
});
