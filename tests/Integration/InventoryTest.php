<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Money\Money;

beforeEach(function () {
    $this->stock = $this->ledger->catalog->account($this->book, '1300', 'Inventory', AccountType::Asset);
    $this->clearing = $this->ledger->catalog->account($this->book, '2300', 'Goods received clearing', AccountType::Liability);
    $this->cogs = $this->ledger->catalog->account($this->book, '5000', 'Cost of goods', AccountType::Expense);
});

it('values perpetual inventory using the selected exact cost method', function (ValuationMethod $method, string $cost, string $remaining) {
    $pool = $this->ledger->inventory->pool($this->book, 'STORE:SKU-1', 'SKU-1', $this->stock, $method);
    $first = $this->ledger->inventory->receive($this->book, $pool, 'receive-1', '2026-01-01', new Quantity('10'), Money::of('1000', $this->kes), $this->clearing, 'Ten units at 100');
    $this->ledger->inventory->receive($this->book, $pool, 'receive-2', '2026-01-02', new Quantity('20'), Money::of('2400', $this->kes), $this->clearing, 'Twenty units at 120');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $issue = $this->ledger->inventory->issue($this->book, $pool, 'issue-1', '2026-01-03', new Quantity('15'), $this->cogs, 'Issue fifteen units');
    expect($issue->value->decimal())->toBe($cost);
    expect($this->ledger->inventory->issue($this->book, $pool, 'issue-1', '2026-01-03', new Quantity('15'), $this->cogs, 'Issue fifteen units')->id)->toBe($issue->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balance = $this->ledger->inventory->balance($snapshot, $pool);
    expect($balance->quantity->decimal())->toBe('15.000000000000');
    expect($balance->value->decimal())->toBe($remaining);
    expect($this->ledger->inventory->balance($before, $pool)->value->decimal())->toBe('3400.00');
    $this->ledger->inventory->reverse($this->book, $issue->id, 'reverse-issue', '2026-01-04', 'Correct stock issue');
    expect($this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool)->value->decimal())->toBe('3400.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([[ValuationMethod::Fifo, '-1600.00', '1800.00'], [ValuationMethod::MovingAverage, '-1700.00', '1700.00']]);

it('does not bypass retirement controls when a stock receipt has zero value', function (string $reference) {
    $this->ledger->catalog->dimensionValue($this->entity, 'department', 'west');
    $pool = $this->ledger->inventory->pool($this->book, 'RETIRED', 'SKU', $this->stock, ValuationMethod::Fifo, ['department' => 'west']);
    match ($reference) {
        'account' => $this->ledger->catalog->deleteAccount($this->book, $this->stock),
        'inactive-account' => $this->ledger->catalog->setAccountActive($this->book, $this->stock, false),
        'inactive-counter' => $this->ledger->catalog->setAccountActive($this->book, $this->clearing, false),
        'dimension' => $this->ledger->catalog->deleteDimensionValue($this->entity, 'department', 'west'),
        'book' => $this->ledger->catalog->deleteBook($this->book),
    };
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    try {
        $this->ledger->inventory->receive($this->book, $pool, 'zero-receipt', '2026-01-01', new Quantity('1'), Money::of('0', $this->kes), $this->clearing, 'Zero-value receipt');
        test()->fail('Zero-value movements still require live catalog references.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('soft-deleted');
    }
    $after = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($after->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->inventory->balance($after, $pool)->quantity->value->isZero())->toBeTrue();
})->with(['account', 'inactive-account', 'inactive-counter', 'dimension', 'book']);

it('conserves fractional quantities and the last cost cent', function (ValuationMethod $method) {
    $pool = $this->ledger->inventory->pool($this->book, 'FRACTIONAL', 'SKU', $this->stock, $method);
    $this->ledger->inventory->receive($this->book, $pool, 'receipt', '2026-01-01', new Quantity('0.3'), Money::of('0.01', $this->kes), $this->clearing, 'Fractional stock');
    $cost = Money::of('0', $this->kes);

    for ($i = 0; $i < 3; $i++) {
        $issue = $this->ledger->inventory->issue($this->book, $pool, 'issue-'.$i, '2026-01-02', new Quantity('0.1'), $this->cogs, 'Fractional issue');
        $cost = $cost->plus($issue->value);
    }

    expect($cost->decimal())->toBe('-0.01');
    $balance = $this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool);
    expect($balance->value->decimal())->toBe('0.00');
    expect($balance->quantity->value->isZero())->toBeTrue();
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('allocates landed costs over FIFO layers without losing value', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'LANDED', 'SKU', $this->stock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $pool, 'r1', '2026-01-01', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'First lot');
    $this->ledger->inventory->receive($this->book, $pool, 'r2', '2026-01-02', new Quantity('1'), Money::of('2', $this->kes), $this->clearing, 'Second lot');
    $this->ledger->inventory->adjustValue($this->book, $pool, 'freight', '2026-01-03', Money::of('0.01', $this->kes), $this->clearing, 'Landed freight cost');
    $issue = $this->ledger->inventory->issue($this->book, $pool, 'sell-all', '2026-01-04', new Quantity('2'), $this->cogs, 'Issue all stock');
    expect($issue->value->decimal())->toBe('-3.01');
});

it('rejects negative stock and backdated valuation changes', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'STORE', 'SKU', $this->stock, ValuationMethod::MovingAverage);
    $this->ledger->inventory->receive($this->book, $pool, 'r1', '2026-01-02', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'Receive');

    try {
        $this->ledger->inventory->issue($this->book, $pool, 'too-many', '2026-01-03', new Quantity('2'), $this->cogs, 'Invalid issue');
        test()->fail('Negative inventory must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('Insufficient inventory');
    }

    $this->ledger->inventory->receive($this->book, $pool, 'backdated', '2026-01-01', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'Backdated receipt');
})->throws(QueryException::class, 'controlled reposting');

it('writes stock down to zero and restores exact layer values on reversal', function (ValuationMethod $method) {
    $pool = $this->ledger->inventory->pool($this->book, 'WRITEDOWN', 'SKU', $this->stock, $method);
    $first = $this->ledger->inventory->receive($this->book, $pool, 'r1', '2026-01-01', new Quantity('1'), Money::of('0.01', $this->kes), $this->clearing, 'First lot');
    $second = $this->ledger->inventory->receive($this->book, $pool, 'r2', '2026-01-02', new Quantity('2'), Money::of('0.02', $this->kes), $this->clearing, 'Second lot');
    $adjustment = $this->ledger->inventory->adjustValue($this->book, $pool, 'write-down', '2026-01-03', Money::of('-0.03', $this->kes), $this->cogs, 'Net realizable value');
    $issue = $this->ledger->inventory->issue($this->book, $pool, 'free-issue', '2026-01-04', new Quantity('1'), $this->cogs, 'Issue written-down goods');
    expect($issue->journalId)->toBeNull();
    expect($issue->value->minor->isZero())->toBeTrue();
    $this->ledger->inventory->reverse($this->book, $issue->id, 'restore-issue', '2026-01-05', 'Restore goods');
    $this->ledger->inventory->reverse($this->book, $adjustment->id, 'restore-value', '2026-01-05', 'Restore original cost');
    $this->ledger->inventory->reverse($this->book, $second->id, 'restore-second', '2026-01-05', 'Correct receipt');
    $this->ledger->inventory->reverse($this->book, $first->id, 'restore-first', '2026-01-05', 'Correct receipt');
    $balance = $this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool);
    expect($balance->quantity->value->isZero())->toBeTrue();
    expect($balance->value->minor->isZero())->toBeTrue();
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('rejects removing a receipt that later issues consumed', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'DEPENDENCY', 'SKU', $this->stock, ValuationMethod::Fifo);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'r1', '2026-01-01', new Quantity('2'), Money::of('2', $this->kes), $this->clearing, 'Receive');
    $this->ledger->inventory->issue($this->book, $pool, 'issue', '2026-01-02', new Quantity('1'), $this->cogs, 'Issue');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');

    try {
        $this->ledger->inventory->reverse($this->book, $receipt->id, 'invalid', '2026-01-03', 'Invalid reversal');
        test()->fail('Dependent stock must be restored first.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('reverse order');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-01-31')->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->inventory->balance($before, $pool)->value->decimal())->toBe('1.00');
});

it('rejects even zero-value stock movements in a closed period', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'FREE', 'SKU', $this->stock, ValuationMethod::Fifo);
    $this->ledger->periods->close($this->book, $this->period);
    $this->ledger->inventory->receive($this->book, $pool, 'free-receipt', '2026-01-01', new Quantity('1'), Money::of('0', $this->kes), $this->clearing, 'Free goods');
})->throws(QueryException::class, 'open accounting period');

it('rejects a write-down exceeding remaining stock value', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'NEGATIVE', 'SKU', $this->stock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $pool, 'r1', '2026-01-01', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'Receive');
    $this->ledger->inventory->adjustValue($this->book, $pool, 'excess', '2026-01-02', Money::of('-1.01', $this->kes), $this->cogs, 'Invalid write-down');
})->throws(QueryException::class, 'Invalid stock value adjustment');

it('manufactures finished goods from components at exact consumed cost', function (ValuationMethod $method) {
    $materials = $this->ledger->inventory->pool($this->book, 'MATERIALS', 'RAW', $this->stock, $method);
    $finished = $this->ledger->inventory->pool($this->book, 'FINISHED', 'UNIT', $this->stock, ValuationMethod::Fifo);
    $wip = $this->ledger->catalog->account($this->book, '1301', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Raw materials');
    $production = $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'produce', '2026-01-02', 'Assemble one unit');
    expect($production->output->value->decimal())->toBe('40.00');
    expect($this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'produce', '2026-01-02', 'Assemble one unit')->id)->toBe($production->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $materials)->value->decimal())->toBe('60.00');
    expect($this->ledger->inventory->balance($snapshot, $finished)->value->decimal())->toBe('40.00');
    expect(collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId')[$wip]->closing->decimal())->toBe('0.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $this->ledger->inventory->reverseProduction($this->book, $production->id, 'unproduce', '2026-01-03', 'Correct production');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $materials)->value->decimal())->toBe('100.00');
    expect($this->ledger->inventory->balance($snapshot, $finished)->value->decimal())->toBe('0.00');
    expect($this->ledger->inventory->balance($snapshot, $finished)->quantity->value->isZero())->toBeTrue();
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('conserves multi-component production cost across the WIP clearing account', function () {
    $first = $this->ledger->inventory->pool($this->book, 'COMP-A', 'A', $this->stock, ValuationMethod::Fifo);
    $secondStock = $this->ledger->catalog->account($this->book, '1301', 'Other component stock', AccountType::Asset);
    $second = $this->ledger->inventory->pool($this->book, 'COMP-B', 'B', $secondStock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'GOOD', 'GOOD', $this->stock, ValuationMethod::Fifo);
    $wip = $this->ledger->catalog->account($this->book, '1302', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $first, 'a', '2026-01-01', new Quantity('0.3'), Money::of('0.01', $this->kes), $this->clearing, 'Component A');
    $this->ledger->inventory->receive($this->book, $second, 'b', '2026-01-01', new Quantity('0.2'), Money::of('0.02', $this->kes), $this->clearing, 'Component B');
    $production = $this->ledger->inventory->produce($this->book, [
        ['pool' => $first, 'quantity' => new Quantity('0.3')], ['pool' => $second, 'quantity' => new Quantity('0.1')],
    ], $finished, new Quantity('1'), $wip, 'assemble', '2026-01-02', 'Assemble goods');
    expect($production->output->value->decimal())->toBe('0.02');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $finished)->value->decimal())->toBe('0.02');
    expect(collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId')[$wip]->closing->decimal())->toBe('0.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
});

it('rejects production that does not conserve component cost and non-WIP counter accounts', function (string $wipType, string $message) {
    $materials = $this->ledger->inventory->pool($this->book, 'BAD-MAT', 'RAW', $this->stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'BAD-GOOD', 'UNIT', $this->stock, ValuationMethod::Fifo);
    $wip = match ($wipType) {
        'cash' => $this->ledger->catalog->account($this->book, '1001', 'Not WIP', AccountType::Asset, cashFlow: 'cash'),
        'income' => $this->ledger->catalog->account($this->book, '4100', 'Not WIP', AccountType::Income),
    };
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Raw materials');
    $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'produce', '2026-01-02', 'Invalid WIP');
})->with([['cash', 'non-cash balance-sheet WIP'], ['income', 'non-cash balance-sheet WIP']])->throws(QueryException::class);

it('rejects one-sided reversal of a production movement', function () {
    $materials = $this->ledger->inventory->pool($this->book, 'LINKED-MAT', 'RAW', $this->stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'LINKED-GOOD', 'UNIT', $this->stock, ValuationMethod::Fifo);
    $wip = $this->ledger->catalog->account($this->book, '1301', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Raw materials');
    $production = $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'produce', '2026-01-02', 'Assemble');
    $this->ledger->inventory->reverse($this->book, $production->output->id, 'one-sided', '2026-01-03', 'Invalid one-sided reversal');
})->throws(QueryException::class, 'production movements together');

it('absorbs explicitly allocated conversion costs from WIP and restores them on correction', function () {
    $materials = $this->ledger->inventory->pool($this->book, 'CONVERSION-MAT', 'RAW', $this->stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'CONVERSION-GOOD', 'UNIT', $this->stock, ValuationMethod::Fifo);
    $wip = $this->ledger->catalog->account($this->book, '1301', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Materials');
    $this->ledger->journals->post(new JournalRequest($this->book, 'labour', '2026-01-02', 'Recognize conversion costs', [
        new Posting($wip, Side::Debit, Money::of('15', $this->kes)),
        new Posting($this->clearing, Side::Credit, Money::of('15', $this->kes)),
    ]));
    $production = $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'convert', '2026-01-03', 'Assemble', Money::of('15', $this->kes));
    expect($production->output->value->decimal())->toBe('55.00');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect(collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId')[$wip]->closing->decimal())->toBe('0.00');
    $reverse = $this->ledger->inventory->reverseProduction($this->book, $production->id, 'correct-conversion', '2026-01-04', 'Correct assembly');
    expect($reverse->output->value->decimal())->toBe('-55.00');
    expect($this->ledger->inventory->reverseProduction($this->book, $production->id, 'correct-conversion', '2026-01-04', 'Correct assembly')->id)->toBe($reverse->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect(collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId')[$wip]->closing->decimal())->toBe('15.00');
    expect($this->ledger->inventory->balance($snapshot, $materials)->value->decimal())->toBe('100.00');
    expect($this->ledger->inventory->balance($snapshot, $finished)->value->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('produces and reverses fractional or written-down components with zero consumed value', function (ValuationMethod $method) {
    $materials = $this->ledger->inventory->pool($this->book, 'ZERO-MAT', 'RAW', $this->stock, $method);
    $finished = $this->ledger->inventory->pool($this->book, 'ZERO-GOOD', 'UNIT', $this->stock, $method);
    $wip = $this->ledger->catalog->account($this->book, '1301', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('0.01', $this->kes), $this->clearing, 'Fractional materials');
    $production = $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('1')]], $finished, new Quantity('1'), $wip, 'zero', '2026-01-02', 'Assemble');
    expect($production->output->value->decimal())->toBe('0.00');
    expect($production->output->journalId)->toBeNull();
    $this->ledger->inventory->reverseProduction($this->book, $production->id, 'undo-zero', '2026-01-03', 'Correct assembly');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $materials)->quantity->decimal())->toBe('10.000000000000');
    expect($this->ledger->inventory->balance($snapshot, $materials)->value->decimal())->toBe('0.01');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('rejects unrecognized conversion costs without consuming components', function () {
    $materials = $this->ledger->inventory->pool($this->book, 'UNFUNDED-MAT', 'RAW', $this->stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'UNFUNDED-GOOD', 'UNIT', $this->stock, ValuationMethod::Fifo);
    $wip = $this->ledger->catalog->account($this->book, '1301', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Materials');
    try {
        $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'unfunded', '2026-01-02', 'Invalid assembly', Money::of('15', $this->kes));
        test()->fail('Unrecognized conversion costs must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('exceed recognized WIP');
    }
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $materials)->value->decimal())->toBe('100.00');
    expect($this->ledger->inventory->balance($snapshot, $finished)->quantity->value->isZero())->toBeTrue();
});

it('keeps production movements outside standalone customer and supplier returns', function (bool $supplier) {
    $materials = $this->ledger->inventory->pool($this->book, 'RETURN-MAT', 'RAW', $this->stock, ValuationMethod::Fifo);
    $finished = $this->ledger->inventory->pool($this->book, 'RETURN-GOOD', 'UNIT', $this->stock, ValuationMethod::Fifo);
    $wip = $this->ledger->catalog->account($this->book, '1301', 'Work in progress', AccountType::Asset);
    $this->ledger->inventory->receive($this->book, $materials, 'materials', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Materials');
    $production = $this->ledger->inventory->produce($this->book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'), $wip, 'assembly', '2026-01-02', 'Assemble');
    if ($supplier) {
        $this->ledger->inventory->returnReceipt($this->book, $production->output->id, 'return-production', '2026-01-03', new Quantity('1'), $this->clearing, $this->cogs, 'Invalid supplier return');
    } else {
        $this->ledger->inventory->returnIssue($this->book, $production->inputs[0]->id, 'return-component', '2026-01-03', new Quantity('1'), 'Invalid customer return');
    }
})->with([false, true])->throws(QueryException::class);

it('costs specific-identification issues from the selected lots and reports lots by expiry', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'PHARMACY:AMOX', 'AMOX-500', $this->stock, ValuationMethod::Specific);
    $this->ledger->inventory->receive($this->book, $pool, 'lot-a', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Batch A', 'A-2026', '2026-06-30');
    $this->ledger->inventory->receive($this->book, $pool, 'lot-b', '2026-01-02', new Quantity('10'), Money::of('150', $this->kes), $this->clearing, 'Batch B', 'B-2026', '2026-03-31');
    $this->ledger->inventory->receive($this->book, $pool, 'lot-a2', '2026-01-03', new Quantity('5'), Money::of('60', $this->kes), $this->clearing, 'More of batch A', 'A-2026', '2026-06-30');

    $issue = $this->ledger->inventory->issue($this->book, $pool, 'dispense', '2026-01-05', new Quantity('14'), $this->cogs, 'Dispense', ['B-2026' => new Quantity('4'), 'A-2026' => new Quantity('10')]);
    expect($issue->value->decimal())->toBe('-160.00')
        ->and($this->ledger->inventory->issue($this->book, $pool, 'dispense', '2026-01-05', new Quantity('14'), $this->cogs, 'Dispense', ['A-2026' => new Quantity('10'), 'B-2026' => new Quantity('4')])->id)->toBe($issue->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect(array_map(fn ($lot) => [$lot['lot'], $lot['expires_on'], $lot['quantity']->decimal(), $lot['value']->decimal()], $this->ledger->inventory->lots($snapshot, $pool)))
        ->toBe([['B-2026', '2026-03-31', '6.000000000000', '90.00'], ['A-2026', '2026-06-30', '5.000000000000', '60.00']])
        ->and(fn () => $this->ledger->inventory->issue($this->book, $pool, 'unselected', '2026-01-06', new Quantity('1'), $this->cogs, 'No lot'))->toThrow(QueryException::class, 'require a lot selection')
        ->and(fn () => $this->ledger->inventory->issue($this->book, $pool, 'too-many', '2026-01-06', new Quantity('7'), $this->cogs, 'Too many', ['B-2026' => new Quantity('7')]))->toThrow(QueryException::class, 'Insufficient quantity in lot B-2026')
        ->and(fn () => $this->ledger->inventory->receive($this->book, $pool, 'no-lot', '2026-01-06', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'Missing lot'))->toThrow(QueryException::class, 'require a lot');

    $single = $this->ledger->inventory->issue($this->book, $pool, 'single', '2026-01-07', new Quantity('2'), $this->cogs, 'Dispense', ['B-2026' => new Quantity('2')]);
    $return = $this->ledger->inventory->returnIssue($this->book, $single->id, 'return-single', '2026-01-08', new Quantity('1'), 'Unused');
    expect($return->value->decimal())->toBe('15.00')
        ->and(fn () => $this->ledger->inventory->returnIssue($this->book, $issue->id, 'return-mixed', '2026-01-08', new Quantity('1'), 'Mixed lots'))->toThrow(QueryException::class, 'exactly one lot');
    $lots = collect($this->ledger->inventory->lots($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool))->keyBy('lot');
    expect($lots['B-2026']['quantity']->decimal())->toBe('5.000000000000')->and($lots['B-2026']['value']->decimal())->toBe('75.00');
    $this->ledger->inventory->reverse($this->book, $return->id, 'reverse-return', '2026-01-09', 'Mistake');
    expect(collect($this->ledger->inventory->lots($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool))->keyBy('lot')['B-2026']['quantity']->decimal())->toBe('4.000000000000')
        ->and(collect($this->ledger->inventory->lots($snapshot, $pool))->keyBy('lot')['B-2026']['quantity']->decimal())->toBe('6.000000000000');
});

it('tracks serial numbers one unit at a time in serialized pools', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'STORE:LAPTOP', 'LAPTOP-14', $this->stock, ValuationMethod::Specific, serialized: true);
    $this->ledger->inventory->receive($this->book, $pool, 'sn-1', '2026-01-01', new Quantity('1'), Money::of('900', $this->kes), $this->clearing, 'Laptop', 'SN-001');
    $this->ledger->inventory->receive($this->book, $pool, 'sn-2', '2026-01-01', new Quantity('1'), Money::of('950', $this->kes), $this->clearing, 'Laptop', 'SN-002');
    expect(fn () => $this->ledger->inventory->receive($this->book, $pool, 'sn-dup', '2026-01-02', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'Duplicate', 'SN-001'))
        ->toThrow(QueryException::class, 'enters stock once')
        ->and(fn () => $this->ledger->inventory->receive($this->book, $pool, 'sn-two', '2026-01-02', new Quantity('2'), Money::of('1', $this->kes), $this->clearing, 'Two', 'SN-003'))
        ->toThrow(QueryException::class, 'enters stock once')
        ->and(fn () => $this->ledger->inventory->pool($this->book, 'STORE:BAD', 'BAD', $this->stock, ValuationMethod::Fifo, serialized: true))->toThrow(LedgerException::class, 'specific identification');

    $sale = $this->ledger->inventory->issue($this->book, $pool, 'sell', '2026-01-03', new Quantity('1'), $this->cogs, 'Sold', ['SN-002' => new Quantity('1')]);
    expect($sale->value->decimal())->toBe('-950.00')
        ->and(array_column($this->ledger->inventory->lots($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool), 'lot'))->toBe(['SN-001'])
        ->and(fn () => $this->ledger->inventory->receive($this->book, $this->ledger->inventory->pool($this->book, 'STORE:FIFO', 'X', $this->stock, ValuationMethod::Fifo), 'fifo-lot', '2026-01-04', new Quantity('1'), Money::of('1', $this->kes), $this->clearing, 'Lot in FIFO', 'L1'))
        ->toThrow(QueryException::class, 'specific-identification pool');
});
