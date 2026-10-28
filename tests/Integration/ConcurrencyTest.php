<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Associates\AssociateRequest;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Scheduling\Schedule;
use Noria\Ledger\Statutory\SubmissionRequest;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\AuditEvent;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;
use Noria\Ledger\Tests\Support\Race;

beforeEach(function () {
    $connection = Database::connection();
    $this->raceScope = new Scope((string) Str::uuid7(), 'race-controller');
    $this->raceDb = new ScopedDatabase($connection, $this->raceScope, new PermitAll);
    $this->raceLedger = (new Ledger($connection, new PermitAll))->for($this->raceScope);
    $this->raceEntity = $this->raceLedger->catalog->entity('Concurrent books');
    $this->raceBook = $this->raceLedger->catalog->book($this->raceEntity, 'Statutory', $this->kes);
    $this->raceLedger->periods->open($this->raceBook, '2026', '2026-01-01', '2026-12-31');
    $this->raceCash = $this->raceLedger->catalog->account($this->raceBook, '1000', 'Bank', AccountType::Asset);
    $this->raceIncome = $this->raceLedger->catalog->account($this->raceBook, '4000', 'Income', AccountType::Income);
});

it('deduplicates truly concurrent requests for the same financial event', function () {
    $command = ['operation' => 'post', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'same-event', 'amount' => '100', 'cash' => $this->raceCash, 'income' => $this->raceIncome];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, $command]);
    expect($results[0]['ok'])->toBeTrue();
    expect($results[1]['ok'])->toBeTrue();
    expect($results[0]['id'])->toBe($results[1]['id']);
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->reports->generalLedger($snapshot))->toHaveCount(2);
    expect($this->raceLedger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('100.00');
});

it('serializes OCI recycling capacity and preserves identical concurrent retries', function (bool $sameKey) {
    $reserve = $this->raceLedger->catalog->account($this->raceBook, '3100', 'OCI reserve', AccountType::Equity);
    $source = $this->raceLedger->journals->post(new JournalRequest($this->raceBook, 'oci', '2026-01-01', 'Reviewed OCI', [
        new Posting($reserve, Side::Credit, Money::of('100', $this->kes), reportingMovement: new ReportingMovement('oci', 'reviewed:1', 'Original gain', 'recyclable')),
        new Posting($this->raceCash, Side::Debit, Money::of('100', $this->kes)),
    ]));
    $command = ['operation' => 'recycle_oci', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'recycle-a',
        'source' => $source->id, 'amount' => '60', 'income' => $this->raceIncome];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => $sameKey ? 'recycle-a' : 'recycle-b']]);
    expect(count(array_filter(array_column($results, 'ok'))))->toBe($sameKey ? 2 : 1);
    if ($sameKey) {
        expect($results[0]['id'])->toBe($results[1]['id']);
    }
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->reports->generalLedger($snapshot))->toHaveCount(4)
        ->and($this->raceLedger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('60.00');
})->with([false, true]);

it('serializes protected finance slots and preserves retry identities across competing runners', function (bool $sameKey) {
    $this->raceLedger->catalog->account($this->raceBook, '1010', 'Finance cash', AccountType::Asset, cashFlow: 'cash');
    $configuration = $this->raceLedger->configurations->install($this->raceBook, new Pack('concurrent-finance', '1', accounts: [
        ['code' => '1300', 'name' => 'Loan', 'type' => 'asset'], ['code' => '1310', 'name' => 'Allowance', 'type' => 'asset'], ['code' => '5000', 'name' => 'Credit losses', 'type' => 'expense'],
    ], roles: ['cash' => '1010', 'loan' => '1300', 'interest' => '4000', 'ecl.allowance' => '1310', 'ecl.expense' => '5000']));
    $contract = $this->raceLedger->finance->open($this->raceBook, 'loan', 'RACE-LOAN', '2026-01-01', 'loan_asset', $configuration,
        ['opening' => '100', 'rate_per_slot' => '0', 'interest_cash_flow' => 'operating', 'policy_version' => 'loan:1', 'basis_reference' => 'reviewed-loan:1',
            'payments' => [['due_on' => '2026-01-31', 'payment' => '50', 'interest_paid' => '0'], ['due_on' => '2026-02-28', 'payment' => '50', 'interest_paid' => '0']]]);
    $command = ['operation' => 'finance_period', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'contract' => $contract, 'key' => 'slot-a', 'amount' => '0'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => $sameKey ? 'slot-a' : 'slot-b']]);
    expect(count(array_filter(array_column($results, 'ok'))))->toBe($sameKey ? 2 : 1);
    if ($sameKey) {
        expect($results[0]['id'])->toBe($results[1]['id']);
    }
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('50.00')
        ->and($this->raceLedger->finance->evidence($snapshot, $contract)['events'])->toHaveCount(1);
})->with([false, true]);

it('settles protected loans once across competing payoff commands and retains exact retries', function (bool $sameKey) {
    $this->raceLedger->catalog->account($this->raceBook, '1010', 'Finance cash', AccountType::Asset, cashFlow: 'cash');
    $configuration = $this->raceLedger->configurations->install($this->raceBook, new Pack('settlement-race', '1', accounts: [
        ['code' => '1300', 'name' => 'Loan', 'type' => 'asset'], ['code' => '1310', 'name' => 'Allowance', 'type' => 'asset'], ['code' => '5000', 'name' => 'Credit losses', 'type' => 'expense'],
        ['code' => '4500', 'name' => 'Loan gains', 'type' => 'income'], ['code' => '5500', 'name' => 'Loan losses', 'type' => 'expense'],
    ], roles: ['cash' => '1010', 'loan' => '1300', 'interest' => '4000', 'ecl.allowance' => '1310', 'ecl.expense' => '5000', 'loan.gain' => '4500', 'loan.loss' => '5500']));
    $contract = $this->raceLedger->finance->open($this->raceBook, 'payoff-loan', 'PAYOFF-LOAN', '2026-01-01', 'loan_asset', $configuration,
        ['opening' => '100', 'rate_per_slot' => '0', 'interest_cash_flow' => 'operating', 'policy_version' => 'loan:1', 'basis_reference' => 'reviewed-loan:1',
            'payments' => [['due_on' => '2026-12-31', 'payment' => '100', 'interest_paid' => '0']]]);
    $command = ['operation' => 'finance_settle', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'contract' => $contract, 'key' => 'payoff-a', 'amount' => '95',
        'gain' => $this->raceLedger->configurations->account($this->raceBook, $configuration, 'loan.gain'), 'loss' => $this->raceLedger->configurations->account($this->raceBook, $configuration, 'loan.loss')];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => $sameKey ? 'payoff-a' : 'payoff-b']]);
    expect(count(array_filter(array_column($results, 'ok'))))->toBe($sameKey ? 2 : 1);
    if ($sameKey) {
        expect($results[0]['id'])->toBe($results[1]['id']);
    }
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->finance->position($snapshot, $contract)['settled'])->toBeTrue()
        ->and($this->raceLedger->reports->cashFlow($snapshot, '2026-01-01')->closing->decimal())->toBe('-5.00');
})->with([false, true]);

it('serializes original tax return identities and deduplicates competing preparation retries', function (bool $sameKey) {
    $configuration = $this->raceLedger->configurations->install($this->raceBook, new Pack('concurrent-tax', '1', accounts: [
        ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'],
    ], roles: ['tax.output' => '2200'], settings: ['tax_returns' => ['vat' => ['accounts' => ['tax.output'], 'codes' => ['EXAMPLE' => []],
        'payable_terms' => ['output_tax' => '1'], 'reconciliations' => ['settlement' => ['roles' => ['tax.output'], 'coefficient' => '0']]]]]));
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-01-31');
    $command = ['operation' => 'tax_prepare', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'configuration' => $configuration,
        'cutoff' => $snapshot->throughSequence, 'key' => 'tax-a', 'amount' => '0'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => $sameKey ? 'tax-a' : 'tax-b']]);
    expect(count(array_filter(array_column($results, 'ok'))))->toBe($sameKey ? 2 : 1);
    if ($sameKey) {
        expect($results[0]['id'])->toBe($results[1]['id']);
    }
    $id = data_get(collect($results)->first(fn (array $result): bool => $result['ok'] === true), 'id');
    expect($this->raceLedger->taxes->evidence($this->raceBook, text($id))['status'])->toBe('prepared');
})->with([false, true]);

it('grants statutory transmission work to only one competing runner including identical claim retries', function (bool $sameKey) {
    $receivable = $this->raceLedger->catalog->account($this->raceBook, '1100', 'Receivables', AccountType::Asset, control: true);
    $party = $this->raceLedger->catalog->party($this->raceEntity, 'delivery-customer', 'Customer');
    $invoice = $this->raceLedger->documents->issue(new DocumentRequest($this->raceBook, 'delivery-invoice', '2026-01-01', new Source('delivery-fixture', 'INV-1'), DocumentKind::SalesInvoice,
        $receivable, [new Obligation($party, Money::of('100', $this->kes), '2026-01-31')], [new Charge($this->raceIncome, Money::of('100', $this->kes), 'Supply')]));
    $submission = $this->raceLedger->statutory->enqueue(new SubmissionRequest($this->raceBook, 'delivery', 'invoice', $invoice->id, 'etims.branch', 'fixture.etims', '1', 'sandbox', 'request-1', '{"invoice":"INV-1"}'));
    $command = ['operation' => 'statutory_claim', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'submission' => $submission, 'key' => 'claim-a', 'amount' => '0'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => $sameKey ? 'claim-a' : 'claim-b']]);
    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and(count(array_filter(array_column($results, 'id'))))->toBe(1);
    $evidence = $this->raceLedger->statutory->evidence($this->raceBook, $submission);
    expect($evidence['state']['status'])->toBe('inflight')->and($evidence['state']['attempts'])->toBe(1)->and($evidence['events'])->toHaveCount(1);
})->with([false, true]);

it('serializes associate loss limits and deduplicates concurrent result retries', function (bool $sameKey) {
    $investee = $this->raceLedger->catalog->entity('Concurrent associate');
    $investment = $this->raceLedger->catalog->account($this->raceBook, '1200', 'Investment', AccountType::Asset);
    $oci = $this->raceLedger->catalog->account($this->raceBook, '3100', 'Associate OCI', AccountType::Equity);
    $expense = $this->raceLedger->catalog->account($this->raceBook, '5000', 'Impairment', AccountType::Expense);
    $associate = $this->raceLedger->associates->acquire(new AssociateRequest($this->raceBook, 'acquire', 'ASSOCIATE', $investee, '2026-01-01', Money::of('100', $this->kes), Money::of('300', $this->kes), '0.3',
        $investment, $this->raceCash, $this->raceIncome, $oci, $this->raceCash, $expense, 'Significant influence without control', 'ias28:v1'));
    $command = ['operation' => 'associate_profit', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'associate' => $associate, 'key' => 'loss-a', 'amount' => '-300'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => $sameKey ? 'loss-a' : 'loss-b']]);
    expect(array_column($results, 'ok'))->toBe([true, true]);
    expect($results[0]['id'] === $results[1]['id'])->toBe($sameKey);
    $position = $this->raceLedger->associates->position($this->raceLedger->reports->snapshot($this->raceBook, '2026-02-28'), $associate);
    expect($position->carrying->decimal())->toBe($sameKey ? '10.00' : '0.00');
    expect($position->unrecognizedProfitLoss->decimal())->toBe($sameKey ? '0.00' : '80.00');
})->with([false, true]);

it('rejects conflicting concurrent use of the same idempotency key', function () {
    $command = ['operation' => 'post', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'conflict', 'amount' => '100', 'cash' => $this->raceCash, 'income' => $this->raceIncome];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'amount' => '200']]);
    expect(array_column($results, 'ok'))->toContain(true, false);
    expect($this->raceLedger->reports->generalLedger($this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31')))->toHaveCount(2);
});

it('prevents two concurrent allocations from consuming the same outstanding amount', function () {
    $ar = $this->raceLedger->catalog->account($this->raceBook, '1100', 'Receivables', AccountType::Asset, control: true);
    $party = $this->raceLedger->catalog->party($this->raceEntity, 'customer', 'Customer');
    $money = Money::of('100', $this->kes);
    $this->raceLedger->journals->post(new JournalRequest($this->raceBook, 'invoice', '2026-01-01', 'Invoice', [
        new Posting($ar, Side::Debit, $money, partyId: $party), new Posting($this->raceIncome, Side::Credit, $money),
    ]));
    $this->raceLedger->journals->post(new JournalRequest($this->raceBook, 'receipt', '2026-01-02', 'Receipt', [
        new Posting($this->raceCash, Side::Debit, $money), new Posting($ar, Side::Credit, $money, partyId: $party),
    ]));
    $items = $this->raceLedger->reports->openItems($this->raceLedger->reports->snapshot($this->raceBook, '2026-02-28'));
    $command = ['operation' => 'allocate', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'allocation-a', 'amount' => '80', 'debit' => $items[0]->id, 'credit' => $items[1]->id];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => 'allocation-b']]);
    expect(array_column($results, 'ok'))->toContain(true, false);
    $remaining = $this->raceLedger->reports->openItems($this->raceLedger->reports->snapshot($this->raceBook, '2026-02-28'));
    expect($remaining[0]->outstanding->decimal())->toBe('20.00');
    expect($remaining[1]->outstanding->decimal())->toBe('20.00');
});

it('prevents two stock issues from consuming the same quantity or cost layers', function (ValuationMethod $method) {
    $stock = $this->raceLedger->catalog->account($this->raceBook, '1300', 'Inventory', AccountType::Asset);
    $expense = $this->raceLedger->catalog->account($this->raceBook, '5000', 'Cost of goods', AccountType::Expense);
    $pool = $this->raceLedger->inventory->pool($this->raceBook, 'STORE', 'SKU', $stock, $method);
    $this->raceLedger->inventory->receive($this->raceBook, $pool, 'receive', '2026-01-01', new Quantity('100'), Money::of('100', $this->kes), $this->raceCash, 'Receive stock');
    $command = ['operation' => 'issue', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'issue-a', 'amount' => '0', 'quantity' => '80', 'pool' => $pool, 'counter' => $expense];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => 'issue-b']]);
    expect(array_column($results, 'ok'))->toContain(true, false);
    $balance = $this->raceLedger->inventory->balance($this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31'), $pool);
    expect($balance->quantity->decimal())->toBe('20.000000000000');
    expect($balance->value->decimal())->toBe('20.00');
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('posts one depreciation slot once across concurrent runners', function () {
    $cost = $this->raceLedger->catalog->account($this->raceBook, '1500', 'Equipment', AccountType::Asset);
    $dep = $this->raceLedger->catalog->account($this->raceBook, '1590', 'Accumulated depreciation', AccountType::Asset, normalSide: Side::Credit);
    $imp = $this->raceLedger->catalog->account($this->raceBook, '1591', 'Accumulated impairment', AccountType::Asset, normalSide: Side::Credit);
    $expense = $this->raceLedger->catalog->account($this->raceBook, '5000', 'Depreciation', AccountType::Expense);
    $asset = $this->raceLedger->assets->capitalize(new AssetRequest($this->raceBook, 'cap', 'EQUIPMENT-1', '2026-01-01', '2026-01-01',
        Money::of('100', $this->kes), Money::of('0', $this->kes), Schedule::equal(Money::of('100', $this->kes), ['2026-01-31']),
        $cost, $dep, $imp, $expense, $this->raceCash, 'fixture:v1'));
    $command = ['operation' => 'depreciate', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'amount' => '0', 'asset' => $asset];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, $command]);
    expect(array_column($results, 'ok'))->toBe([true, true]);
    expect(array_column($results, 'id'))->toContain('');
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->reports->generalLedger($snapshot))->toHaveCount(4);
    expect($this->raceLedger->assets->position($snapshot, $asset)->depreciation->decimal())->toBe('100.00');
});

it('does not let concurrent stock returns exceed the original issue quantity', function () {
    $stock = $this->raceLedger->catalog->account($this->raceBook, '1300', 'Inventory', AccountType::Asset);
    $expense = $this->raceLedger->catalog->account($this->raceBook, '5000', 'Cost of goods', AccountType::Expense);
    $pool = $this->raceLedger->inventory->pool($this->raceBook, 'MAIN', 'SKU', $stock, ValuationMethod::Fifo);
    $this->raceLedger->inventory->receive($this->raceBook, $pool, 'receive', '2026-01-01', new Quantity('100'), Money::of('100', $this->kes), $this->raceCash, 'Receive');
    $issue = $this->raceLedger->inventory->issue($this->raceBook, $pool, 'sell', '2026-01-02', new Quantity('100'), $expense, 'Issue all');
    $command = ['operation' => 'return', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'issue' => $issue->id, 'key' => 'return-a', 'quantity' => '80', 'amount' => '0'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => 'return-b']]);
    expect(array_column($results, 'ok'))->toContain(true, false);
    $balance = $this->raceLedger->inventory->balance($this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31'), $pool);
    expect($balance->quantity->decimal())->toBe('80.000000000000');
    expect($balance->value->decimal())->toBe('80.00');
});

it('cannot transfer the same remaining source stock to two warehouses concurrently', function () {
    $stock = $this->raceLedger->catalog->account($this->raceBook, '1300', 'Inventory', AccountType::Asset);
    $clearing = $this->raceLedger->catalog->account($this->raceBook, '2300', 'Transfer clearing', AccountType::Liability);
    $source = $this->raceLedger->inventory->pool($this->raceBook, 'MAIN', 'SKU', $stock, ValuationMethod::MovingAverage);
    $first = $this->raceLedger->inventory->pool($this->raceBook, 'FIRST', 'SKU', $stock, ValuationMethod::Fifo);
    $second = $this->raceLedger->inventory->pool($this->raceBook, 'SECOND', 'SKU', $stock, ValuationMethod::Fifo);
    $this->raceLedger->inventory->receive($this->raceBook, $source, 'receive', '2026-01-01', new Quantity('100'), Money::of('100', $this->kes), $this->raceCash, 'Receive');
    $command = ['operation' => 'transfer', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'source' => $source, 'destination' => $first,
        'key' => 'transfer-a', 'quantity' => '80', 'clearing' => $clearing, 'amount' => '0'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'destination' => $second, 'key' => 'transfer-b']]);
    expect(array_column($results, 'ok'))->toContain(true, false);
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->inventory->balance($snapshot, $source)->quantity->decimal())->toBe('20.000000000000');
    $total = $this->raceLedger->inventory->balance($snapshot, $first)->value->plus($this->raceLedger->inventory->balance($snapshot, $second)->value);
    expect($total->decimal())->toBe('80.00');
});

it('deduplicates a concurrent monetary valuation without duplicating its exchange effect', function () {
    $usd = Currency::iso('USD');
    $bank = $this->raceLedger->catalog->account($this->raceBook, '1001', 'USD bank', AccountType::Asset, $usd, cashFlow: 'cash');
    $loss = $this->raceLedger->catalog->account($this->raceBook, '5100', 'Exchange loss', AccountType::Expense);
    $this->raceLedger->monetaryAccounts->register($this->raceBook, $bank, 'monetary-cash:v1');
    $rate = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'initial', Rounding::HalfEven);
    $amount = Money::of('100', $usd);
    $this->raceLedger->journals->post(new JournalRequest($this->raceBook, 'fund', '2026-01-01', 'Fund bank', [
        new Posting($bank, Side::Debit, $amount, $rate), new Posting($this->raceIncome, Side::Credit, $rate->convert($amount)),
    ]));
    $command = ['operation' => 'monetary', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'account' => $bank,
        'key' => 'close', 'gain' => $this->raceIncome, 'loss' => $loss, 'amount' => '0'];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, $command]);
    expect(array_column($results, 'ok'))->toBe([true, true]);
    expect($results[0]['id'])->toBe($results[1]['id']);
    $snapshot = $this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31');
    expect($this->raceLedger->reports->generalLedger($snapshot))->toHaveCount(4);
    expect($this->raceLedger->reports->cashFlow($snapshot, '2026-01-01')->exchange->decimal())->toBe('1000.00');
});

it('prevents concurrent supplier returns from exceeding the original receipt', function (ValuationMethod $method) {
    $stock = $this->raceLedger->catalog->account($this->raceBook, '1300', 'Inventory', AccountType::Asset);
    $clearing = $this->raceLedger->catalog->account($this->raceBook, '2300', 'Supplier clearing', AccountType::Liability);
    $variance = $this->raceLedger->catalog->account($this->raceBook, '5000', 'Purchase variance', AccountType::Expense);
    $pool = $this->raceLedger->inventory->pool($this->raceBook, 'SUPPLIER', 'SKU', $stock, $method);
    $receipt = $this->raceLedger->inventory->receive($this->raceBook, $pool, 'purchase', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $clearing, 'Purchase');
    $before = $this->raceLedger->events->cutoff($this->raceBook);
    $command = ['operation' => 'supplier_return', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'return-a', 'receipt' => $receipt->id,
        'quantity' => '8', 'amount' => '0', 'counter' => $clearing, 'variance' => $variance];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => 'return-b']]);
    expect(array_column($results, 'ok'))->toContain(true, false);
    $balance = $this->raceLedger->inventory->balance($this->raceLedger->reports->snapshot($this->raceBook, '2026-12-31'), $pool);
    expect($balance->quantity->decimal())->toBe('2.000000000000');
    expect($balance->value->decimal())->toBe('20.00');
    $events = $this->raceLedger->events->page($this->raceBook, $before);
    expect(array_map(fn (AuditEvent $event): string => $event->tableName, $events))->toBe(['ledger_journals', 'ledger_inventory_moves']);
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('records concurrent committed journals in a contiguous book event order', function () {
    $before = $this->raceLedger->events->cutoff($this->raceBook);
    $command = ['operation' => 'post', 'tenant' => $this->raceScope->tenantId, 'book' => $this->raceBook, 'key' => 'event-a', 'amount' => '10', 'cash' => $this->raceCash, 'income' => $this->raceIncome];
    $results = Race::run($this->raceDb, $this->raceBook, [$command, [...$command, 'key' => 'event-b']]);
    expect(array_column($results, 'ok'))->toBe([true, true]);
    $events = $this->raceLedger->events->page($this->raceBook, $before);
    expect($events)->toHaveCount(2);
    expect($events[0]->sequence)->toBe((string) ((int) $before + 1));
    expect($events[1]->sequence)->toBe((string) ((int) $before + 2));
    expect(array_map(fn (AuditEvent $event): string => $event->tableName, $events))->toBe(['ledger_journals', 'ledger_journals']);
    $firstPage = $this->raceLedger->events->page($this->raceBook, $before, limit: 1);
    $secondPage = $this->raceLedger->events->page($this->raceBook, $firstPage[0]->sequence, limit: 1);
    expect($firstPage[0]->auditId)->not->toBe($secondPage[0]->auditId);
});
