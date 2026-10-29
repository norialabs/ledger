<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Assets\StubConvention;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Documents\TaxedDocumentRequest;
use Noria\Ledger\Documents\TaxLine;
use Noria\Ledger\Finance\ContractEvent;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Scheduling\Recurrence;
use Noria\Ledger\Scheduling\Schedule;
use Noria\Ledger\Session;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Tax\DeferredTaxRequest;
use Noria\Ledger\Tax\TaxRule;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;

beforeEach(function () {
    $this->committedConnection = Database::connection();
    $this->committedScope = new Scope((string) Str::uuid7(), 'committed-subledgers');
    $this->ledger = (new Ledger($this->committedConnection, new PermitAll))->for($this->committedScope);
    $this->entity = $this->ledger->catalog->entity('Committed accounting');
    $this->book = $this->ledger->catalog->book($this->entity, 'Statutory', $this->kes);
    $this->ledger->periods->open($this->book, '2026', '2026-01-01', '2026-12-31');
    $this->cash = $this->ledger->catalog->account($this->book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
    $this->income = $this->ledger->catalog->account($this->book, '4000', 'Income', AccountType::Income);
});

it('commits a recurring journal through the source link check at actual commit', function () {
    $rent = $this->ledger->catalog->account($this->book, '5100', 'Rent', AccountType::Expense);
    $amount = Money::of('1000', $this->kes);
    $this->ledger->schedules->recurring($this->book, 'rent', 'Monthly rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-31', occurrences: 1));
    expect($this->ledger->schedules->postRecurring($this->book, '2026-01-31'))->toHaveCount(1);
});

it('rejects a closing journal posing as a year-end close on income tax expense at actual commit', function () {
    $configuration = $this->ledger->configurations->install($this->book, new Pack('committed-income-tax', '1', accounts: [
        ['code' => '2300', 'name' => 'Current income tax payable', 'type' => 'liability'], ['code' => '5300', 'name' => 'Current income tax expense', 'type' => 'expense'],
    ], roles: ['tax.income.current' => '5300', 'tax.income.payable' => '2300'], settings: ['income_tax' => ['version' => 'reviewed:1']]));
    $retained = $this->ledger->catalog->account($this->book, '3100', 'Retained earnings', AccountType::Equity);
    $expense = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.current');
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'forged-close', '2026-07-01', 'Forged year-end close', [
        new Posting($expense, Side::Debit, Money::of('400', $this->kes)), new Posting($retained, Side::Credit, Money::of('400', $this->kes)),
    ], closing: true, source: new Source('year_end', (string) Str::uuid7()))))->toThrow(PDOException::class, 'corresponding fiscal closure');
});

it('rejects an orphan deferred tax journal at actual commit and rolls back its sequence', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'tax', $configuration, 'temporary:asset', deferredTaxInputs()));
    $expense = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.deferred');
    $liability = $this->ledger->configurations->account($this->book, $configuration, 'tax.deferred.liability');
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $storage->run(Permission::Tax, function () use ($storage, $id, $expense, $liability): void {
        $storage->connection->select("SELECT set_config('noria.ledger_deferred_tax', ?, true)", [$id]);
        $this->ledger->journals->post(new JournalRequest($this->book, 'orphan', '2026-01-01', 'Detached tax charge', [
            new Posting($expense, Side::Debit, Money::of('150', $this->kes)), new Posting($liability, Side::Credit, Money::of('150', $this->kes)),
        ], source: new Source('ledger.deferred_tax', $id)));
    }, $this->book))->toThrow(PDOException::class, 'requires its linked measurement');
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('commits deferred tax corrections only through the service and projects their exact evidence', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'tax', $configuration, 'temporary:asset', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $id, 'tax', '2026-01-01');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-01');
    $journal = $this->ledger->incomeTax->deferredHistory($snapshot, $id)[1]->journalId;
    expect($journal)->not->toBeNull();
    expect(fn () => $this->ledger->journals->reverse($this->book, text($journal), 'direct-correction', '2026-01-02', 'Correct tax'))
        ->toThrow(QueryException::class, 'corrected only by the income tax service');
    $event = $this->ledger->incomeTax->reverseDeferred($this->book, $id, 'correction', '2026-01-02', 'Correct tax');
    $corrected = $this->ledger->reports->snapshot($this->book, '2026-01-02');
    $history = $this->ledger->incomeTax->deferredHistory($corrected, $id);
    $reversal = $this->ledger->journals->get($this->book, text($history[2]->journalId));
    expect($history)->toHaveCount(3)->and($history[2]->id)->toBe($event)->and($history[2]->sequence)->toBe($reversal->sequence)
        ->and($reversal->reversesId)->toBe($journal)->and($this->ledger->incomeTax->deferredPositions($corrected))->toBe([])
        ->and($this->ledger->incomeTax->deferredPositions($snapshot)[0]->liability->decimal())->toBe('150.00');
});

function protectedFinanceFixture(Session $ledger, string $book): string
{
    $configuration = $ledger->configurations->install($book, new Pack('protected-finance', '1', accounts: [
        ['code' => '1300', 'name' => 'Loan gross carrying', 'type' => 'asset'],
        ['code' => '1310', 'name' => 'Loan allowance', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '5000', 'name' => 'Credit losses', 'type' => 'expense'],
    ], roles: ['cash' => '1000', 'loan' => '1300', 'interest' => '4000', 'ecl.allowance' => '1310', 'ecl.expense' => '5000']));

    return $ledger->finance->open($book, 'protected-loan', 'PROTECTED-LOAN', '2026-01-01', 'loan_asset', $configuration, [
        'opening' => '100', 'rate_per_slot' => '0', 'policy_version' => 'lending:1', 'basis_reference' => 'reviewed-contract:1', 'interest_cash_flow' => 'operating',
        'payments' => [['due_on' => '2026-01-31', 'payment' => '50', 'interest_paid' => '0'], ['due_on' => '2026-02-28', 'payment' => '50', 'interest_paid' => '0']],
    ]);
}

/** @return array{string, string} */
function committedTaxFixture(Session $ledger, string $book, string $entity, Currency $currency): array
{
    $configuration = $ledger->configurations->install($book, new Pack('committed-tax', '1', accounts: [
        ['code' => '1100', 'name' => 'Tax receivables', 'type' => 'asset', 'control' => true], ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'],
    ], roles: ['receivable' => '1100', 'revenue' => '4000', 'tax.output' => '2200'], taxRules: [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:1')],
        settings: ['tax_returns' => ['vat' => ['accounts' => ['tax.output'], 'codes' => ['EXAMPLE' => []], 'payable_terms' => ['output_tax' => '1'],
            'reconciliations' => ['settlement' => ['roles' => ['tax.output'], 'coefficient' => '0']]]]]));
    $party = $ledger->catalog->party($entity, 'tax-customer', 'Tax customer');
    $ledger->documents->issueTaxed(new TaxedDocumentRequest($book, 'tax-source', '2026-01-15', new Source('tax-fixture', 'sale'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($party, Money::of('116', $currency), '2026-01-31')], [new TaxLine('revenue', '100', '1', 'Taxable sale', ['EXAMPLE'])], ['EXAMPLE' => 'tax.output']));
    $return = $ledger->taxes->prepare($ledger->reports->snapshot($book, '2026-01-31'), 'prepared-tax-return', $configuration, 'vat', '2026-01-01');

    return [$configuration, $return];
}

it('independently rejects forged tax return results and preserves the committed source evidence', function () {
    [$configuration, $return] = committedTaxFixture($this->ledger, $this->book, $this->entity, $this->kes);
    $definition = $this->ledger->taxes->evidence($this->book, $return)['definition'];
    $result = $this->ledger->taxes->evidence($this->book, $return)['result'];
    $result['net_minor'] = '0';
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $storage->run(Permission::Tax, function () use ($configuration, $return, $definition, $result, $storage): void {
            $storage->insert('ledger_tax_returns', ['book_id' => $this->book, 'idempotency_key' => 'forged-tax-return', 'fingerprint' => str_repeat('a', 64),
                'configuration_id' => $configuration, 'scheme' => 'vat', 'starts_on' => '2026-01-01', 'effective_on' => '2026-01-31', 'through_sequence' => $definition->throughSequence,
                'previous_id' => $return, 'opening_credit_minor' => '0', 'result_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
        }, $this->book);
        $this->fail('The database must independently compute declared tax amounts.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('independently computed evidence');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before)
        ->and($this->ledger->taxes->evidence($this->book, $return)['result']['net_minor'])->toBe('1600');
});

it('protects committed tax evidence from editing deletion and truncation', function (string $operation) {
    [, $return] = committedTaxFixture($this->ledger, $this->book, $this->entity, $this->kes);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $storage->run(Permission::Tax, function () use ($storage, $return, $operation): void {
        match ($operation) {
            'update' => $storage->table('ledger_tax_returns')->where('id', $return)->update(['result_json' => '{}']),
            'delete' => $storage->table('ledger_tax_returns')->where('id', $return)->delete(),
            default => $storage->connection->unprepared('TRUNCATE ledger_tax_return_events'),
        };
    }, $this->book);
})->with(['update', 'delete', 'truncate'])->throws(QueryException::class, 'append-only');

it('commits protected finance events and rejects detached journals and one-sided corrections', function () {
    $contract = protectedFinanceFixture($this->ledger, $this->book);
    $event = $this->ledger->finance->period($this->book, $contract, 'first-payment', '2026-01-31', 1, 'Receive first installment');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $evidence = $this->ledger->finance->evidence($snapshot, $contract);
    $loan = $evidence['definition']->carryingAccountId;
    $this->ledger->catalog->dimensionValue($this->entity, 'portfolio', 'other');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    foreach ([[], ['portfolio' => 'other']] as $index => $dimensions) {
        try {
            $this->ledger->journals->post(new JournalRequest($this->book, 'detached-finance-'.$index, '2026-02-01', 'Detached finance adjustment', [
                new Posting($loan, Side::Debit, Money::of('1', $this->kes), dimensions: $dimensions), new Posting($this->cash, Side::Credit, Money::of('1', $this->kes), dimensions: $dimensions),
            ]));
            $this->fail('Protected finance journals must have matching events at commit.');
        } catch (PDOException $exception) {
            expect($exception->getMessage())->toContain('linked contract evidence');
        }
    }
    try {
        $this->ledger->journals->reverse($this->book, $evidence['events'][0]->journalId ?? throw new RuntimeException('Missing finance journal.'), 'detached-finance-reversal', '2026-02-01', 'Detached period correction');
        $this->fail('Finance journal corrections require exact event corrections.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('linked contract evidence');
    }
    expect($this->ledger->reports->snapshot($this->book, '2026-01-31')->throughSequence)->toBe($before->throughSequence);
    $correction = $this->ledger->finance->reverse($this->book, $event, 'correct-payment', '2026-02-01', 'Correct original installment');
    expect($this->ledger->finance->reverse($this->book, $event, 'correct-payment', '2026-02-01', 'Correct original installment'))->toBe($correction);
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-02-01'), $contract)['carrying']->decimal())->toBe('100.00')
        ->and($this->committedConnection->table('ledger_finance_contracts')->count())->toBe(0);
});

it('independently rejects forged finance amounts even when the forged journal balances', function () {
    $contract = protectedFinanceFixture($this->ledger, $this->book);
    $definition = $this->ledger->finance->evidence($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract)['definition'];
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $storage->run(Permission::Finance, function () use ($storage, $definition, $contract): void {
            $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-period-journal', '2026-01-31', 'Understated installment', [
                new Posting($definition->carryingAccountId, Side::Credit, Money::of('49', $this->kes)),
                new Posting($this->cash, Side::Debit, Money::of('49', $this->kes)),
            ], ['cash_flow' => ['investing' => '4900', 'operating' => '0']]));
            $storage->insert('ledger_finance_events', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'contract_id' => $contract,
                'idempotency_key' => 'forged-period', 'fingerprint' => str_repeat('a', 64), 'effective_on' => '2026-01-31', 'kind' => 'period',
                'inputs' => Canonical::json(['line' => 1]), 'carrying_delta' => '-4900', 'allowance_delta' => '0', 'written_off_delta' => '0', 'recovered_delta' => '0',
                'stage' => 1, 'prior_stage' => 1, 'journal_id' => $journal->id, 'reason' => 'Forged installment']);
        }, $this->book);
        $this->fail('The database must recompute installment values independently.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('independently measured effects');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('does not allow a forged no-journal finance event to change a protected allowance', function () {
    $contract = protectedFinanceFixture($this->ledger, $this->book);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $storage->run(Permission::Finance, function () use ($storage, $contract): void {
        $storage->insert('ledger_finance_events', ['book_id' => $this->book, 'contract_id' => $contract,
            'idempotency_key' => 'forged-model', 'fingerprint' => str_repeat('b', 64), 'effective_on' => '2026-01-31', 'kind' => 'ecl',
            'inputs' => Canonical::json(['stage' => 2, 'model_reference' => 'forged-assessment', 'contractual' => [['period' => 0, 'amount' => '100']],
                'scenarios' => [['weight' => '1', 'receipts' => ['100']]]]), 'carrying_delta' => '0', 'allowance_delta' => '1000', 'written_off_delta' => '0', 'recovered_delta' => '0',
            'stage' => 2, 'prior_stage' => 1, 'journal_id' => null, 'reason' => 'Forged allowance']);
    }, $this->book);
})->throws(QueryException::class, 'independently measured effects');

it('independently validates modified loan effects and calculation evidence at actual commit', function (bool $forgeAmount) {
    $contract = protectedFinanceFixture($this->ledger, $this->book);
    $this->ledger->finance->period($this->book, $contract, 'before-modification', '2026-01-31', 1, 'First installment');
    $definition = $this->ledger->finance->evidence($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract)['definition'];
    $gain = $this->ledger->catalog->account($this->book, '4500', 'Loan-change gains', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5500', 'Loan-change losses', AccountType::Expense);
    $payments = [['due_on' => '2026-02-28', 'payment' => '45', 'interest_paid' => '0']];
    $model = ['stage' => 1, 'model_reference' => 'reviewed-revised-credit', 'contractual' => [['period' => 1, 'amount' => '45']], 'scenarios' => [['weight' => '1', 'receipts' => ['45']]]];
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $storage->run(Permission::Finance, function () use ($storage, $contract, $definition, $payments, $model, $gain, $loss, $forgeAmount): void {
            $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-modification-journal', '2026-01-31', 'Forged measured loan change', [
                new Posting($definition->carryingAccountId, Side::Credit, Money::of($forgeAmount ? '4' : '5', $this->kes)),
                new Posting($loss, Side::Debit, Money::of($forgeAmount ? '4' : '5', $this->kes)),
            ], ['finance_calculation' => ['closing_minor' => '9999']]));
            $storage->insert('ledger_finance_events', ['book_id' => $this->book, 'contract_id' => $contract, 'idempotency_key' => 'forged-modification', 'fingerprint' => str_repeat('c', 64),
                'effective_on' => '2026-01-31', 'kind' => 'modification', 'inputs' => Canonical::json(['payments' => $payments, 'gain_account_id' => $gain, 'loss_account_id' => $loss,
                    'basis_reference' => 'reviewed-basis', 'loss_model' => $model]), 'carrying_delta' => $forgeAmount ? '-400' : '-500', 'allowance_delta' => '0', 'written_off_delta' => '0',
                'recovered_delta' => '0', 'stage' => 1, 'prior_stage' => 1, 'journal_id' => $journal->id, 'reason' => 'Forged modification']);
        }, $this->book);
        $this->fail('The database must verify measurement and its retained calculation.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($forgeAmount ? 'independently measured effects' : 'calculation evidence must match');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->finance->modify($this->book, $contract, 'valid-modification', '2026-01-31', $payments, $gain, $loss, 'Reviewed non-derecognizing change', 'Revise remaining payment', $model);
    $settlement = $this->ledger->finance->settle($this->book, $contract, 'valid-settlement', '2026-01-31', '40', '0', $gain, $loss, 'Full legal discharge', 'Settle modified loan');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $evidence = $this->ledger->finance->evidence($snapshot, $contract);
    $settlementEvent = collect($evidence['events'])->first(fn (ContractEvent $event) => $event->id === $settlement) ?? throw new RuntimeException('The settlement event is missing.');
    $journalId = $settlementEvent->journalId ?? throw new RuntimeException('Missing settlement journal.');
    try {
        $this->ledger->journals->reverse($this->book, $journalId, 'detached-settlement-reversal', '2026-02-01', 'Attempt detached correction');
        $this->fail('A settlement correction must restore its lifecycle state too.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('linked contract evidence');
    }
    expect($this->ledger->finance->position($snapshot, $contract)['settled'])->toBeTrue();
    $this->ledger->finance->reverse($this->book, $settlement, 'restore-settlement', '2026-02-01', 'Correct legal discharge');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-02-01'), $contract)['carrying']->decimal())->toBe('45.00');
})->with([false, true]);

it('commits asset disposal and restoration while rejecting detached valuation journals', function () {
    $cost = $this->ledger->catalog->account($this->book, '1500', 'Equipment', AccountType::Asset);
    $dep = $this->ledger->catalog->account($this->book, '1590', 'Accumulated depreciation', AccountType::Asset, normalSide: Side::Credit);
    $imp = $this->ledger->catalog->account($this->book, '1591', 'Accumulated impairment', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Depreciation and losses', AccountType::Expense);
    $asset = $this->ledger->assets->capitalize(new AssetRequest($this->book, 'cap', 'EQUIPMENT', '2026-01-01', '2026-01-01', Money::of('100', $this->kes), Money::of('0', $this->kes),
        Schedule::equal(Money::of('100', $this->kes), ['2026-01-31', '2026-02-28']), $cost, $dep, $imp, $expense, $this->cash, 'fixture:v1'));
    $event = $this->ledger->assets->dispose($this->book, $asset, 'dispose', '2026-01-31', Money::of('40', $this->kes), $this->cash, $this->income, $expense, 'Sell equipment');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->assets->position($snapshot, $asset)->disposed)->toBeTrue();
    $restore = $this->ledger->assets->reverse($this->book, $event, 'restore', '2026-02-01', 'Cancel sale');
    expect($this->ledger->assets->reverse($this->book, $event, 'restore', '2026-02-01', 'Cancel sale'))->toBe($restore);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->assets->position($snapshot, $asset)->carrying->decimal())->toBe('50.00');

    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'detached', '2026-02-01', 'Untracked asset adjustment', [
            new Posting($cost, Side::Debit, Money::of('1', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('1', $this->kes)),
        ]));
        $this->fail('Untracked asset postings must fail on commit.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('corresponding asset event');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($snapshot->throughSequence);
    expect($this->committedConnection->table('ledger_assets')->count())->toBe(0);
});

it('commits exact stock corrections and rejects a journal-only stock reversal', function () {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Inventory', AccountType::Asset);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Cost of goods', AccountType::Expense);
    $pool = $this->ledger->inventory->pool($this->book, 'STORE', 'SKU', $stock, ValuationMethod::Fifo);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'receive', '2026-01-01', new Quantity('0.3'), Money::of('0.01', $this->kes), $this->cash, 'Fractional stock');
    $adjustment = $this->ledger->inventory->adjustValue($this->book, $pool, 'adjust', '2026-01-02', Money::of('0.02', $this->kes), $this->cash, 'Freight');
    $issue = $this->ledger->inventory->issue($this->book, $pool, 'issue', '2026-01-03', new Quantity('0.1'), $expense, 'Issue stock');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');

    try {
        $this->ledger->journals->reverse($this->book, text($issue->journalId), 'detached-reversal', '2026-01-04', 'Invalid correction');
        $this->fail('A stock journal cannot be detached from its movement.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('matching stock valuation');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($snapshot->throughSequence);
    $this->ledger->inventory->reverse($this->book, $issue->id, 'restore-issue', '2026-01-04', 'Restore goods');
    $this->ledger->inventory->reverse($this->book, $adjustment->id, 'restore-adjustment', '2026-01-04', 'Restore cost');
    $this->ledger->inventory->reverse($this->book, $receipt->id, 'restore-receipt', '2026-01-04', 'Restore opening');
    expect($this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-12-31'), $pool)->value->decimal())->toBe('0.00');
    expect($this->committedConnection->table('ledger_inventory_moves')->count())->toBe(0);
});

it('commits supplier return variances and rejects forged commercial values independently in PostgreSQL', function () {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Inventory', AccountType::Asset);
    $clearing = $this->ledger->catalog->account($this->book, '2300', 'Supplier clearing', AccountType::Liability);
    $variance = $this->ledger->catalog->account($this->book, '5000', 'Purchase variance', AccountType::Expense);
    $pool = $this->ledger->inventory->pool($this->book, 'SUPPLIER', 'SKU', $stock, ValuationMethod::MovingAverage);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'purchase', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $clearing, 'Original purchase');
    $this->ledger->inventory->receive($this->book, $pool, 'new-purchase', '2026-01-02', new Quantity('10'), Money::of('300', $this->kes), $clearing, 'Higher current cost');
    $returned = $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'return', '2026-01-03', new Quantity('2'), $clearing, $variance, 'Supplier return');
    expect($returned->value->decimal())->toBe('-40.00');
    $commercialValue = $returned->commercialValue ?? throw new RuntimeException('The supplier return has no commercial value.');
    expect($commercialValue->decimal())->toBe('-20.00');
    $before = $this->ledger->events->cutoff($this->book);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    try {
        $storage->run(Permission::Inventory, function () use ($storage, $stock, $clearing, $variance, $pool, $receipt): void {
            $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-journal', '2026-01-04', 'Forged commercial refund', [
                new Posting($stock, Side::Credit, Money::of('20', $this->kes)),
                new Posting($clearing, Side::Debit, Money::of('9.99', $this->kes)),
                new Posting($variance, Side::Debit, Money::of('10.01', $this->kes)),
            ]));
            $storage->insert('ledger_inventory_moves', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'pool_id' => $pool, 'idempotency_key' => 'forged-return',
                'fingerprint' => str_repeat('a', 64), 'effective_on' => '2026-01-04', 'kind' => 'supplier_return', 'quantity' => '-1', 'value_minor' => '-2000',
                'commercial_minor' => '-999', 'counter_account_id' => $clearing, 'variance_account_id' => $variance, 'journal_id' => $journal->id,
                'reason' => 'Forged return', 'plan' => '[]', 'returns_id' => $receipt->id]);
        }, $this->book);
        $this->fail('The database must independently recompute the original receipt credit.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('conserve the original receipt price');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->inventory->reverse($this->book, $returned->id, 'restore', '2026-01-04', 'Correct supplier return');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe('400.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('0.00');
});

it('commits asset additions partial disposal and exact restoration with linked register journals', function () {
    $cost = $this->ledger->catalog->account($this->book, '1500', 'Equipment', AccountType::Asset);
    $dep = $this->ledger->catalog->account($this->book, '1590', 'Accumulated depreciation', AccountType::Asset, normalSide: Side::Credit);
    $imp = $this->ledger->catalog->account($this->book, '1591', 'Accumulated impairment', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Depreciation and losses', AccountType::Expense);
    $asset = $this->ledger->assets->capitalize(new AssetRequest($this->book, 'capitalization', 'ENHANCED', '2026-01-01', '2026-01-01', Money::of('100', $this->kes), Money::of('0', $this->kes),
        Schedule::equal(Money::of('100', $this->kes), ['2026-01-31', '2026-02-28']), $cost, $dep, $imp, $expense, $this->cash, 'fixture:v1'));
    $addition = $this->ledger->assets->addCost($this->book, $asset, 'addition', '2026-01-31', Money::of('50', $this->kes), $this->cash, Money::of('0', $this->kes),
        Schedule::equal(Money::of('100', $this->kes), ['2026-02-28']), 'improvement:v1', 'Capital improvement');
    $part = $this->ledger->assets->disposePart($this->book, $asset, 'partial-sale', '2026-01-31', '0.5', Money::of('45', $this->kes), $this->cash, $this->income, $expense,
        Money::of('0', $this->kes), Schedule::equal(Money::of('50', $this->kes), ['2026-02-28']), 'proportionate-sale:v1', 'Sell one half');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $asset);
    expect($position->cost->decimal())->toBe('75.00');
    expect($position->depreciation->decimal())->toBe('25.00');
    expect($position->carrying->decimal())->toBe('50.00');
    $this->ledger->assets->reverse($this->book, $part, 'restore-part', '2026-02-01', 'Correct disposal');
    $this->ledger->assets->reverse($this->book, $addition, 'restore-addition', '2026-02-01', 'Correct improvement');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-02-01'), $asset);
    expect($position->cost->decimal())->toBe('100.00');
    expect($position->depreciation->decimal())->toBe('50.00');
    expect($position->carrying->decimal())->toBe('50.00');
});

it('commits linked unrealized FX clearing and realized settlement together', function () {
    $usd = Currency::iso('USD');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'USD receivables', AccountType::Asset, $usd, control: true);
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $gain = $this->ledger->catalog->account($this->book, '4100', 'Unrealized gain', AccountType::Income);
    $realized = $this->ledger->catalog->account($this->book, '4200', 'Realized gain', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5100', 'Exchange loss', AccountType::Expense);
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'original', Rounding::HalfEven);
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'closing', Rounding::HalfEven);
    $paid = new ExchangeRate($usd, $this->kes, '145', '2026-02-01', 'paid', Rounding::HalfEven);
    $amount = Money::of('100', $usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'invoice', '2026-01-01', 'Invoice', [
        new Posting($ar, Side::Debit, $amount, $rate, $party), new Posting($this->income, Side::Credit, $rate->convert($amount)),
    ]));
    $original = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'))[0];
    $revaluation = $this->ledger->revaluations->revalue($this->book, $original->id, 'revalue', '2026-01-31', $closing, $gain, $loss, 'Closing revaluation') ?? throw new RuntimeException('The revaluation posted no journal.');

    try {
        $this->ledger->journals->reverse($this->book, $revaluation->id, 'detached-reversal', '2026-02-01', 'Invalid revaluation correction');
        $this->fail('A revaluation journal requires a carrying-value correction event.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('corresponding settlement or revaluation');
    }

    $this->ledger->journals->post(new JournalRequest($this->book, 'receipt', '2026-02-01', 'Receipt', [
        new Posting($this->cash, Side::Debit, $paid->convert($amount)), new Posting($ar, Side::Credit, $amount, $paid, $party),
    ]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));
    $allocation = $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'settle', $items[0]->id, $items[1]->id, $amount, '2026-02-02', 'Settle', $realized, $loss));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->reports->openItems($snapshot))->toBe([]);
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$gain]->closing->decimal())->toBe('0.00');
    expect($balances[$realized]->closing->decimal())->toBe('-1500.00');
    $release = $this->ledger->settlements->release($this->book, $allocation, 'release', '2026-02-03', 'Release settlement');
    expect($this->ledger->settlements->release($this->book, $allocation, 'release', '2026-02-03', 'Release settlement'))->toBe($release);
    expect($this->ledger->reports->controlReconciliation($this->ledger->reports->snapshot($this->book, '2026-02-28'), $ar)->decimal())->toBe('0.00');
});

it('commits linked stock transfers and cross-pool returns with dependency-safe corrections', function () {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Inventory', AccountType::Asset);
    $clearing = $this->ledger->catalog->account($this->book, '2300', 'Transfer clearing', AccountType::Liability);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Cost of goods', AccountType::Expense);
    $source = $this->ledger->inventory->pool($this->book, 'MAIN', 'SKU', $stock, ValuationMethod::Fifo);
    $destination = $this->ledger->inventory->pool($this->book, 'OTHER', 'SKU', $stock, ValuationMethod::MovingAverage);
    $this->ledger->inventory->receive($this->book, $source, 'receive', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->cash, 'Receive');
    $transfer = $this->ledger->inventory->transfer($this->book, $source, $destination, 'move', '2026-01-02', new Quantity('4'), $clearing, 'Move');
    $issue = $this->ledger->inventory->issue($this->book, $destination, 'sell', '2026-01-03', new Quantity('2'), $expense, 'Sell');
    $returned = $this->ledger->inventory->returnIssue($this->book, $issue->id, 'return', '2026-01-04', new Quantity('1'), 'Return to main store', $source);
    expect($returned->value->decimal())->toBe('10.00');
    $this->ledger->inventory->reverse($this->book, $returned->id, 'restore-return', '2026-01-05', 'Correct return');
    $this->ledger->inventory->reverse($this->book, $issue->id, 'restore-issue', '2026-01-05', 'Correct issue');
    $this->ledger->inventory->reverseTransfer($this->book, $transfer->id, 'restore-transfer', '2026-01-05', 'Correct transfer');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->inventory->balance($snapshot, $source)->value->decimal())->toBe('100.00');
    expect($this->ledger->inventory->balance($snapshot, $destination)->value->decimal())->toBe('0.00');
    expect($this->committedConnection->table('ledger_inventory_transfers')->count())->toBe(0);
});

it('rejects an extra movement attached to an existing transfer when the transaction commits', function () {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Inventory', AccountType::Asset);
    $clearing = $this->ledger->catalog->account($this->book, '2300', 'Transfer clearing', AccountType::Liability);
    $source = $this->ledger->inventory->pool($this->book, 'MAIN', 'SKU', $stock, ValuationMethod::Fifo);
    $destination = $this->ledger->inventory->pool($this->book, 'OTHER', 'SKU', $stock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $source, 'receive', '2026-01-01', new Quantity('1'), Money::of('1', $this->kes), $this->cash, 'Receive');
    $transfer = $this->ledger->inventory->transfer($this->book, $source, $destination, 'move', '2026-01-02', new Quantity('1'), $clearing, 'Move');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);

    try {
        $storage->run(Permission::Inventory, fn () => $storage->insert('ledger_inventory_moves', [
            'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'pool_id' => $source, 'idempotency_key' => 'extra', 'fingerprint' => str_repeat('0', 64),
            'effective_on' => '2026-01-03', 'kind' => 'receipt', 'quantity' => '1', 'value_minor' => '0', 'counter_account_id' => $clearing,
            'journal_id' => null, 'plan' => '[]', 'transfer_id' => $transfer->id, 'reason' => 'Invalid extra transfer member',
        ]), $this->book);
        $this->fail('Extra transfer members must fail at commit.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('matching atomic transfer');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($before->throughSequence);
});

it('commits monetary valuation evidence and protects its journal and historical cutoff', function () {
    $usd = Currency::iso('USD');
    $bank = $this->ledger->catalog->account($this->book, '1001', 'USD bank', AccountType::Asset, $usd, cashFlow: 'cash');
    $loss = $this->ledger->catalog->account($this->book, '5100', 'Exchange loss', AccountType::Expense);
    $this->ledger->monetaryAccounts->register($this->book, $bank, 'monetary-bank:v1');
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'initial', Rounding::HalfEven);
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-01-31', 'closing', Rounding::HalfEven);
    $amount = Money::of('100', $usd);
    $this->ledger->journals->post(new JournalRequest($this->book, 'fund', '2026-01-01', 'Fund USD bank', [
        new Posting($bank, Side::Debit, $amount, $rate), new Posting($this->income, Side::Credit, $rate->convert($amount)),
    ]));
    $valuation = $this->ledger->monetaryAccounts->revalue($this->book, $bank, 'close', '2026-01-31', $closing, $this->income, $loss, 'Close foreign bank');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');

    try {
        $this->ledger->journals->reverse($this->book, text($valuation->journalId), 'detached', '2026-02-01', 'Invalid journal-only correction');
        $this->fail('Monetary evidence must be corrected with its journal.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('corresponding valuation evidence');
    }

    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'backdated', '2026-01-15', 'Invalid late source posting', [
            new Posting($bank, Side::Debit, $amount, $rate), new Posting($this->income, Side::Credit, $rate->convert($amount)),
        ]));
        $this->fail('Backdated source movements must not silently change an earlier valuation.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('silently backdate');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($before->throughSequence);
    $this->ledger->monetaryAccounts->reverse($this->book, $valuation->id, 'restore', '2026-02-01', 'Reverse closing valuation');
    expect($this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')->exchange->decimal())->toBe('0.00');
    expect($this->committedConnection->table('ledger_monetary_valuations')->count())->toBe(0);
});

it('independently rejects duplicate component evidence and overstated production cost', function (bool $duplicate) {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Stock', AccountType::Asset);
    $wip = $this->ledger->catalog->account($this->book, '1400', 'WIP', AccountType::Asset);
    $raw = $this->ledger->inventory->pool($this->book, 'RAW', 'RAW', $stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'FINISHED', 'FINISHED', $stock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $raw, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->cash, 'Materials');
    $before = $this->ledger->events->cutoff($this->book);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    try {
        $storage->run(Permission::Inventory, function () use ($storage, $stock, $wip, $raw, $finished, $duplicate): void {
            $id = (string) Str::uuid7();
            $legs = [];
            foreach ([[$raw, 'issue', '-1', '-1000'], [$finished, 'receipt', '1', '2000']] as $index => [$pool, $kind, $quantity, $value]) {
                $move = (string) Str::uuid7();
                $quote = row($storage->connection->selectOne('SELECT ledger_inventory_quote(?::uuid, ?::uuid, ?::uuid, ?, ?::numeric, ?::numeric)::text AS quote', [$this->committedScope->tenantId, $this->book, $pool, $kind, $quantity, $value]));
                $plan = data_get(json_decode(text($quote->quote), true, 512, JSON_THROW_ON_ERROR), 'plan');
                $amount = Money::minor((string) abs((int) $value), $this->kes);
                $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-leg-'.$index, '2026-01-02', 'Forged production', [
                    new Posting($stock, $kind === 'issue' ? Side::Credit : Side::Debit, $amount),
                    new Posting($wip, $kind === 'issue' ? Side::Debit : Side::Credit, $amount),
                ]));
                $storage->insert('ledger_inventory_moves', ['id' => $move, 'book_id' => $this->book, 'pool_id' => $pool, 'idempotency_key' => 'forged-move-'.$index,
                    'fingerprint' => str_repeat('a', 64), 'effective_on' => '2026-01-02', 'kind' => $kind, 'quantity' => $quantity, 'value_minor' => $value,
                    'counter_account_id' => $wip, 'journal_id' => $journal->id, 'reason' => 'Forged production', 'plan' => Canonical::json($plan), 'production_id' => $id]);
                $legs[] = ['movement_id' => $move, 'pool_id' => $pool, 'quantity' => $quantity, 'value_minor' => $value];
            }
            $storage->insert('ledger_productions', ['id' => $id, 'book_id' => $this->book, 'idempotency_key' => 'forged-production', 'fingerprint' => str_repeat('a', 64),
                'effective_on' => '2026-01-02', 'output_pool_id' => $finished, 'output_movement_id' => $legs[1]['movement_id'], 'output_quantity' => '1', 'output_value_minor' => '2000',
                'wip_account_id' => $wip, 'inputs' => Canonical::json($duplicate ? [$legs[0], $legs[0]] : [$legs[0]]), 'reason' => 'Overstated cost']);
        }, $this->book);
        $this->fail('Invalid production evidence must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($duplicate ? 'component movements must be unique' : 'conserve exact component and conversion costs');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    expect($this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-01-31'), $raw)->value->decimal())->toBe('100.00');
})->with([false, true]);

it('rejects production corrections that substitute another productions component movement', function () {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Stock', AccountType::Asset);
    $wip = $this->ledger->catalog->account($this->book, '1400', 'WIP', AccountType::Asset);
    $productions = [];
    foreach (['A', 'B'] as $label) {
        $raw = $this->ledger->inventory->pool($this->book, 'RAW-'.$label, 'RAW-'.$label, $stock, ValuationMethod::Fifo);
        $finished = $this->ledger->inventory->pool($this->book, 'FINISHED-'.$label, 'FINISHED-'.$label, $stock, ValuationMethod::Fifo);
        $this->ledger->inventory->receive($this->book, $raw, 'materials-'.$label, '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->cash, 'Materials');
        $productions[] = $this->ledger->inventory->produce($this->book, [['pool' => $raw, 'quantity' => new Quantity('1')]], $finished, new Quantity('1'), $wip, 'production-'.$label, '2026-01-02', 'Assemble');
    }
    $before = $this->ledger->events->cutoff($this->book);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    try {
        $storage->run(Permission::Inventory, function () use ($storage, $wip, $productions): void {
            $id = (string) Str::uuid7();
            $legs = [];
            foreach ([$productions[0]->output, $productions[1]->inputs[0]] as $index => $movement) {
                $original = $storage->find('ledger_inventory_moves', $movement->id, $this->book);
                $reversed = $this->ledger->journals->reverse($this->book, text($movement->journalId), 'wrong-leg-'.$index, '2026-01-03', 'Wrong correction');
                $plan = row($storage->connection->selectOne("SELECT coalesce(jsonb_agg(jsonb_build_object('layer_id', value->>'layer_id', 'quantity', (-(value->>'quantity')::numeric)::numeric(38, 12)::text, 'value_minor', (-(value->>'value_minor')::numeric)::text) ORDER BY ordinality), '[]'::jsonb)::text AS plan FROM jsonb_array_elements(?::jsonb) WITH ORDINALITY", [$original->plan]));
                $leg = ['movement_id' => (string) Str::uuid7(), 'pool_id' => $original->pool_id, 'quantity' => (string) $movement->quantity->value->negated(), 'value_minor' => (string) $movement->value->minor->negated()];
                $storage->insert('ledger_inventory_moves', ['id' => $leg['movement_id'], 'book_id' => $this->book, 'pool_id' => $leg['pool_id'], 'idempotency_key' => 'wrong-move-'.$index,
                    'fingerprint' => str_repeat('b', 64), 'effective_on' => '2026-01-03', 'kind' => 'reversal', 'quantity' => $leg['quantity'], 'value_minor' => $leg['value_minor'],
                    'counter_account_id' => $wip, 'journal_id' => $reversed->id, 'reason' => 'Wrong correction', 'plan' => $plan->plan, 'reverses_id' => $movement->id, 'production_id' => $id]);
                $legs[] = $leg;
            }
            $storage->insert('ledger_productions', ['id' => $id, 'book_id' => $this->book, 'idempotency_key' => 'wrong-production', 'fingerprint' => str_repeat('b', 64),
                'effective_on' => '2026-01-03', 'output_pool_id' => $legs[0]['pool_id'], 'output_movement_id' => $legs[0]['movement_id'], 'output_quantity' => '-1', 'output_value_minor' => '-1000',
                'wip_account_id' => $wip, 'inputs' => Canonical::json([$legs[1]]), 'reverses_id' => $productions[0]->id, 'reason' => 'Output A and component B']);
        }, $this->book);
        $this->fail('Production corrections must preserve original component identities.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('component issue movements');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->inventory->reverseProduction($this->book, $productions[0]->id, 'correct-A', '2026-01-03', 'Correct A');
    $this->ledger->inventory->reverseProduction($this->book, $productions[1]->id, 'correct-B', '2026-01-03', 'Correct B');
});

it('independently verifies fiscal closures clear earnings into the declared retained account', function (bool $wrongRetained) {
    $amount = Money::of('100', $this->kes);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $otherEquity = $this->ledger->catalog->account($this->book, '3100', 'Other equity', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->book, 'income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $before = $this->ledger->events->cutoff($this->book);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    try {
        $storage->run(Permission::Close, function () use ($storage, $retained, $otherEquity, $amount, $wrongRetained): void {
            $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-close', '2026-12-31', 'Forged closure', [
                new Posting($wrongRetained ? $this->income : $retained, Side::Debit, $amount), new Posting($otherEquity, Side::Credit, $amount),
            ], closing: true));
            $storage->insert('ledger_year_closures', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'idempotency_key' => 'forged-close', 'fingerprint' => str_repeat('a', 64),
                'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'journal_id' => $journal->id, 'retained_account_id' => $retained, 'reason' => 'Wrong closing effects']);
        }, $this->book);
        $this->fail('Fiscal closure evidence must match exact earnings and retained effects.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('clear earnings by account and dimension');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->yearEnd->close($this->book, 'valid-close', '2026-01-01', '2026-12-31', $retained, 'Close the year');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('100.00');
    expect(byAccount($this->ledger->reports->trialBalance($snapshot))[$retained]->closing->decimal())->toBe('-100.00');
})->with([false, true]);

it('protects conversion-cost recognition from detached or backdated WIP corrections', function () {
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Stock', AccountType::Asset);
    $wip = $this->ledger->catalog->account($this->book, '1400', 'WIP', AccountType::Asset);
    $raw = $this->ledger->inventory->pool($this->book, 'RAW', 'RAW', $stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'FINISHED', 'FINISHED', $stock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $raw, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->cash, 'Materials');
    $labour = $this->ledger->journals->post(new JournalRequest($this->book, 'labour', '2026-01-01', 'Labour costs', [new Posting($wip, Side::Debit, Money::of('15', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('15', $this->kes))]));
    $production = $this->ledger->inventory->produce($this->book, [['pool' => $raw, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'produce', '2026-01-03', 'Assemble', Money::of('15', $this->kes));
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->journals->reverse($this->book, $labour->id, 'detach-labour', '2026-01-02', 'Invalid backdated WIP correction');
        $this->fail('Recognized conversion costs cannot lose their historical WIP funding.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('invalidate recognized production conversion costs');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->inventory->reverseProduction($this->book, $production->id, 'correct-assembly', '2026-01-04', 'Correct assembly');
    $this->ledger->journals->reverse($this->book, $labour->id, 'correct-labour', '2026-01-04', 'Correct recognized labour');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')));
    expect($balances[$wip]->closing->decimal())->toBe('0.00');
});

it('rejects malformed recognition plans independently of PHP validation', function () {
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $storage->run(Permission::Configure, fn () => $storage->insert('ledger_schedules', [
        'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'idempotency_key' => 'malformed-plan', 'fingerprint' => str_repeat('a', 64),
        'description' => 'Invalid recognition plan', 'debit_account_id' => $this->cash, 'credit_account_id' => $this->income, 'amount_minor' => '100',
        'plan' => Canonical::json([['due_on' => '2026-01-31', 'amount_minor' => null], ['due_on' => '2026-02-28', 'amount_minor' => '100']]),
        'policy_version' => 'invalid:v1', 'dimensions' => '{}',
    ]), $this->book);
})->throws(QueryException::class, 'Invalid schedule plan');

it('rejects earnings appended after fiscal closing evidence within the same transaction', function () {
    $amount = Money::of('100', $this->kes);
    $retained = $this->ledger->catalog->account($this->book, '3000', 'Retained earnings', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->book, 'income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $before = $this->ledger->events->cutoff($this->book);
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    try {
        $storage->run(Permission::Close, function () use ($storage, $amount, $retained): void {
            $closing = $this->ledger->journals->post(new JournalRequest($this->book, 'close', '2026-12-31', 'Close', [new Posting($this->income, Side::Debit, $amount), new Posting($retained, Side::Credit, $amount)], closing: true));
            $storage->insert('ledger_year_closures', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'idempotency_key' => 'close', 'fingerprint' => str_repeat('a', 64),
                'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'journal_id' => $closing->id, 'retained_account_id' => $retained, 'reason' => 'Close']);
            $this->ledger->journals->post(new JournalRequest($this->book, 'late-income', '2026-12-31', 'Late income', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
        }, $this->book);
        $this->fail('Closing evidence must still be valid at commit.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('leave earnings cleared when the transaction commits');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('rejects forged per-asset surplus budgets independently in PostgreSQL', function () {
    $cost = $this->ledger->catalog->account($this->book, '1500', 'Cost', AccountType::Asset);
    $dep = $this->ledger->catalog->account($this->book, '1590', 'Depreciation', AccountType::Asset);
    $imp = $this->ledger->catalog->account($this->book, '1591', 'Impairment', AccountType::Asset);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Expense', AccountType::Expense);
    $surplus = $this->ledger->catalog->account($this->book, '3100', 'Surplus', AccountType::Equity);
    $asset = $this->ledger->assets->capitalize(new AssetRequest($this->book, 'asset', 'ASSET', '2026-01-01', '2026-01-01', Money::of('100', $this->kes), Money::of('0', $this->kes),
        Schedule::equal(Money::of('100', $this->kes), ['2026-12-31']), $cost, $dep, $imp, $expense, $this->cash, 'fixture:v1', stubConvention: StubConvention::CompletedPeriods));
    $storage = new ScopedDatabase($this->committedConnection, $this->committedScope, new PermitAll);
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $storage->run(Permission::Assets, function () use ($storage, $asset, $cost, $surplus): void {
            $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'forged-budget', '2026-01-01', 'Revalue', [new Posting($cost, Side::Debit, Money::of('20', $this->kes)), new Posting($surplus, Side::Credit, Money::of('20', $this->kes))]));
            $storage->insert('ledger_asset_events', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'asset_id' => $asset, 'idempotency_key' => 'forged-budget', 'fingerprint' => str_repeat('a', 64),
                'effective_on' => '2026-01-01', 'kind' => 'revaluation', 'amount_minor' => '2000', 'cost_delta' => '2000', 'depreciation_minor' => '0', 'impairment_minor' => '0', 'disposal_delta' => 0,
                'surplus_delta' => '5000', 'loss_delta' => '0', 'surplus_account_id' => $surplus, 'counter_account_id' => $surplus, 'journal_id' => $journal->id,
                'residual_minor' => '0', 'plan' => Canonical::json(Schedule::equal(Money::of('120', $this->kes), ['2026-12-31'])), 'policy_version' => 'forged:v1', 'reason' => 'Inflated available surplus']);
        }, $this->book);
        $this->fail('Surplus budgets must agree with financial effects.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('Incorrect asset carrying effects');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});
