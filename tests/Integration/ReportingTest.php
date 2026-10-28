<?php

declare(strict_types=1);

use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\BudgetLine;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Reporting\Statement;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;

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
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01'));
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
        default => throw new UnexpectedValueException('Unknown report '.$report.'.'),
    };
})->with(['trial', 'income', 'balance', 'cash', 'items', 'ledger'])->throws(LedgerException::class, 'future knowledge cutoff');

it('filters dimensions and paginates a fixed general-ledger snapshot', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $this->income, 'campus');
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'dimensioned', '2026-01-01', 'Fees', [
        new Posting($this->cash, Side::Debit, $money, dimensions: ['campus' => 'west'], description: 'Cash side'),
        new Posting($this->income, Side::Credit, $money, dimensions: ['campus' => 'west']),
    ], ['reviewed' => 'fixture'], source: new Source('reporting', 'dimensioned')));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $first = $this->ledger->reports->generalLedger($snapshot, limit: 1);
    $second = $this->ledger->reports->generalLedger($snapshot, limit: 1, afterSequence: $first[0]->journal->sequence, afterLine: $first[0]->line->lineNo);
    expect($first[0]->line->lineNo)->toBe(1);
    expect($second[0]->line->lineNo)->toBe(2);
    expect($first[0]->journal->description)->toBe('Fees')
        ->and($first[0]->line->description)->toBe('Cash side')
        ->and($first[0]->line->amount->decimal())->toBe('100.00')
        ->and($first[0]->line->dimensions)->toBe(['campus' => 'west'])
        ->and($first[0]->tenantId)->toBe($this->scope->tenantId)
        ->and($first[0]->journal->source?->reference)->toBe('dimensioned')
        ->and($first[0]->journal->metadata)->toBe(['reviewed' => 'fixture'])
        ->and($first[0]->line->journalId)->toBe($first[0]->journal->id)
        ->and($first[0]->id === $first[0]->journal->id)->toBeFalse();
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01', ['campus' => 'west'])['income']->decimal())->toBe('100.00')
        ->and(array_map(fn ($entry) => $entry->line->accountId, $this->ledger->reports->generalLedger($snapshot, $this->income)))->toBe([$this->income]);
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
    expect($report->rows['profit']['values']['jan']->decimal())->toBe('70.00');
    expect($report->rows['profit']['values']['feb']->decimal())->toBe('50.00');
    expect($report->rows['revenue']['values']['feb']->decimal())->toBe('70.00');
    expect($report->rows['cash']['values']['feb']->decimal())->toBe('120.00');
    expect($report->rows['opening_cash']['values']['feb']->decimal())->toBe('70.00');
    $id = $this->ledger->reports->saveStatement($this->book, 'management-2026', $definition, $columns);
    $frozen = $this->ledger->reports->statementEvidence($this->book, $id);
    expect(hash('sha256', $frozen['result_json']))->toBe($frozen['result_hash']);
    expect($frozen['result']->rows['profit']['values']['feb']->decimal())->toBe('50.00');
    expect($frozen['result']->rowOrder)->toBe(['profit', 'revenue', 'cost', 'cash', 'opening_cash']);
    expect(array_keys($frozen['result']->definition->rows))->toBe(['profit', 'revenue', 'cost', 'cash', 'opening_cash']);
    expect(Canonical::json($frozen['result']))->toBe(Canonical::json($report));
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-income', '2026-01-15', 'Backdated income', [
        new Posting($this->cash, Side::Debit, Money::of('15', $this->kes)), new Posting($this->income, Side::Credit, Money::of('15', $this->kes)),
    ]));
    expect($this->ledger->reports->saveStatement($this->book, 'management-2026', $definition, $columns))->toBe($id);
    expect($this->ledger->reports->statementEvidence($this->book, $id))->toEqual($frozen);
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

it('hydrates historical statement JSON exactly and rejects incompatible saved result fields', function (string $case) {
    $data = [
        'definition' => ['name' => 'Historical report', 'version' => '1', 'rows' => ['profit' => ['label' => 'Saved profit', 'accounts' => ['4000']]], 'order' => ['profit'], 'coverage_types' => []],
        'columns' => [['key' => 'current', 'label' => 'Saved period', 'snapshot' => ['book_id' => $this->book, 'effective_on' => '2026-01-31', 'through_sequence' => '0'], 'starts_on' => '2026-01-01', 'dimensions' => ['campus' => 'west']]],
        'currency' => 'KES', 'row_order' => ['profit'], 'rows' => ['profit' => ['label' => 'Saved profit', 'note' => null, 'values' => ['current' => ['amount' => '900719925474099312345.67', 'currency' => 'KES', 'scale' => 2]]]],
    ];
    match ($case) {
        'structure' => data_set($data, 'columns', []),
        'currency' => data_set($data, 'rows.profit.values.current.currency', 'USD'),
        'precision' => data_set($data, 'rows.profit.values.current.amount', '1.001'),
        'missing_value' => data_set($data, 'rows.profit.values', []),
        'dimensions' => data_set($data, 'columns.0.dimensions.campus', false),
        'order' => data_set($data, 'row_order', ['absent']),
        'scale' => data_set($data, 'rows.profit.values.current.scale', '2'),
        'object' => data_set($data, 'columns.0.snapshot', false),
        default => null,
    };
    if (! is_array($data)) {
        throw new RuntimeException('Statement fixture must remain an object.');
    }
    if ($case !== 'valid') {
        expect(fn () => Statement::fromArray($data))->toThrow($case === 'precision' ? RoundingNecessaryException::class : LedgerException::class);

        return;
    }
    $id = (string) Str::uuid7();
    $before = $this->storage->run(Permission::Read, function () use ($id, $data): string {
        $this->storage->insert('ledger_statement_runs', ['id' => $id, 'book_id' => $this->book, 'idempotency_key' => 'historical-json', 'fingerprint' => hash('sha256', 'historical-json'), 'result_json' => json_encode($data, JSON_THROW_ON_ERROR)]);

        return Row::text($this->storage->find('ledger_statement_runs', $id, $this->book), 'result_json');
    }, $this->book);
    $evidence = $this->ledger->reports->statementEvidence($this->book, $id);
    expect($evidence['result']->rows['profit']['values']['current']->decimal())->toBe('900719925474099312345.67')
        ->and($evidence['result']->columns[0]->dimensions)->toBe(['campus' => 'west'])
        ->and($evidence['result']->definition->name)->toBe('Historical report')
        ->and($evidence['result_json'])->toBe($before)
        ->and($evidence['result_hash'])->toBe(hash('sha256', $before))
        ->and(Canonical::json($evidence['result']))->toBe(Canonical::json($data));
    $after = $this->storage->run(Permission::Read, fn (): string => Row::text($this->storage->find('ledger_statement_runs', $id, $this->book), 'result_json'), $this->book);
    expect($after)->toBe($before);
})->with(['valid', 'structure', 'currency', 'precision', 'missing_value', 'dimensions', 'order', 'scale', 'object']);

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
    expect($report->rows['revenue']['values']['west']->decimal())->toBe('100.00');
    expect($report->rows['revenue']['values']['east']->decimal())->toBe('200.00');
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
    $post = function (string $key, string $date, string $account, string $amount, ?string $campus = null): void {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, $key, [
            new Posting($account, Side::Debit, $money, dimensions: $campus === null ? [] : ['campus' => $campus]), new Posting($this->cash, Side::Credit, $money),
        ]));
    };
    $post('east-jan', '2026-01-25', $salaries, '900', 'east');
    $post('east-feb', '2026-02-25', $salaries, '1250', 'east');
    $post('west-feb', '2026-02-25', $salaries, '400', 'west');
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
    $post = fn (string $key, string $date, Posting ...$postings) => $this->ledger->journals->post(new JournalRequest($this->book, $key, $date, $key, $postings));
    $kes = fn (string $amount) => Money::of($amount, $this->kes);
    $item = fn (string $journalId) => (collect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-12-31')))->firstWhere('journalId', $journalId) ?? throw new RuntimeException('Open item is missing.'))->id;
    $allocate = fn (string $key, string $debit, string $credit, string $amount, string $date) => $this->ledger->settlements->allocate(
        new AllocationRequest($this->book, $key, $debit, $credit, $kes($amount), $date, 'Apply'));

    $post('cash-sale', '2026-01-05', new Posting($this->cash, Side::Debit, $kes('50')), new Posting($this->income, Side::Credit, $kes('50')));
    $invoice = $post('invoice', '2026-01-10', new Posting($receivable, Side::Debit, $kes('100'), partyId: $party),
        new Posting($this->income, Side::Credit, $kes('33.33')), new Posting($services, Side::Credit, $kes('66.67')))->id;
    $post('depreciation', '2026-01-31', new Posting($depreciation, Side::Debit, $kes('10')), new Posting($equipment, Side::Credit, $kes('10')));
    $mixed = $post('mixed', '2026-02-01', new Posting($this->cash, Side::Debit, $kes('40')), new Posting($receivable, Side::Debit, $kes('60'), partyId: $party),
        new Posting($services, Side::Credit, $kes('100')))->id;
    $credit = $post('credit-note', '2026-02-02', new Posting($services, Side::Debit, $kes('20')), new Posting($receivable, Side::Credit, $kes('20'), partyId: $party))->id;
    $allocate('credit-mixed', $item($mixed), $item($credit), '20', '2026-02-02');
    foreach (['1' => '2026-02-10', '2' => '2026-03-10', '3' => '2026-03-20'] as $n => $date) {
        $receipt = $post('receipt-'.$n, $date, new Posting($this->cash, Side::Debit, $kes('33.33')), new Posting($receivable, Side::Credit, $kes('33.33'), partyId: $party))->id;
        $allocate('apply-'.$n, $item($invoice), $item($receipt), '33.33', $date);
    }
    $final = $post('receipt-4', '2026-03-25', new Posting($this->cash, Side::Debit, $kes('0.01')), new Posting($receivable, Side::Credit, $kes('0.01'), partyId: $party))->id;
    $allocate('apply-4', $item($invoice), $item($final), '0.01', '2026-03-25');
    $bill = $post('bill', '2026-02-15', new Posting($supplies, Side::Debit, $kes('90')), new Posting($payable, Side::Credit, $kes('90'), partyId: $supplier))->id;
    $payment = $post('payment', '2026-03-05', new Posting($payable, Side::Debit, $kes('90'), partyId: $supplier), new Posting($this->cash, Side::Credit, $kes('90')))->id;
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

it('rejects malformed stored statement definitions field by field', function (string $path, mixed $value, string $message) {
    $data = ['name' => 'Stored', 'version' => '1', 'rows' => ['profit' => ['label' => 'Profit', 'accounts' => ['4000'], 'terms' => ['other' => '1'], 'basis' => 'movement', 'include_closing' => true],
        'other' => ['label' => 'Other', 'accounts' => ['5000']]], 'order' => ['profit', 'other'], 'coverage_types' => ['income']];
    data_set($data, $path, $value);
    if (! is_array($data)) {
        throw new RuntimeException('Statement fixture must remain an object.');
    }
    expect(fn () => StatementDefinition::fromArray($data))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'name' => ['name', 1, 'Invalid statement definition fields.'],
    'order count' => ['order', ['profit'], 'Statement row order must list every row exactly once.'],
    'order key' => ['order', ['profit', 'absent'], 'Statement row order must list every row exactly once.'],
    'row label' => ['rows.other.label', 7, 'Invalid statement row.'],
    'accounts' => ['rows.profit.accounts', '4000', 'Statement account selectors must be a list.'],
    'account code' => ['rows.profit.accounts', [4000], 'Statement account codes must be strings.'],
    'terms' => ['rows.profit.terms', 'other', 'Statement formula terms must be a map.'],
    'coefficient' => ['rows.profit.terms', ['other' => 1], 'Statement formula references and coefficients must be strings.'],
    'basis' => ['rows.profit.basis', 1, 'Statement row setting must be a string.'],
    'closing' => ['rows.profit.include_closing', 'yes', 'Statement closing selector must be a boolean.'],
    'coverage' => ['coverage_types', [1], 'Statement coverage types must be strings.'],
]);

it('rejects saved statement columns whose dimensions are not a map', function () {
    Statement::fromArray([
        'definition' => ['name' => 'Saved', 'version' => '1', 'rows' => ['profit' => ['label' => 'Profit', 'accounts' => ['4000']]]],
        'columns' => [['key' => 'current', 'label' => 'Current', 'snapshot' => ['book_id' => $this->book, 'effective_on' => '2026-01-31', 'through_sequence' => '0'], 'starts_on' => '2026-01-01', 'dimensions' => 'west']],
        'currency' => 'KES', 'row_order' => ['profit'], 'rows' => ['profit' => ['label' => 'Profit', 'note' => null, 'values' => ['current' => ['amount' => '1.00', 'currency' => 'KES', 'scale' => 2]]]],
    ]);
})->throws(LedgerException::class, 'Invalid saved statement dimensions.');

it('renders gross debit and credit statement rows separately from net movement', function () {
    $money = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-01-05', 'Receipt', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'refund', '2026-01-06', 'Refund', [
        new Posting($this->income, Side::Debit, Money::of('30', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('30', $this->kes)),
    ]));
    $definition = new StatementDefinition('Cash activity', '1', [
        'receipts' => ['label' => 'Receipts', 'accounts' => ['1000'], 'basis' => 'debits'],
        'payments' => ['label' => 'Payments', 'accounts' => ['1000'], 'basis' => 'credits'],
        'net' => ['label' => 'Net movement', 'accounts' => ['1000']],
    ]);
    $report = $this->ledger->reports->statement($definition, [new StatementColumn('jan', 'January', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')]);
    expect(array_map(fn (array $row): string => $row['values']['jan']->decimal(), $report->rows))->toBe(['receipts' => '100.00', 'payments' => '30.00', 'net' => '70.00']);
});
