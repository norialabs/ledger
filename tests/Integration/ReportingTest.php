<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Money;
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
