<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Scheduling\Schedule;
use Noria\Ledger\Storage\ScopedDatabase;
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
    expect(array_column($events, 'table_name'))->toBe(['ledger_journals', 'ledger_inventory_moves']);
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
    expect(array_column($events, 'table_name'))->toBe(['ledger_journals', 'ledger_journals']);
    $firstPage = $this->raceLedger->events->page($this->raceBook, $before, limit: 1);
    $secondPage = $this->raceLedger->events->page($this->raceBook, $firstPage[0]->sequence, limit: 1);
    expect($firstPage[0]->audit_id)->not->toBe($secondPage[0]->audit_id);
});
