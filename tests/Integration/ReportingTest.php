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
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\BudgetLine;
use Noria\Ledger\Reporting\PartyStatement;
use Noria\Ledger\Reporting\ReportingMapping;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Reporting\Statement;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Reporting\StatementKind;
use Noria\Ledger\Session;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;

/** @return array{mapping: ReportingMapping, capital: string, retained: string, reserve: string} */
function equityReportingFixture(Session $ledger, string $book): array
{
    return ['capital' => $ledger->catalog->account($book, '3000', 'Share capital', AccountType::Equity),
        'reserve' => $ledger->catalog->account($book, '3100', 'OCI reserve', AccountType::Equity),
        'retained' => $ledger->catalog->account($book, '3200', 'Retained earnings', AccountType::Equity),
        'mapping' => new ReportingMapping('Reviewed financial statements', '1', 'Trading', [
            '3000' => ['equity_component' => 'capital'], '3100' => ['oci' => 'recyclable', 'equity_component' => 'reserve'],
            '3200' => ['equity_component' => 'retained'], '4000' => ['profit_or_loss' => 'operating'],
        ], true, ['capital' => 'Share capital', 'retained' => 'Retained earnings', 'reserve' => 'OCI reserve'])];
}

it('reconciles budget commitments invoice fulfilment cancellations and exact corrections at immutable cutoffs', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $budget = $this->ledger->budgets->create($this->book, 'Purchasing', '1', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes))]);
    $policy = $this->ledger->budgets->selectPolicy($this->book, $budget, 'reviewed:1', true, true);
    expect($this->ledger->budgets->policy($this->book, $policy)->version)->toBe('reviewed:1');
    expect($this->ledger->budgets->selectPolicy($this->book, $budget, 'reviewed:1', true, true))->toBe($policy);
    expect(fn () => $this->ledger->budgets->selectPolicy($this->book, $budget, 'reviewed:1', false, true))->toThrow(LedgerException::class);
    $commitment = $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:1', '2026-01-01', Money::of('400', $this->kes), 'Approved PO 1');
    expect($this->ledger->budgets->commit($this->book, $budget, 1, 'PO:1', '2026-01-01', Money::of('400', $this->kes), 'Approved PO 1'))->toBe($commitment);
    expect(fn () => $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:1', '2026-01-01', Money::of('401', $this->kes), 'Approved PO 1'))->toThrow(LedgerException::class);
    $reserved = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $request = new JournalRequest($this->book, 'invoice:1', '2026-01-02', 'Invoice 1', [
        new Posting($expense, Side::Debit, Money::of('300', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('300', $this->kes)),
    ], ['budget_commitments' => [$commitment => '30000']]);
    $invoice = $this->ledger->journals->post($request);
    expect($this->ledger->journals->post($request)->id)->toBe($invoice->id);
    $fulfilled = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $position = $this->ledger->budgets->position($fulfilled, $budget)[0];
    expect($position->actual->decimal())->toBe('300.00')->and($position->openCommitment->decimal())->toBe('100.00')->and($position->available->decimal())->toBe('600.00');
    $cancel = $this->ledger->budgets->cancel($this->book, $commitment, 'cancel:1', '2026-01-03', Money::of('100', $this->kes), 'Remainder cancelled');
    expect($this->ledger->budgets->cancel($this->book, $commitment, 'cancel:1', '2026-01-03', Money::of('100', $this->kes), 'Remainder cancelled'))->toBe($cancel);
    $cancelled = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->budgets->position($cancelled, $budget)[0]->available->decimal())->toBe('700.00');
    $this->ledger->budgets->reverse($this->book, $cancel, 'undo:cancel', '2026-01-04', 'Reinstate remainder');
    $this->ledger->journals->reverse($this->book, $invoice->id, 'undo:invoice', '2026-01-05', 'Wrong invoice');
    $corrected = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $position = $this->ledger->budgets->position($corrected, $budget)[0];
    expect($position->actual->decimal())->toBe('0.00')->and($position->openCommitment->decimal())->toBe('400.00')->and($position->available->decimal())->toBe('600.00');
    expect($this->ledger->budgets->position($reserved, $budget)[0]->actual->decimal())->toBe('0.00')
        ->and($this->ledger->budgets->position($fulfilled, $budget)[0]->openCommitment->decimal())->toBe('100.00')
        ->and($this->ledger->budgets->position($cancelled, $budget)[0]->available->decimal())->toBe('700.00');
    $history = $this->ledger->budgets->history($corrected, $commitment);
    expect(array_map(fn ($event) => $event->kind, $history))->toBe(['commitment', 'fulfilment', 'cancellation', 'reversal', 'reversal'])
        ->and($history[1]->journalId)->toBe($invoice->id)->and($history[1]->sequence)->toBe($invoice->sequence)->and($history[4]->reversesId)->toBe($history[1]->id)
        ->and($history[0]->policyId)->toBe($policy)->and($history[0]->actorId)->toBe($this->scope->actorId);
    $undo = $this->ledger->budgets->reverse($this->book, $commitment, 'undo:po', '2026-01-06', 'Withdraw PO');
    expect($this->ledger->budgets->reverse($this->book, $commitment, 'undo:po', '2026-01-06', 'Withdraw PO'))->toBe($undo);
    expect($this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $budget)[0]->available->decimal())->toBe('1000.00');
});

it('enforces commitment budgets across posting runs and rejects forged releases atomically', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $budget = $this->ledger->budgets->create($this->book, 'Purchasing', '1', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes))]);
    $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, true);
    $commitment = $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:1', '2026-01-01', Money::of('400', $this->kes), 'PO');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect(fn () => $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:2', '2026-01-01', Money::of('601', $this->kes), 'Over budget'))->toThrow(QueryException::class, 'Budget exceeded');
    expect(fn () => $this->ledger->budgets->cancel($this->book, $commitment, 'cancel:excess', '2026-01-02', Money::of('401', $this->kes), 'Excess'))->toThrow(QueryException::class, 'exceeds its balance');
    $post = fn (string $key, string $amount, ?string $releaseMinor = null): JournalRequest => new JournalRequest($this->book, $key, '2026-01-02', $key, [
        new Posting($expense, Side::Debit, Money::of($amount, $this->kes)), new Posting($this->cash, Side::Credit, Money::of($amount, $this->kes)),
    ], $releaseMinor === null ? [] : ['budget_commitments' => [$commitment => $releaseMinor]]);
    expect(fn () => $this->ledger->journals->post($post('excess', '601')))->toThrow(QueryException::class, 'Budget exceeded');
    expect(fn () => $this->ledger->journals->post($post('false-release', '300', '30100')))->toThrow(QueryException::class, 'exceeds matching invoice');
    expect(fn () => $this->ledger->journals->postRun($this->book, [$post('run:1', '400'), $post('run:2', '201')]))->toThrow(LedgerException::class);
    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($before->throughSequence);
    $run = $this->ledger->journals->postRun($this->book, [$post('fulfilled-run', '300', '30000'), $post('other-spend', '600')]);
    expect($run->posted)->toBe(2);
    expect($this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $budget)[0]->available->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, function () use ($commitment, $run): void {
        expect(fn () => $this->storage->connection->transaction(fn () => $this->storage->table('ledger_commitments')->where('id', $commitment)->update(['amount_minor' => '1'])))->toThrow(QueryException::class);
        $original = $this->storage->find('ledger_commitments', $commitment, $this->book);
        $forged = ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'budget_id' => Row::text($original, 'budget_id'), 'line_no' => 1,
            'kind' => 'fulfilment', 'commitment_id' => $commitment, 'amount_minor' => '-1', 'effective_on' => '2026-01-02',
            'idempotency_key' => 'forged', 'fingerprint' => Row::text($original, 'fingerprint'), 'reason' => 'Forged release'];
        expect(fn () => $this->storage->connection->transaction(fn () => $this->storage->insert('ledger_commitments', $forged)))->toThrow(QueryException::class, 'requires a journal');
        expect(fn () => $this->storage->connection->transaction(fn () => $this->storage->insert('ledger_commitments', [...$forged, 'journal_id' => $run->journalIds['other-spend']])))
            ->toThrow(QueryException::class, 'generated by its journal');
    }, $this->book);
});

it('matches commitment dimensions and periods without sharing capacity between budget lines', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    foreach (['north', 'south', 'west'] as $value) {
        $this->ledger->catalog->dimensionValue($this->entity, 'branch', $value);
    }
    $budget = $this->ledger->budgets->create($this->book, 'Branches', '1', [
        new BudgetLine($expense, '2026-01-01', '2026-01-31', Money::of('1000', $this->kes), ['branch' => 'north']),
        new BudgetLine($expense, '2026-01-01', '2026-01-31', Money::of('2000', $this->kes), ['branch' => 'south']),
    ]);
    $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, true);
    $commitment = $this->ledger->budgets->commit($this->book, $budget, 1, 'north-po', '2026-01-01', Money::of('1000', $this->kes), 'North PO');
    $post = fn (string $branch, string $date, string $key): JournalRequest => new JournalRequest($this->book, $key, $date, $key, [
        new Posting($expense, Side::Debit, Money::of('300', $this->kes), dimensions: ['branch' => $branch]),
        new Posting($this->cash, Side::Credit, Money::of('300', $this->kes)),
    ], ['budget_commitments' => [$commitment => '30000']]);
    expect(fn () => $this->ledger->journals->post($post('south', '2026-01-02', 'wrong-branch')))->toThrow(QueryException::class, 'exceeds matching invoice');
    expect(fn () => $this->ledger->journals->post($post('north', '2026-02-01', 'wrong-period')))->toThrow(QueryException::class, 'outside budget line');
    $invoice = $this->ledger->journals->post($post('north', '2026-01-02', 'north-invoice'));
    $atInvoice = new Snapshot($this->book, '2026-01-02', $invoice->sequence);
    expect($this->ledger->budgets->position($atInvoice, $budget)[0]->openCommitment->decimal())->toBe('700.00');
    $position = $this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $budget);
    expect($position[0]->available->decimal())->toBe('0.00')->and($position[1]->available->decimal())->toBe('2000.00');
    expect(fn () => $this->ledger->budgets->cancel($this->book, $commitment, 'backdate', '2026-01-01', Money::of('1', $this->kes), 'Backdate'))->toThrow(QueryException::class, 'chronological');
    expect(fn () => $this->ledger->budgets->reverse($this->book, $commitment, 'withdraw', '2026-01-03', 'Withdraw consumed PO'))->toThrow(QueryException::class, 'exceeds its balance');
    $this->ledger->journals->reverse($this->book, $invoice->id, 'undo-invoice', '2026-02-01', 'Correction after budget period');
    $february = $this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $budget)[0];
    expect($february->openCommitment->decimal())->toBe('1000.00');
});

it('requires an explicit budget policy and retains report-only and strict elections as immutable evidence', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $unbudgeted = $this->ledger->catalog->account($this->book, '5100', 'Other', AccountType::Expense);
    $budget = $this->ledger->budgets->create($this->book, 'Purchasing', '1', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes))]);
    expect(fn () => $this->ledger->budgets->commit($this->book, $budget, 1, 'no-policy', '2026-01-01', Money::of('1', $this->kes), 'PO'))->toThrow(QueryException::class);
    $policy = $this->ledger->budgets->selectPolicy($this->book, $budget, 'report-only:1', false, false);
    $commitment = $this->ledger->budgets->commit($this->book, $budget, 1, 'oversized-po', '2026-01-01', Money::of('1100', $this->kes), 'Reviewed exception');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->budgets->position($snapshot, $budget)[0]->available->decimal())->toBe('-100.00');
    expect(fn () => $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, true))->toThrow(QueryException::class, 'Budget exceeded');
    $replacement = $this->ledger->budgets->create($this->book, 'Purchasing', '2', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('2000', $this->kes))]);
    expect(fn () => $this->ledger->budgets->selectPolicy($this->book, $replacement, 'new-budget:1', true, true))->toThrow(QueryException::class, 'Release open commitments');
    $this->ledger->budgets->cancel($this->book, $commitment, 'cancel', '2026-01-02', Money::of('1100', $this->kes), 'Withdraw exception');
    $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:2', true, true);
    $request = new JournalRequest($this->book, 'unbudgeted', '2026-01-02', 'Unbudgeted spend', [
        new Posting($unbudgeted, Side::Debit, Money::of('1', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('1', $this->kes)),
    ]);
    expect(fn () => $this->ledger->journals->post($request))->toThrow(QueryException::class, 'no selected budget line');
    $allowUnbudgeted = $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:3', true, false);
    expect($this->ledger->journals->post($request)->budgetPolicyId)->toBe($allowUnbudgeted);
    expect($this->ledger->budgets->history($snapshot, $commitment)[0]->policyId)->toBe($policy);
    $this->ledger->budgets->selectPolicy($this->book, $replacement, 'new-budget:1', true, true);
    expect(fn () => $this->ledger->budgets->commit($this->book, $budget, 1, 'old-version', '2026-01-03', Money::of('1', $this->kes), 'Old PO'))->toThrow(QueryException::class, 'selected budget version');
});

it('rejects ambiguous expense budget coverage before selecting a commitment policy', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $this->ledger->catalog->dimensionValue($this->entity, 'branch', 'north');
    $budget = $this->ledger->budgets->create($this->book, 'Ambiguous', '1', [
        new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes)),
        new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes), ['branch' => 'north']),
    ]);
    expect(fn () => $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, true))->toThrow(QueryException::class, 'unambiguous coverage');
});

it('caps combined fulfilments at invoice expenditure and restores every reservation on correction', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $budget = $this->ledger->budgets->create($this->book, 'Purchasing', '1', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes))]);
    $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, true);
    $first = $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:1', '2026-01-01', Money::of('600', $this->kes), 'PO 1');
    $second = $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:2', '2026-01-01', Money::of('400', $this->kes), 'PO 2');
    $postings = [new Posting($expense, Side::Debit, Money::of('300', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('300', $this->kes))];
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'over-release', '2026-01-02', 'Over release', $postings,
        ['budget_commitments' => [$first => '20000', $second => '20000']])))->toThrow(QueryException::class, 'exceeds matching invoice');
    $invoice = $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-02', 'Split fulfilment', $postings,
        ['budget_commitments' => [$first => '20000', $second => '10000']]));
    $position = $this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $budget)[0];
    expect($position->actual->decimal())->toBe('300.00')->and($position->openCommitment->decimal())->toBe('700.00')->and($position->available->decimal())->toBe('0.00');
    $this->ledger->journals->reverse($this->book, $invoice->id, 'undo', '2026-01-03', 'Correct split invoice');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->budgets->position($snapshot, $budget)[0]->openCommitment->decimal())->toBe('1000.00');
    foreach ([$first, $second] as $root) {
        $event = $this->ledger->budgets->history($snapshot, $root)[1];
        expect(fn () => $this->ledger->budgets->reverse($this->book, $event->id, 'detach-'.$root, '2026-01-04', 'Detached correction'))->toThrow(LedgerException::class, 'through its journal');
    }
});

it('restores fulfilments through backdated journal reversals and after the selected budget version changes', function () {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $budget = $this->ledger->budgets->create($this->book, 'Purchasing', '1', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes))]);
    $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, false);
    $commitment = $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:1', '2026-01-01', Money::of('400', $this->kes), 'PO 1');
    $invoice = $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-10', 'Invoice', [
        new Posting($expense, Side::Debit, Money::of('300', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('300', $this->kes)),
    ], ['budget_commitments' => [$commitment => '30000']]));
    $this->ledger->budgets->cancel($this->book, $commitment, 'cancel', '2026-01-20', Money::of('100', $this->kes), 'Remainder cancelled');
    $replacement = $this->ledger->budgets->create($this->book, 'Purchasing', '2', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('5000', $this->kes))]);
    $this->ledger->budgets->selectPolicy($this->book, $replacement, 'strict:2', true, false);
    $this->ledger->journals->reverse($this->book, $invoice->id, 'undo-invoice', '2026-01-15', 'Backdated correction');
    $midMonth = $this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-01-16'), $budget)[0];
    expect($midMonth->actual->decimal())->toBe('0.00')->and($midMonth->openCommitment->decimal())->toBe('400.00');
    expect($this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $budget)[0]->openCommitment->decimal())->toBe('300.00');
    expect(fn () => $this->ledger->budgets->commit($this->book, $budget, 1, 'PO:old', '2026-01-21', Money::of('1', $this->kes), 'Old version'))->toThrow(QueryException::class, 'selected budget version');
    $this->ledger->budgets->cancel($this->book, $commitment, 'cancel-restored', '2026-01-21', Money::of('300', $this->kes), 'Restored reservation cancelled');
    expect($this->ledger->budgets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $budget)[0]->available->decimal())->toBe('1000.00')
        ->and(array_map(fn ($event) => $event->kind, $this->ledger->budgets->history($this->ledger->reports->snapshot($this->book, '2026-12-31'), $commitment)))
        ->toBe(['commitment', 'fulfilment', 'cancellation', 'reversal', 'cancellation']);
});

it('rejects malformed direct SQL commitment amounts and relationships without consuming sequence', function (string $field, string $value) {
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Purchases', AccountType::Expense);
    $budget = $this->ledger->budgets->create($this->book, 'Purchasing', '1', [new BudgetLine($expense, '2026-01-01', '2026-12-31', Money::of('1000', $this->kes))]);
    $this->ledger->budgets->selectPolicy($this->book, $budget, 'strict:1', true, true);
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect(fn () => $this->storage->run(Permission::Post, fn () => $this->storage->insert('ledger_commitments', [
        'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'budget_id' => $budget, 'line_no' => 1,
        'kind' => 'commitment', 'amount_minor' => '100', 'effective_on' => '2026-01-01', 'idempotency_key' => 'forged',
        'fingerprint' => str_repeat('a', 64), 'reason' => 'Forged reservation', $field => $value,
    ]), $this->book))->toThrow(QueryException::class);
    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($before->throughSequence);
})->with([
    ['amount_minor', '0.5'], ['amount_minor', '1'.str_repeat('0', 38)], ['amount_minor', '-1'], ['line_no', '0'],
    ['line_no', '2'], ['effective_on', '2025-12-31'], ['kind', 'fulfilment'], ['reason', ''],
]);

it('reconciles comprehensive income and equity through closing restatements and immutable configured evidence', function () {
    ['mapping' => $mapping, 'capital' => $capital, 'retained' => $retained, 'reserve' => $reserve] = equityReportingFixture($this->ledger, $this->book);
    foreach ([['opening', '1000', $capital, 'opening_balance'], ['profit', '200', $this->income, null],
        ['oci', '60', $reserve, 'oci'], ['issue', '100', $capital, 'contribution']] as [$key, $amount, $account, $kind]) {
        $money = Money::of($amount, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, 'equity-'.$key, '2026-01-01', $key, [
            new Posting($this->cash, Side::Debit, $money), new Posting($account, Side::Credit, $money,
                reportingMovement: $kind === null ? null : new ReportingMovement($kind, 'reviewed:1', $key, $kind === 'oci' ? 'recyclable' : null)),
        ]));
    }
    $this->ledger->journals->post(new JournalRequest($this->book, 'dividend', '2026-01-02', 'Dividend', [
        new Posting($capital, Side::Debit, Money::of('50', $this->kes), reportingMovement: new ReportingMovement('distribution', 'reviewed:1', 'Dividend')),
        new Posting($this->cash, Side::Credit, Money::of('50', $this->kes)),
    ]));
    $transfer = new ReportingMovement('transfer', 'reviewed:1', 'Reserve transfer');
    $this->ledger->journals->post(new JournalRequest($this->book, 'reserve-transfer', '2026-01-03', 'Transfer', [
        new Posting($reserve, Side::Debit, Money::of('20', $this->kes), reportingMovement: $transfer),
        new Posting($retained, Side::Credit, Money::of('20', $this->kes), reportingMovement: $transfer),
    ]));
    $columns = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')];
    $comprehensive = $this->ledger->reports->comprehensiveIncome($mapping, $columns);
    $equity = $this->ledger->reports->changesInEquity($mapping, $columns, 'retained');
    expect($comprehensive->rows['profit']['values']['current']->decimal())->toBe('200.00')
        ->and($comprehensive->rows['total_oci']['values']['current']->decimal())->toBe('60.00')
        ->and($comprehensive->rows['total_comprehensive_income']['values']['current']->decimal())->toBe('260.00')
        ->and($equity->rows['total.opening']['values']['current']->decimal())->toBe('1000.00')
        ->and($equity->rows['total.contributions']['values']['current']->decimal())->toBe('100.00')
        ->and($equity->rows['total.distributions']['values']['current']->decimal())->toBe('-50.00')
        ->and($equity->rows['total.transfers']['values']['current']->decimal())->toBe('0.00')
        ->and($equity->rows['capital.closing']['values']['current']->decimal())->toBe('1050.00')
        ->and($equity->rows['retained.closing']['values']['current']->decimal())->toBe('220.00')
        ->and($equity->rows['reserve.closing']['values']['current']->decimal())->toBe('40.00')
        ->and($equity->rows['total.closing']['values']['current']->decimal())->toBe('1310.00')
        ->and($equity->rows['total.difference']['values']['current']->decimal())->toBe('0.00');
    $configuration = $this->ledger->configurations->install($this->book, new Pack('financial-statements', '1', statements: [$comprehensive->definition, $equity->definition], reportingMapping: $mapping));
    $saved = [];
    foreach ([$comprehensive, $equity] as $index => $report) {
        $saved[$index] = $this->ledger->reports->saveConfiguredStatement($this->book, 'financial-'.$index, $configuration, $report->definition->name, $columns);
    }
    $evidence = array_map(fn (string $id): array => $this->ledger->reports->statementEvidence($this->book, $id), $saved);
    expect($evidence[1]['result']->rows)->toEqual($equity->rows)
        ->and($evidence[1]['result']->definition->earningsComponent)->toBe('retained')
        ->and(hash('sha256', $evidence[1]['result_json']))->toBe($evidence[1]['result_hash']);
    $closure = $this->ledger->yearEnd->close($this->book, 'equity-close', '2026-01-01', '2026-12-31', $retained, 'Close year');
    $after = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')];
    expect($this->ledger->reports->comprehensiveIncome($mapping, $after)->rows)->toEqual($comprehensive->rows)
        ->and($this->ledger->reports->changesInEquity($mapping, $after, 'retained')->rows)->toEqual($equity->rows)
        ->and(fn () => $this->ledger->reports->changesInEquity($mapping, $after, 'capital'))->toThrow(LedgerException::class, 'does not reconcile');
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $openingColumns = [new StatementColumn('next', 'Next year', $this->ledger->reports->snapshot($this->book, '2027-01-31'), '2027-01-01')];
    $opening = $this->ledger->reports->changesInEquity($mapping, $openingColumns, 'retained');
    $this->ledger->yearEnd->restate($this->book, 'equity-restatement', $closure, '2027-01-01', [
        new Posting($this->cash, Side::Debit, Money::of('30', $this->kes)), new Posting($this->income, Side::Credit, Money::of('30', $this->kes)),
    ], $retained, 'restatement-policy:1', 'Correct prior income');
    $restatedColumns = [new StatementColumn('next', 'Next year', $this->ledger->reports->snapshot($this->book, '2027-01-31'), '2027-01-01')];
    $restated = $this->ledger->reports->changesInEquity($mapping, $restatedColumns, 'retained');
    expect($restated->rows['total.opening']['values']['next']->decimal())->toBe('1310.00')
        ->and($restated->rows['total.opening_adjustments']['values']['next']->decimal())->toBe('30.00')
        ->and($restated->rows['total.restated_opening']['values']['next']->decimal())->toBe('1340.00')
        ->and($restated->rows['total.profit']['values']['next']->decimal())->toBe('0.00')
        ->and($restated->rows['total.closing']['values']['next']->decimal())->toBe('1340.00')
        ->and($this->ledger->reports->comprehensiveIncome($mapping, $restatedColumns)->rows['total_comprehensive_income']['values']['next']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->changesInEquity($mapping, $openingColumns, 'retained')->rows)->toEqual($opening->rows);
    foreach ($saved as $index => $id) {
        expect($this->ledger->reports->statementEvidence($this->book, $id))->toEqual($evidence[$index]);
    }
});

it('rolls unclosed OCI income into its explicit earnings component without treating it as profit', function () {
    ['mapping' => $base, 'retained' => $retained] = equityReportingFixture($this->ledger, $this->book);
    $oci = $this->ledger->catalog->account($this->book, '4100', 'Non-recyclable OCI income', AccountType::Income);
    $mapping = new ReportingMapping($base->name, $base->version, $base->mainBusinessActivity,
        $base->accounts + ['4100' => ['oci' => 'non_recyclable']], true, $base->equityComponents);
    $this->ledger->journals->post(new JournalRequest($this->book, 'oci-income', '2026-01-01', 'Reviewed OCI recognition', [
        new Posting($this->cash, Side::Debit, Money::of('260', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('200', $this->kes)),
        new Posting($oci, Side::Credit, Money::of('60', $this->kes), reportingMovement: new ReportingMovement('oci', 'reviewed:1', 'Non-recyclable gain', 'non_recyclable')),
    ]));
    $columns = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')];
    $income = $this->ledger->reports->comprehensiveIncome($mapping, $columns);
    $equity = $this->ledger->reports->changesInEquity($mapping, $columns, 'retained');
    expect($income->rows['profit']['values']['current']->decimal())->toBe('200.00')
        ->and($income->rows['total_comprehensive_income']['values']['current']->decimal())->toBe('260.00')
        ->and($equity->rows['retained.oci_non_recyclable']['values']['current']->decimal())->toBe('60.00')
        ->and($equity->rows['retained.closing']['values']['current']->decimal())->toBe('260.00');
    $this->ledger->yearEnd->close($this->book, 'oci-income-close', '2026-01-01', '2026-12-31', $retained, 'Close income including OCI');
    $closed = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')];
    expect($this->ledger->reports->comprehensiveIncome($mapping, $closed)->rows)->toEqual($income->rows)
        ->and($this->ledger->reports->changesInEquity($mapping, $closed, 'retained')->rows)->toEqual($equity->rows);
});

it('keeps equity comparatives dimensions and knowledge cutoffs independent', function () {
    ['mapping' => $mapping, 'capital' => $capital, 'reserve' => $reserve] = equityReportingFixture($this->ledger, $this->book);
    foreach (['west' => ['200', '10', '5'], 'east' => ['300', '20', '7']] as $campus => [$opening, $profit, $oci]) {
        $this->ledger->catalog->dimensionValue($this->entity, 'campus', $campus);
        foreach ([['opening_balance', $opening, $capital, '2026-01-01'], [null, $profit, $this->income, '2026-02-01'], ['oci', $oci, $reserve, '2026-02-01']] as [$kind, $amount, $account, $date]) {
            $money = Money::of($amount, $this->kes);
            $this->ledger->journals->post(new JournalRequest($this->book, $campus.'-'.($kind ?? 'profit'), $date, $campus, [
                new Posting($this->cash, Side::Debit, $money, dimensions: ['campus' => $campus]),
                new Posting($account, Side::Credit, $money, dimensions: ['campus' => $campus],
                    reportingMovement: $kind === null ? null : new ReportingMovement($kind, 'reviewed:1', $campus, $kind === 'oci' ? 'recyclable' : null)),
            ]));
        }
    }
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $columns = [new StatementColumn('west', 'West current', $snapshot, '2026-02-01', ['campus' => 'west']),
        new StatementColumn('east', 'East current', $snapshot, '2026-02-01', ['campus' => 'east']),
        new StatementColumn('previous', 'West previous', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01', ['campus' => 'west'])];
    $equity = $this->ledger->reports->changesInEquity($mapping, $columns, 'retained');
    expect($equity->rows['total.opening']['values']['west']->decimal())->toBe('200.00')
        ->and($equity->rows['total.closing']['values']['west']->decimal())->toBe('215.00')
        ->and($equity->rows['total.closing']['values']['east']->decimal())->toBe('327.00')
        ->and($equity->rows['total.closing']['values']['previous']->decimal())->toBe('200.00');
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-west-profit', '2026-02-02', 'Late revenue', [
        new Posting($this->cash, Side::Debit, Money::of('3', $this->kes), dimensions: ['campus' => 'west']),
        new Posting($this->income, Side::Credit, Money::of('3', $this->kes), dimensions: ['campus' => 'west']),
    ]));
    $latest = [new StatementColumn('west', 'West current', $this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01', ['campus' => 'west'])];
    expect($this->ledger->reports->changesInEquity($mapping, $columns, 'retained')->rows)->toEqual($equity->rows)
        ->and($this->ledger->reports->changesInEquity($mapping, $latest, 'retained')->rows['total.closing']['values']['west']->decimal())->toBe('218.00')
        ->and($this->ledger->reports->comprehensiveIncome($mapping, $latest)->rows['total_comprehensive_income']['values']['west']->decimal())->toBe('18.00');
});

it('rejects incomplete equity classifications including prior opening balances', function (string $case, string $message) {
    ['mapping' => $mapping, 'capital' => $capital, 'reserve' => $reserve] = equityReportingFixture($this->ledger, $this->book);
    $account = $case === 'income' ? $this->income : ($case === 'oci' ? $reserve : $capital);
    $this->ledger->journals->post(new JournalRequest($this->book, 'unclassified-equity', '2026-01-01', 'Reviewed source', [
        new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)),
        new Posting($account, Side::Credit, Money::of('10', $this->kes), reportingMovement: match ($case) {
            'opening', 'income' => null, 'oci' => new ReportingMovement('oci', 'reviewed:1', 'Conflicting category', 'non_recyclable'),
            'mapping' => new ReportingMovement('contribution', 'reviewed:1', 'Share issue'), default => null,
        }),
    ]));
    $accounts = $mapping->accounts;
    if (in_array($case, ['opening', 'mapping', 'income'], true)) {
        unset($accounts[$case === 'income' ? '4000' : '3000']);
        $mapping = new ReportingMapping($mapping->name, $mapping->version, $mapping->mainBusinessActivity, $accounts, true, $mapping->equityComponents);
    }
    $columns = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-02-28'), $case === 'opening' ? '2026-02-01' : '2026-01-01')];
    expect(fn () => $this->ledger->reports->changesInEquity($mapping, $columns, 'retained'))->toThrow(LedgerException::class, $message);
})->with([
    ['unclassified', 'Unclassified reporting movement: 3000'],
    ['mapping', 'Equity reporting omits an active account: 3000'],
    ['opening', 'Equity reporting omits an active account: 3000'],
    ['income', 'Equity reporting omits an active account: 4000'],
    ['oci', 'Reporting mapping conflicts with source OCI classification or omits an active equity component: 3100'],
]);

it('validates equity statement columns and explicit presentation settings', function (string $case, string $message) {
    ['mapping' => $mapping] = equityReportingFixture($this->ledger, $this->book);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $column = new StatementColumn('current', 'Current', $snapshot, '2026-01-01');
    expect(fn () => match ($case) {
        'future' => $this->ledger->reports->changesInEquity($mapping, [new StatementColumn('current', 'Current', new Snapshot($this->book, '2026-01-31', '999999'), '2026-01-01')], 'retained'),
        'duplicate' => $this->ledger->reports->changesInEquity($mapping, [$column, $column], 'retained'),
        'cross_book' => $this->ledger->reports->changesInEquity($mapping, [$column, new StatementColumn('other', 'Other', new Snapshot((string) Str::uuid7(), '2026-01-31', '0'), '2026-01-01')], 'retained'),
        'component' => $mapping->definition(StatementKind::ChangesInEquity, 'unknown'),
        'name' => new ReportingMapping(str_repeat('n', 106), $mapping->version, $mapping->mainBusinessActivity, $mapping->accounts, true, $mapping->equityComponents),
        'incompatible' => $mapping->definition(StatementKind::ComprehensiveIncome, 'retained'),
        'reserved' => (new ReportingMapping($mapping->name, $mapping->version, $mapping->mainBusinessActivity, $mapping->accounts, true, $mapping->equityComponents + ['total' => 'Reserved']))->definition(StatementKind::ChangesInEquity, 'retained'),
        default => throw new RuntimeException('Unknown equity validation case.'),
    })->toThrow(LedgerException::class, $message);
})->with([
    ['future', 'A snapshot cannot use a future knowledge cutoff.'],
    ['duplicate', 'Statement columns require the same book and unique keys.'],
    ['cross_book', 'Statement columns require the same book and unique keys.'],
    ['component', 'Equity reporting requires an explicit earnings component.'],
    ['name', 'A reporting mapping name must leave room for its derived statement names.'],
    ['incompatible', 'Only an equity statement specifies an earnings component.'],
    ['reserved', 'The equity component key total is reserved for statement totals.'],
]);

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

it('classifies profit and OCI by an explicit versioned business policy without changing cash flow', function () {
    $interest = $this->ledger->catalog->account($this->book, '4100', 'Interest income', AccountType::Income, cashFlow: 'investing');
    $cost = $this->ledger->catalog->account($this->book, '5000', 'Operating costs', AccountType::Expense);
    $borrowing = $this->ledger->catalog->account($this->book, '5100', 'Borrowing costs', AccountType::Expense);
    $tax = $this->ledger->catalog->account($this->book, '5200', 'Income tax', AccountType::Expense);
    $discontinued = $this->ledger->catalog->account($this->book, '4200', 'Discontinued operation', AccountType::Income);
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Revaluation reserve', AccountType::Equity);
    $translation = $this->ledger->catalog->account($this->book, '3200', 'Translation reserve', AccountType::Equity);
    foreach ([[$this->income, Side::Credit, '1000'], [$interest, Side::Credit, '100'], [$cost, Side::Debit, '300'],
        [$borrowing, Side::Debit, '50'], [$tax, Side::Debit, '150'], [$discontinued, Side::Credit, '20'],
        [$reserve, Side::Credit, '60'], [$translation, Side::Credit, '10']] as $index => [$account, $side, $value]) {
        $amount = Money::of($value, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, 'classified-'.$index, '2026-01-15', 'Reporting fixture', [
            new Posting($account, $side, $amount, reportingMovement: in_array($account, [$reserve, $translation], true)
                ? new ReportingMovement('oci', 'fixture:1', 'classified-'.$index, $account === $reserve ? 'non_recyclable' : 'recyclable') : null), new Posting($this->cash, $side->opposite(), $amount),
        ]));
    }
    $accounts = ['4100' => ['profit_or_loss' => 'investing'], '4000' => ['profit_or_loss' => 'operating'],
        '5000' => ['profit_or_loss' => 'operating'], '5100' => ['profit_or_loss' => 'financing'],
        '5200' => ['profit_or_loss' => 'income_taxes'], '4200' => ['profit_or_loss' => 'discontinued_operations'],
        '3100' => ['oci' => 'non_recyclable', 'equity_component' => 'revaluation'], '3200' => ['oci' => 'recyclable', 'equity_component' => 'translation']];
    $components = ['revaluation' => 'Revaluation reserve', 'translation' => 'Translation reserve'];
    $mapping = new ReportingMapping('Classified performance', '1', 'Trading entity; reviewed fixture policy', $accounts, true, $components);
    $configuration = $this->ledger->configurations->install($this->book, new Pack('reporting', '1', reportingMapping: $mapping));
    $columns = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')];
    $definition = $this->ledger->configurations->statement($this->book, $configuration, $mapping->name);
    expect(Canonical::hash($definition))->toBe(Canonical::hash($mapping->definition()));
    $report = $this->ledger->reports->statement($definition, $columns);
    foreach (['operating_profit' => '700.00', 'profit_before_financing_and_income_taxes' => '800.00',
        'profit_before_income_taxes' => '750.00', 'profit_from_continuing_operations' => '600.00', 'profit' => '620.00',
        'oci_non_recyclable' => '60.00', 'oci_recyclable' => '10.00'] as $key => $value) {
        expect($report->rows[$key]['values']['current']->decimal())->toBe($value);
    }
    $saved = $this->ledger->reports->saveConfiguredStatement($this->book, 'classified-report', $configuration, $mapping->name, $columns);
    $before = $this->ledger->reports->statementEvidence($this->book, $saved);
    expect($before['result']->definition->reportingMapping?->mainBusinessActivity)->toBe($mapping->mainBusinessActivity)
        ->and($before['result']->configuration['id'] ?? null)->toBe($configuration)
        ->and($before['result_hash'])->toBe(hash('sha256', $before['result_json']));
    $accounts['4100']['profit_or_loss'] = 'operating';
    $accounts['5100']['profit_or_loss'] = 'operating';
    $financing = new ReportingMapping($mapping->name, '2', 'Providing financing to customers; reviewed fixture policy', $accounts, false, $components);
    $revised = $this->ledger->configurations->install($this->book, new Pack('reporting', '2', reportingMapping: $financing));
    $newReport = $this->ledger->reports->statement($this->ledger->configurations->statement($this->book, $revised, $mapping->name), $columns);
    expect($newReport->rows['operating_profit']['values']['current']->decimal())->toBe('750.00')
        ->and($newReport->rows['profit']['values']['current']->decimal())->toBe('620.00')
        ->and(array_key_exists('profit_before_financing_and_income_taxes', $newReport->rows))->toBeFalse();
    $this->storage->run(Permission::Read, function () use ($interest): void {
        expect(Row::text($this->storage->find('ledger_accounts', $interest, $this->book), 'cash_flow'))->toBe('investing');
    }, $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'later-classified', '2026-01-20', 'Later revenue', [
        new Posting($this->cash, Side::Debit, Money::of('25', $this->kes)), new Posting($this->income, Side::Credit, Money::of('25', $this->kes)),
    ]));
    expect($this->ledger->reports->saveConfiguredStatement($this->book, 'classified-report', $configuration, $mapping->name, $columns))->toBe($saved)
        ->and($this->ledger->reports->statementEvidence($this->book, $saved))->toEqual($before);
    expect(fn () => $this->ledger->configurations->install($this->book, new Pack('reporting', '1', reportingMapping: $financing)))->toThrow(LedgerException::class, 'different content');
    expect(fn () => $this->ledger->reports->saveConfiguredStatement($this->book, 'classified-report', $revised, $mapping->name, $columns))->toThrow(LedgerException::class, 'different inputs');
});

it('reconciles reviewed performance measures with explicit per-adjustment tax and NCI effects', function () {
    $cost = $this->ledger->catalog->account($this->book, '5000', 'Exceptional operating costs', AccountType::Expense);
    foreach (['west' => ['100', '30'], 'east' => ['200', '40']] as $campus => [$revenue, $expense]) {
        $this->ledger->catalog->dimensionValue($this->entity, 'campus', $campus);
        $this->ledger->journals->post(new JournalRequest($this->book, 'mpm-'.$campus, '2026-01-15', 'Performance fixture', [
            new Posting($this->cash, Side::Debit, Money::of($revenue, $this->kes), dimensions: ['campus' => $campus]),
            new Posting($this->income, Side::Credit, Money::of($revenue, $this->kes), dimensions: ['campus' => $campus]),
            new Posting($cost, Side::Debit, Money::of($expense, $this->kes), dimensions: ['campus' => $campus]),
            new Posting($this->cash, Side::Credit, Money::of($expense, $this->kes), dimensions: ['campus' => $campus]),
        ]));
    }
    $mapping = new ReportingMapping('Reviewed performance', '1', 'Trading', ['4000' => ['profit_or_loss' => 'operating'], '5000' => ['profit_or_loss' => 'operating']], true, performanceMeasures: [
        'adjusted' => ['label' => 'Adjusted operating profit', 'subtotal' => 'operating_profit', 'review_reference' => 'review:fixture:1',
            'adjustments' => ['exceptional' => ['label' => 'Exceptional costs added back', 'accounts' => ['5000'], 'coefficient' => '-1']]],
    ]);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $columns = [new StatementColumn('west', 'West', $snapshot, '2026-01-01', ['campus' => 'west']), new StatementColumn('east', 'East', $snapshot, '2026-01-01', ['campus' => 'east'])];
    $effects = ['west' => ['adjusted' => ['exceptional' => ['tax' => Money::of('-9', $this->kes), 'nci' => Money::of('-2', $this->kes)]]],
        'east' => ['adjusted' => ['exceptional' => ['tax' => Money::of('-12', $this->kes), 'nci' => Money::of('0', $this->kes)]]]];
    $configuration = $this->ledger->configurations->install($this->book, new Pack('performance', '1', reportingMapping: $mapping));
    $definition = $mapping->definition();
    expect(fn () => $this->ledger->reports->statement($definition, $columns))->toThrow(LedgerException::class, 'explicit performance measure effects');
    $report = $this->ledger->reports->statement($definition, $columns, $effects);
    expect($report->rows['operating_profit']['values']['west']->decimal())->toBe('70.00')
        ->and($report->rows['mpm.adjusted.exceptional']['values']['west']->decimal())->toBe('30.00')
        ->and($report->rows['mpm.adjusted']['values']['west']->decimal())->toBe('100.00')
        ->and($report->rows['mpm.adjusted']['values']['east']->decimal())->toBe('200.00');
    $id = $this->ledger->reports->saveConfiguredStatement($this->book, 'reviewed-mpm', $configuration, $mapping->name, $columns, $effects);
    $saved = $this->ledger->reports->statementEvidence($this->book, $id);
    expect($saved['result']->performanceMeasureEffects['west']['adjusted']['exceptional']['tax']->decimal())->toBe('-9.00')
        ->and($saved['result']->performanceMeasureEffects['east']['adjusted']['exceptional']['nci']->decimal())->toBe('0.00')
        ->and($saved['result']->definition->reportingMapping?->performanceMeasures['adjusted']['review_reference'])->toBe('review:fixture:1');
    expect($this->ledger->reports->saveConfiguredStatement($this->book, 'reviewed-mpm', $configuration, $mapping->name, $columns, $effects))->toBe($id);
    $effects['west']['adjusted']['exceptional']['tax'] = Money::of('-8', $this->kes);
    expect(fn () => $this->ledger->reports->saveConfiguredStatement($this->book, 'reviewed-mpm', $configuration, $mapping->name, $columns, $effects))->toThrow(LedgerException::class, 'different inputs');
    $invalid = json_decode(Canonical::json($report), true, 512, JSON_THROW_ON_ERROR);
    expect(is_array($invalid))->toBeTrue();
    if (! is_array($invalid)) {
        throw new RuntimeException('Performance fixture must remain an object.');
    }
    data_forget($invalid, 'performance_measure_effects.west.adjusted.exceptional.nci');
    if (! is_array($invalid)) {
        throw new RuntimeException('Performance fixture must remain an object.');
    }
    expect(fn () => Statement::fromArray($invalid))->toThrow(LedgerException::class);
});

it('rejects unmapped earnings and altered reporting formulas', function () {
    $this->ledger->journals->post(new JournalRequest($this->book, 'unmapped-reporting', '2026-01-01', 'Unmapped revenue', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Reserve', AccountType::Equity);
    $mapping = new ReportingMapping('Incomplete classification', '1', 'Trading', ['3100' => ['equity_component' => 'reserve']], true, ['reserve' => 'Reserve']);
    $columns = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')];
    expect(fn () => $this->ledger->reports->statement($mapping->definition(), $columns))->toThrow(LedgerException::class, 'omits an active account: 4000');
    $definition = $mapping->definition();
    $rows = $definition->rows;
    $rows['profit'] = ['label' => 'Altered profit', 'terms' => ['oci_recyclable' => '1']];
    expect(fn () => $this->ledger->reports->statement(new StatementDefinition($definition->name, $definition->version, $rows, $definition->coverageTypes, $mapping), $columns))->toThrow(LedgerException::class, 'differs from its mapping');
});

it('rejects malformed reporting policies before rendering or installing them', function (string $case) {
    $data = ['name' => 'Performance', 'version' => '1', 'main_business_activity' => 'Trading',
        'accounts' => ['4000' => ['profit_or_loss' => 'operating']], 'financing_subtotal_required' => true, 'equity_components' => [], 'performance_measures' => []];
    match ($case) {
        'missing_activity' => data_forget($data, 'main_business_activity'),
        'missing_subtotal_policy' => data_forget($data, 'financing_subtotal_required'),
        'unknown_field' => data_set($data, 'arbitrary', 'value'),
        'missing_accounts' => data_forget($data, 'accounts'),
        'empty_accounts' => data_set($data, 'accounts', []),
        'invalid_account' => data_set($data, 'accounts.4000', false),
        'invalid_classification' => data_set($data, 'accounts.4000.profit_or_loss', 'cash'),
        'dual_classification' => data_set($data, 'accounts.4000.oci', 'recyclable'),
        'invalid_oci' => data_set($data, 'accounts.4000', ['oci' => 'never']),
        'invalid_component' => data_set($data, 'accounts.4000', ['equity_component' => 'missing']),
        'invalid_measures' => data_set($data, 'performance_measures', false),
        'invalid_measure' => data_set($data, 'performance_measures.adjusted', false),
        'unknown_measure_field' => data_set($data, 'performance_measures.adjusted', ['arbitrary' => 'value']),
        'invalid_adjustments' => data_set($data, 'performance_measures.adjusted', ['adjustments' => false]),
        'invalid_adjustment' => data_set($data, 'performance_measures.adjusted', ['adjustments' => ['cost' => false]]),
        'unknown_adjustment_field' => data_set($data, 'performance_measures.adjusted', ['adjustments' => ['cost' => ['arbitrary' => 'value']]]),
        'invalid_selectors' => data_set($data, 'performance_measures.adjusted', ['adjustments' => ['cost' => ['accounts' => []]]]),
        'invalid_selector' => data_set($data, 'performance_measures.adjusted', ['adjustments' => ['cost' => ['accounts' => [false]]]]),
        default => throw new RuntimeException('Unknown reporting fixture.'),
    };
    if (! is_array($data)) {
        throw new RuntimeException('Reporting fixture must remain an object.');
    }
    ReportingMapping::fromArray($data);
})->with(['missing_activity', 'missing_subtotal_policy', 'unknown_field', 'missing_accounts', 'empty_accounts', 'invalid_account', 'invalid_classification', 'dual_classification', 'invalid_oci', 'invalid_component', 'invalid_measures', 'invalid_measure', 'unknown_measure_field', 'invalid_adjustments', 'invalid_adjustment', 'unknown_adjustment_field', 'invalid_selectors', 'invalid_selector'])->throws(LedgerException::class);

it('reconciles performance measures to the paragraph 118 subtotals the mapping presents', function (string $subtotal, bool $financing, bool $accepted) {
    $measures = ['adjusted' => ['label' => 'Adjusted', 'subtotal' => $subtotal, 'review_reference' => 'approved:1',
        'adjustments' => ['cost' => ['label' => 'Cost', 'accounts' => ['5000'], 'coefficient' => '-1']]]];
    $build = fn (): ReportingMapping => new ReportingMapping('Performance', '1', 'Trading', ['4000' => ['profit_or_loss' => 'operating'], '5000' => ['profit_or_loss' => 'operating']], $financing, performanceMeasures: $measures);
    if (! $accepted) {
        expect($build)->toThrow(LedgerException::class, 'supported IFRS subtotal');

        return;
    }
    expect($build()->definition()->rows['mpm.adjusted']['terms'] ?? [])->toBe([$subtotal => '1', 'mpm.adjusted.cost' => '1']);
})->with([
    'profit before income taxes' => ['profit_before_income_taxes', true, true],
    'profit from continuing operations' => ['profit_from_continuing_operations', false, true],
    'financing subtotal when presented' => ['profit_before_financing_and_income_taxes', true, true],
    'financing subtotal when omitted' => ['profit_before_financing_and_income_taxes', false, false],
    'unpresented gross profit' => ['gross_profit', true, false],
]);

it('keeps OCI carried in dedicated income accounts outside classified profit', function () {
    $oci = $this->ledger->catalog->account($this->book, '4300', 'Dedicated OCI income', AccountType::Income);
    $this->ledger->journals->post(new JournalRequest($this->book, 'oci-income', '2026-01-15', 'Dedicated OCI', [
        new Posting($this->cash, Side::Debit, Money::of('60', $this->kes)), new Posting($oci, Side::Credit, Money::of('60', $this->kes),
            reportingMovement: new ReportingMovement('oci', 'fixture:1', 'dedicated OCI', 'non_recyclable')),
    ]));
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'recyclable-oci-income', '2026-01-15', 'Unrecyclable source', [
        new Posting($this->cash, Side::Debit, Money::of('1', $this->kes)), new Posting($oci, Side::Credit, Money::of('1', $this->kes),
            reportingMovement: new ReportingMovement('oci', 'fixture:1', 'income recyclable OCI', 'recyclable')),
    ])))->toThrow(QueryException::class, 'Recyclable OCI requires an equity reserve account');
    $recyclable = new ReportingMapping('OCI classification', '1', 'Trading', ['4000' => ['profit_or_loss' => 'operating'], '4300' => ['oci' => 'recyclable']], true);
    $mapping = new ReportingMapping('OCI classification', '1', 'Trading', ['4000' => ['profit_or_loss' => 'operating'], '4300' => ['oci' => 'non_recyclable']], true);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $columns = [new StatementColumn('current', 'Current', $before, '2026-01-01')];
    $report = $this->ledger->reports->statement($mapping->definition(), $columns);
    expect($report->rows['profit']['values']['current']->decimal())->toBe('0.00')
        ->and($report->rows['oci_non_recyclable']['values']['current']->decimal())->toBe('60.00')
        ->and(fn () => $this->ledger->reports->statement($recyclable->definition(), $columns))->toThrow(LedgerException::class, 'Recyclable OCI requires an equity reserve account: 4300');
    $this->ledger->yearEnd->close($this->book, 'oci-close-fixture', '2026-01-01', '2026-12-31', $retained, 'Closing fixture');
    $after = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')];
    $closed = $this->ledger->reports->statement($mapping->definition(), $after);
    expect($closed->rows)->toEqual($report->rows);
});

it('separates OCI and direct equity movements within one reserve using immutable source evidence', function () {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Mixed-purpose revaluation reserve', AccountType::Equity);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $capital = $this->ledger->catalog->account($this->book, '3200', 'Share capital', AccountType::Equity);
    foreach ([['opening', $retained, 'opening_balance', '1000', Side::Credit], ['revaluation', $reserve, 'oci', '60', Side::Credit],
        ['share-issue', $capital, 'contribution', '100', Side::Credit], ['dividend', $retained, 'distribution', '50', Side::Debit]] as [$key, $account, $kind, $value, $side]) {
        $amount = Money::of($value, $this->kes);
        $this->ledger->journals->post(new JournalRequest($this->book, $key, '2026-01-01', $key, [
            new Posting($account, $side, $amount, reportingMovement: new ReportingMovement($kind, 'reviewed:1', $key, $kind === 'oci' ? 'non_recyclable' : null)),
            new Posting($this->cash, $side->opposite(), $amount),
        ]));
    }
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-01-02', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('200', $this->kes)), new Posting($this->income, Side::Credit, Money::of('200', $this->kes)),
    ]));
    $transfer = new ReportingMovement('transfer', 'reviewed:1', 'Realised reserve transfer');
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'reserve-transfer', '2026-01-03', 'Transfer within equity', [
        new Posting($reserve, Side::Debit, Money::of('20', $this->kes), reportingMovement: $transfer),
        new Posting($retained, Side::Credit, Money::of('20', $this->kes), reportingMovement: $transfer),
    ]));
    $mapping = new ReportingMapping('Mixed reserve performance', '1', 'Trading', ['4000' => ['profit_or_loss' => 'operating'],
        '3100' => ['oci' => 'non_recyclable', 'equity_component' => 'revaluation'], '3000' => ['equity_component' => 'retained'], '3200' => ['equity_component' => 'capital']],
        true, ['revaluation' => 'Revaluation', 'retained' => 'Retained earnings', 'capital' => 'Share capital']);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $columns = [new StatementColumn('current', 'Current', $snapshot, '2026-01-01')];
    $report = $this->ledger->reports->statement($mapping->definition(), $columns);
    expect($report->rows['profit']['values']['current']->decimal())->toBe('200.00')
        ->and($report->rows['oci_non_recyclable']['values']['current']->decimal())->toBe('60.00');
    $movements = $this->ledger->reports->reportingMovements($snapshot, $mapping, '2026-01-01');
    expect(array_column($movements, 'component'))->toBe(['retained', 'revaluation', 'capital', 'retained', 'revaluation', 'retained'])
        ->and(array_map(fn ($row): string => $row['movement']->kind, $movements))->toBe(['opening_balance', 'oci', 'contribution', 'distribution', 'transfer', 'transfer']);
    $saved = $this->ledger->reports->saveStatement($this->book, 'mixed-reserve', $mapping->definition(), $columns);
    $before = $this->ledger->reports->statementEvidence($this->book, $saved);
    $this->ledger->journals->reverse($this->book, $journal->id, 'undo-transfer', '2026-01-04', 'Correct transfer');
    expect($this->ledger->reports->statementEvidence($this->book, $saved))->toEqual($before)
        ->and($this->ledger->journals->lines($this->book, $journal->id)[0]->reportingMovement)->toEqual($transfer);
    $this->ledger->yearEnd->close($this->book, 'mixed-close', '2026-01-01', '2026-12-31', $retained, 'Close earnings');
    $closed = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')]);
    expect($closed->rows)->toEqual($report->rows);
});

it('recycles original OCI gains and losses partially and corrects them without rewriting saved reports', function (bool $gain) {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Recyclable reserve', AccountType::Equity);
    $this->ledger->catalog->dimensionValue($this->entity, 'fund', 'west');
    $oci = new ReportingMovement('oci', 'reviewed:1', 'Original gain', 'recyclable');
    $original = $this->ledger->journals->post(new JournalRequest($this->book, 'original-oci', '2026-01-01', 'Original gain', [
        new Posting($reserve, $gain ? Side::Credit : Side::Debit, Money::of('100', $this->kes), dimensions: ['fund' => 'west'], reportingMovement: $oci),
        new Posting($this->cash, $gain ? Side::Debit : Side::Credit, Money::of('100', $this->kes), dimensions: ['fund' => 'west']),
    ]));
    $mapping = new ReportingMapping('Recycling performance', '1', 'Trading', ['4000' => ['profit_or_loss' => 'investing'],
        '3100' => ['oci' => 'recyclable', 'equity_component' => 'reserve']], true, ['reserve' => 'Reserve', 'retained' => 'Retained earnings']);
    $recycled = $this->ledger->journals->recycleOci($this->book, 'recycle-40', $original->id, 1, '2026-01-02', Money::of('40', $this->kes), $this->income, 'reviewed:1', 'Reviewed disposal');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $columns = [new StatementColumn('current', 'Current', $snapshot, '2026-01-01', ['fund' => 'west'])];
    $report = $this->ledger->reports->statement($mapping->definition(), $columns);
    expect($report->rows['profit']['values']['current']->decimal())->toBe($gain ? '40.00' : '-40.00')
        ->and($report->rows['oci_recyclable']['values']['current']->decimal())->toBe($gain ? '60.00' : '-60.00')
        ->and($this->ledger->journals->lines($this->book, $recycled->id)[0]->reportingMovement?->sourceJournalId)->toBe($original->id);
    $equity = $this->ledger->reports->changesInEquity($mapping, $columns, 'retained');
    expect($equity->rows['total.comprehensive_income']['values']['current']->decimal())->toBe($gain ? '100.00' : '-100.00')
        ->and($equity->rows['total.closing']['values']['current']->decimal())->toBe($gain ? '100.00' : '-100.00')
        ->and($this->ledger->reports->comprehensiveIncome($mapping, $columns)->rows['total_comprehensive_income']['values']['current']->decimal())->toBe($gain ? '100.00' : '-100.00');
    $saved = $this->ledger->reports->saveStatement($this->book, 'recycling-report', $mapping->definition(), $columns);
    $before = $this->ledger->reports->statementEvidence($this->book, $saved);
    $cutoff = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->ledger->journals->recycleOci($this->book, 'too-much', $original->id, 1, '2026-01-02', Money::of('61', $this->kes), $this->income, 'reviewed:1', 'Excess recycling'))->toThrow(QueryException::class, 'exceeds the original OCI amount');
    expect(fn () => $this->ledger->journals->reverse($this->book, $original->id, 'too-early', '2026-01-03', 'Wrong correction order'))->toThrow(QueryException::class, 'Reverse dependent recycling');
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    $reversal = $this->ledger->journals->reverse($this->book, $recycled->id, 'undo-recycling', '2026-01-03', 'Correct recycling');
    $restored = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')]);
    expect($restored->rows['profit']['values']['current']->decimal())->toBe('0.00')
        ->and($restored->rows['oci_recyclable']['values']['current']->decimal())->toBe($gain ? '100.00' : '-100.00')
        ->and($this->ledger->journals->lines($this->book, $reversal->id)[0]->reportingMovement)->toEqual($this->ledger->journals->lines($this->book, $recycled->id)[0]->reportingMovement);
    $cancelled = $this->ledger->journals->reverse($this->book, $original->id, 'undo-original-oci', '2026-01-04', 'Correct original gain');
    expect(fn () => $this->ledger->journals->recycleOci($this->book, 'recycle-cancelled', $cancelled->id, 1, '2026-01-05', Money::of('100', $this->kes), $this->income, 'reviewed:1', 'Recycle a correction'))
        ->toThrow(QueryException::class, 'release its live original reserve');
    $corrected = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')]);
    expect($corrected->rows['profit']['values']['current']->decimal())->toBe('0.00')
        ->and($corrected->rows['oci_recyclable']['values']['current']->decimal())->toBe('0.00');
    expect($this->ledger->reports->statementEvidence($this->book, $saved))->toEqual($before)
        ->and($this->ledger->journals->recycleOci($this->book, 'recycle-40', $original->id, 1, '2026-01-02', Money::of('40', $this->kes), $this->income, 'reviewed:1', 'Reviewed disposal')->id)->toBe($recycled->id);
})->with([false, true]);

it('keeps reviewed basis adjustments separate from OCI recycling and conserves their postings', function () {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Cash flow hedge reserve', AccountType::Equity);
    $asset = $this->ledger->catalog->account($this->book, '1400', 'Non-financial asset', AccountType::Asset);
    $derivative = $this->ledger->catalog->account($this->book, '1300', 'Derivative asset', AccountType::Asset);
    $payable = $this->ledger->catalog->account($this->book, '2100', 'Supplier payable', AccountType::Liability);
    $original = $this->ledger->journals->post(new JournalRequest($this->book, 'hedge-oci', '2026-01-01', 'OCI gain', [
        new Posting($reserve, Side::Credit, Money::of('100', $this->kes), reportingMovement: new ReportingMovement('oci', 'ifrs9:reviewed:1', 'Reviewed hedge gain', 'recyclable')),
        new Posting($derivative, Side::Debit, Money::of('100', $this->kes)),
    ]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'hedged-purchase', '2026-01-02', 'Purchase non-financial asset', [
        new Posting($asset, Side::Debit, Money::of('100', $this->kes)), new Posting($payable, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $movement = new ReportingMovement('basis_adjustment', 'ifrs9:reviewed:1', 'Reviewed hedge basis adjustment', 'recyclable', $original->id, 1);
    $basis = $this->ledger->journals->post(new JournalRequest($this->book, 'basis', '2026-01-02', 'Reviewed basis adjustment', [
        new Posting($reserve, Side::Debit, Money::of('30', $this->kes), reportingMovement: $movement),
        new Posting($asset, Side::Credit, Money::of('30', $this->kes), reportingMovement: $movement),
    ]));
    $mapping = new ReportingMapping('Hedge reporting', '1', 'Trading', ['3100' => ['oci' => 'recyclable', 'equity_component' => 'hedge']], true, ['hedge' => 'Hedge reserve', 'retained' => 'Retained earnings']);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $report = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $snapshot, '2026-01-01')]);
    expect($report->rows['profit']['values']['current']->decimal())->toBe('0.00')
        ->and($report->rows['oci_recyclable']['values']['current']->decimal())->toBe('100.00');
    $equity = $this->ledger->reports->changesInEquity($mapping, [new StatementColumn('current', 'Current', $snapshot, '2026-01-01')], 'retained');
    expect($equity->rows['total.comprehensive_income']['values']['current']->decimal())->toBe('100.00')
        ->and($equity->rows['total.basis_adjustments']['values']['current']->decimal())->toBe('-30.00')
        ->and($equity->rows['total.closing']['values']['current']->decimal())->toBe('70.00');
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'bad-basis', '2026-01-02', 'Unpaired basis adjustment', [
        new Posting($reserve, Side::Debit, Money::of('10', $this->kes), reportingMovement: $movement), new Posting($asset, Side::Credit, Money::of('10', $this->kes)),
    ])))->toThrow(QueryException::class, 'Basis adjustments must conserve');
    expect(fn () => $this->ledger->journals->recycleOci($this->book, 'overdraw-after-basis', $original->id, 1, '2026-01-02', Money::of('71', $this->kes), $this->income, 'ifrs9:reviewed:1', 'Excess source usage'))
        ->toThrow(QueryException::class, 'exceeds the original OCI amount');
    expect(fn () => $this->ledger->journals->reverse($this->book, $original->id, 'early-basis-source-correction', '2026-01-03', 'Incorrect correction order'))
        ->toThrow(QueryException::class, 'Reverse dependent recycling');
    $this->ledger->journals->reverse($this->book, $basis->id, 'undo-basis', '2026-01-03', 'Correct basis adjustment');
    $this->ledger->journals->reverse($this->book, $original->id, 'undo-hedge-source', '2026-01-04', 'Correct hedge OCI');
    $corrected = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')]);
    expect($corrected->rows['oci_recyclable']['values']['current']->decimal())->toBe('0.00');
});

it('rejects backdated recycling that would overdraw an earlier cutoff before a later release', function () {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'OCI reserve', AccountType::Equity);
    $source = $this->ledger->journals->post(new JournalRequest($this->book, 'chronological-oci', '2026-01-01', 'OCI gain', [
        new Posting($reserve, Side::Credit, Money::of('100', $this->kes), reportingMovement: new ReportingMovement('oci', 'reviewed:1', 'Original gain', 'recyclable')),
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)),
    ]));
    $used = $this->ledger->journals->recycleOci($this->book, 'january-recycling', $source->id, 1, '2026-01-02', Money::of('40', $this->kes), $this->income, 'reviewed:1', 'Reviewed disposal');
    $this->ledger->journals->reverse($this->book, $used->id, 'february-release', '2026-02-02', 'Correct disposal');
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->ledger->journals->recycleOci($this->book, 'backdated-recycling', $source->id, 1, '2026-01-03', Money::of('100', $this->kes), $this->income, 'reviewed:1', 'Backdated disposal'))
        ->toThrow(QueryException::class, 'Recycling dates must follow original OCI usage history');
    expect(fn () => $this->ledger->journals->reverse($this->book, $source->id, 'backdated-source-reversal', '2026-01-04', 'Backdated correction'))
        ->toThrow(QueryException::class, 'OCI corrections must follow their recycling history');
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('recycles reviewed opening OCI without recognizing its opening balance as current OCI', function () {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Opening OCI reserve', AccountType::Equity);
    $source = $this->ledger->journals->post(new JournalRequest($this->book, 'opening-oci-lot', '2026-01-01', 'Reviewed opening balance', [
        new Posting($reserve, Side::Credit, Money::of('100', $this->kes), reportingMovement: new ReportingMovement('oci_opening', 'opening-policy:1', 'Reconciled historical reserve', 'recyclable')),
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)),
    ]));
    $mapping = new ReportingMapping('Opening OCI presentation', '1', 'Trading', ['4000' => ['profit_or_loss' => 'investing'],
        '3100' => ['oci' => 'recyclable', 'equity_component' => 'reserve']], true, ['reserve' => 'Reserve', 'retained' => 'Retained earnings']);
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $opening = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $before, '2026-01-01')]);
    expect($opening->rows['profit']['values']['current']->decimal())->toBe('0.00')
        ->and($opening->rows['oci_recyclable']['values']['current']->decimal())->toBe('0.00');
    $equity = $this->ledger->reports->changesInEquity($mapping, [new StatementColumn('current', 'Current', $before, '2026-01-01')], 'retained');
    expect($equity->rows['total.opening']['values']['current']->decimal())->toBe('100.00')
        ->and($equity->rows['total.comprehensive_income']['values']['current']->decimal())->toBe('0.00')
        ->and($equity->rows['total.closing']['values']['current']->decimal())->toBe('100.00');
    $this->ledger->journals->recycleOci($this->book, 'opening-oci-disposal', $source->id, 1, '2026-02-01', Money::of('40', $this->kes), $this->income, 'opening-policy:1', 'Reviewed disposal of historical reserve');
    $report = $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-02-01')]);
    expect($report->rows['profit']['values']['current']->decimal())->toBe('40.00')
        ->and($report->rows['oci_recyclable']['values']['current']->decimal())->toBe('-40.00')
        ->and($this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $before, '2026-01-01')])->rows)->toEqual($opening->rows);
});

it('preserves numeric chart codes through configuration JSON and source classification', function () {
    $reserve = $this->ledger->catalog->account($this->book, '0', 'Numeric OCI reserve', AccountType::Equity);
    $income = $this->ledger->catalog->account($this->book, '1', 'Numeric revenue', AccountType::Income);
    $mapping = new ReportingMapping('Numeric chart', '1', 'Trading', ['0' => ['oci' => 'recyclable', 'equity_component' => 'reserve'], '1' => ['profit_or_loss' => 'operating']], true, ['reserve' => 'Reserve']);
    $configuration = $this->ledger->configurations->install($this->book, new Pack('numeric-reporting', '1', reportingMapping: $mapping));
    $this->ledger->journals->post(new JournalRequest($this->book, 'numeric-oci', '2026-01-01', 'OCI gain', [
        new Posting($reserve, Side::Credit, Money::of('10', $this->kes), reportingMovement: new ReportingMovement('oci', 'reviewed:1', 'Numeric chart gain', 'recyclable')),
        new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)),
    ]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'numeric-profit', '2026-01-01', 'Revenue', [
        new Posting($income, Side::Credit, Money::of('20', $this->kes)), new Posting($this->cash, Side::Debit, Money::of('20', $this->kes)),
    ]));
    $columns = [new StatementColumn('current', 'Current', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')];
    $id = $this->ledger->reports->saveConfiguredStatement($this->book, 'numeric-chart-report', $configuration, $mapping->name, $columns);
    $result = $this->ledger->reports->statementEvidence($this->book, $id)['result'];
    expect($result->rows['profit']['values']['current']->decimal())->toBe('20.00')
        ->and($result->rows['oci_recyclable']['values']['current']->decimal())->toBe('10.00')
        ->and($result->definition->reportingMapping?->accounts)->toBe($mapping->accounts);
});

it('fails on unclassified equity and on reporting policy conflicts with source OCI evidence', function (bool $classified) {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'Reserve', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->book, 'source-classification', '2026-01-01', 'Reserve movement', [
        new Posting($reserve, Side::Credit, Money::of('10', $this->kes), reportingMovement: $classified ? new ReportingMovement('oci', 'reviewed:1', 'Non-recyclable gain', 'non_recyclable') : null),
        new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)),
    ]));
    $mapping = new ReportingMapping('Conflicting reporting', '1', 'Trading', ['3100' => ['oci' => 'recyclable', 'equity_component' => 'reserve']], true, ['reserve' => 'Reserve']);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect(fn () => $this->ledger->reports->statement($mapping->definition(), [new StatementColumn('current', 'Current', $snapshot, '2026-01-01')]))
        ->toThrow(LedgerException::class, $classified ? 'conflicts with source OCI classification' : 'Unclassified reporting movement');
})->with([false, true]);

it('rejects forged source recycling evidence through direct SQL without consuming a sequence', function (string $case) {
    $reserve = $this->ledger->catalog->account($this->book, '3100', 'OCI reserve', AccountType::Equity);
    $this->ledger->catalog->dimensionValue($this->entity, 'fund', 'west');
    $this->ledger->catalog->dimensionValue($this->entity, 'fund', 'east');
    $source = $this->ledger->journals->post(new JournalRequest($this->book, 'oci-source', '2026-01-01', 'Reviewed source', [
        new Posting($reserve, Side::Credit, Money::of('100', $this->kes), dimensions: ['fund' => 'west'], reportingMovement: new ReportingMovement('oci', 'reviewed:1', 'Original gain', 'recyclable')),
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes), dimensions: ['fund' => 'west']),
    ]));
    $recycling = $this->ledger->journals->recycleOci($this->book, 'valid-recycling', $source->id, 1, '2026-01-02', Money::of('40', $this->kes), $this->income, 'reviewed:1', 'Reviewed disposal');
    $before = $this->ledger->events->cutoff($this->book);
    $message = match ($case) {
        'missing_pair' => 'Recycling requires an equal reserve and profit-or-loss pair',
        'dimensions' => 'same currency and dimensions',
        'amount' => 'Recycling exceeds the original OCI amount',
        'source' => 'Recycling source must be a recyclable OCI posting in the same book',
        'category' => 'Recycling requires a recyclable original OCI posting',
        default => 'Invalid reporting movement evidence',
    };
    expect(fn () => $this->storage->run(Permission::Post, function () use ($case, $recycling): void {
        $original = $this->storage->find('ledger_journals', $recycling->id, $this->book);
        $lines = Row::json($original, 'postings');
        match ($case) {
            'missing_pair' => data_forget($lines, '1.reporting_movement'),
            'dimensions' => data_set($lines, '0.dimensions.fund', 'east'),
            'amount' => array_map(function (int $index) use (&$lines): void {
                data_set($lines, $index.'.amount_minor', '7000');
                data_set($lines, $index.'.functional_minor', '7000');
            }, [0, 1]),
            'source' => data_set($lines, '0.reporting_movement.source_journal_id', (string) Str::uuid7()),
            'category' => data_set($lines, '0.reporting_movement.oci_category', 'non_recyclable'),
            'malformed' => data_set($lines, '0.reporting_movement', false),
            'policy' => data_set($lines, '0.reporting_movement.policy_version', ''),
            default => throw new RuntimeException('Unknown recycling SQL fixture.'),
        };
        $this->storage->insert('ledger_journals', ['book_id' => $this->book, 'idempotency_key' => 'forged-recycling', 'fingerprint' => hash('sha256', 'forged-recycling'),
            'effective_on' => '2026-01-02', 'description' => 'Forged recycling', 'postings' => json_encode($lines, JSON_THROW_ON_ERROR), 'metadata' => '{}', 'policy_versions' => '[]']);
    }, $this->book))->toThrow(QueryException::class, $message);
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
})->with(['missing_pair', 'dimensions', 'amount', 'source', 'category', 'malformed', 'policy']);

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
        'effects' => data_set($data, 'performance_measure_effects', ['unexpected' => []]),
        'reporting_kind' => data_set($data, 'definition.reporting_kind', 'unknown'),
        'earnings_component' => data_set($data, 'definition.earnings_component', 'retained'),
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
})->with(['valid', 'structure', 'currency', 'precision', 'missing_value', 'dimensions', 'order', 'scale', 'object', 'effects', 'reporting_kind', 'earnings_component']);

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
        ->and($statement->next)->toBeNull()
        ->and($this->ledger->reports->partyStatement($this->ledger->reports->snapshot($this->book, '2026-02-28'), $receivable, $party)->closing->decimal())->toBe('375.00');

    $first = $this->ledger->reports->partyStatement($snapshot, $receivable, $party, '2026-02-01', limit: 1);
    $second = $this->ledger->reports->partyStatement($snapshot, $receivable, $party, '2026-02-01', limit: 1, after: $first->next);
    $balances = fn (PartyStatement $page): array => array_map(fn (array $line): string => $line['balance']->decimal(), $page->lines);
    expect($balances($first))->toBe(['800.00'])->and($first->next)->toBe($first->lines[0]['cursor'])
        ->and($balances($second))->toBe(['350.00'])->and($second->next)->toBeNull()
        ->and([$second->opening->decimal(), $second->closing->decimal(), $second->functionalClosing->decimal()])->toBe(['300.00', '350.00', '350.00']);
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
