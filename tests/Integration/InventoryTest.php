<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
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
})->with(['account', 'dimension', 'book']);

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
