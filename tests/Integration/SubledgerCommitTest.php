<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Access\Scope;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\AllocationRequest;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Assets\AssetRequest;
use NoriaLabs\Ledger\Inventory\Quantity;
use NoriaLabs\Ledger\Inventory\ValuationMethod;
use NoriaLabs\Ledger\Ledger;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Money\Rounding;
use NoriaLabs\Ledger\Scheduling\Schedule;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Tests\Support\Database;
use NoriaLabs\Ledger\Tests\Support\PermitAll;

beforeEach(function () {
    $this->committedConnection = Database::connection();
    $this->committedScope = new Scope((string) Str::uuid(), 'committed-subledgers');
    $this->ledger = (new Ledger($this->committedConnection, new PermitAll))->for($this->committedScope);
    $this->entity = $this->ledger->catalog->entity('Committed accounting');
    $this->book = $this->ledger->catalog->book($this->entity, 'Statutory', $this->kes);
    $this->ledger->periods->open($this->book, '2026', '2026-01-01', '2026-12-31');
    $this->cash = $this->ledger->catalog->account($this->book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
    $this->income = $this->ledger->catalog->account($this->book, '4000', 'Income', AccountType::Income);
});

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
    $this->ledger->assets->reverse($this->book, $event, 'restore', '2026-02-01', 'Cancel sale');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->assets->position($snapshot, $asset)->carrying->decimal())->toBe('50.00');

    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'detached', '2026-02-01', 'Untracked asset adjustment', [
            new Posting($cost, Side::Debit, Money::of('1', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('1', $this->kes)),
        ]));
        test()->fail('Untracked asset postings must fail on commit.');
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
        $this->ledger->journals->reverse($this->book, $issue->journalId, 'detached-reversal', '2026-01-04', 'Invalid correction');
        test()->fail('A stock journal cannot be detached from its movement.');
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
    $revaluation = $this->ledger->revaluations->revalue($this->book, $original->id, 'revalue', '2026-01-31', $closing, $gain, $loss, 'Closing revaluation');

    try {
        $this->ledger->journals->reverse($this->book, $revaluation->id, 'detached-reversal', '2026-02-01', 'Invalid revaluation correction');
        test()->fail('A revaluation journal requires a carrying-value correction event.');
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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$gain]->closing->decimal())->toBe('0.00');
    expect($balances[$realized]->closing->decimal())->toBe('-1500.00');
    $this->ledger->settlements->release($this->book, $allocation, 'release', '2026-02-03', 'Release settlement');
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
            'id' => (string) Str::uuid(), 'book_id' => $this->book, 'pool_id' => $source, 'idempotency_key' => 'extra', 'fingerprint' => str_repeat('0', 64),
            'effective_on' => '2026-01-03', 'kind' => 'receipt', 'quantity' => '1', 'value_minor' => '0', 'counter_account_id' => $clearing,
            'journal_id' => null, 'plan' => '[]', 'transfer_id' => $transfer->id, 'reason' => 'Invalid extra transfer member',
        ]), $this->book);
        test()->fail('Extra transfer members must fail at commit.');
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
        $this->ledger->journals->reverse($this->book, $valuation->journalId, 'detached', '2026-02-01', 'Invalid journal-only correction');
        test()->fail('Monetary evidence must be corrected with its journal.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('corresponding valuation evidence');
    }

    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'backdated', '2026-01-15', 'Invalid late source posting', [
            new Posting($bank, Side::Debit, $amount, $rate), new Posting($this->income, Side::Credit, $rate->convert($amount)),
        ]));
        test()->fail('Backdated source movements must not silently change an earlier valuation.');
    } catch (PDOException $exception) {
        expect($exception->getMessage())->toContain('silently backdate');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($before->throughSequence);
    $this->ledger->monetaryAccounts->reverse($this->book, $valuation->id, 'restore', '2026-02-01', 'Reverse closing valuation');
    expect($this->ledger->reports->cashFlow($this->ledger->reports->snapshot($this->book, '2026-12-31'), '2026-01-01')->exchange->decimal())->toBe('0.00');
    expect($this->committedConnection->table('ledger_monetary_valuations')->count())->toBe(0);
});
