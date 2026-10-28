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
    $this->otherStock = $this->ledger->catalog->account($this->book, '1301', 'Other store inventory', AccountType::Asset);
    $this->clearing = $this->ledger->catalog->account($this->book, '2300', 'Stock clearing', AccountType::Liability);
    $this->cogs = $this->ledger->catalog->account($this->book, '5000', 'Cost of goods', AccountType::Expense);
});

it('returns original issue cost after later purchases change current valuation', function (ValuationMethod $method) {
    $pool = $this->ledger->inventory->pool($this->book, 'MAIN', 'SKU', $this->stock, $method);
    $this->ledger->inventory->receive($this->book, $pool, 'r1', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Original lot');
    $issue = $this->ledger->inventory->issue($this->book, $pool, 'sell', '2026-01-02', new Quantity('4'), $this->cogs, 'Original issue');
    $this->ledger->inventory->receive($this->book, $pool, 'r2', '2026-01-03', new Quantity('100'), Money::of('2000', $this->kes), $this->clearing, 'Higher current cost');
    $returned = $this->ledger->inventory->returnIssue($this->book, $issue->id, 'return-two', '2026-01-04', new Quantity('2'), 'Customer return');
    expect($returned->value->decimal())->toBe('20.00');
    expect($this->ledger->inventory->returnIssue($this->book, $issue->id, 'return-two', '2026-01-04', new Quantity('2'), 'Customer return')->id)->toBe($returned->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe('2080.00');
    $this->ledger->inventory->reverse($this->book, $returned->id, 'correct-return', '2026-01-05', 'Returned goods recorded incorrectly');
    expect($this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool)->value->decimal())->toBe('2060.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('conserves original cost through fractional returns and rejects over-returning', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'FRACTIONAL', 'SKU', $this->stock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $pool, 'receive', '2026-01-01', new Quantity('0.3'), Money::of('0.01', $this->kes), $this->clearing, 'Fractional lot');
    $issue = $this->ledger->inventory->issue($this->book, $pool, 'issue', '2026-01-02', new Quantity('0.3'), $this->cogs, 'Issue all');
    $total = Money::of('0', $this->kes);

    for ($index = 0; $index < 3; $index++) {
        $returned = $this->ledger->inventory->returnIssue($this->book, $issue->id, 'return-'.$index, '2026-01-03', new Quantity('0.1'), 'Partial return');
        $total = $total->plus($returned->value);
    }

    expect($total->decimal())->toBe('0.01');
    expect($this->ledger->inventory->issue($this->book, $pool, 'issue-returns', '2026-01-04', new Quantity('0.3'), $this->cogs, 'Issue returned layers')->value->decimal())->toBe('-0.01');
    $this->ledger->inventory->returnIssue($this->book, $issue->id, 'excess', '2026-01-05', new Quantity('0.000000000001'), 'Excess return');
})->throws(QueryException::class, 'exceeds the original issue');

it('restores the original expense dimension when returning to another warehouse', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'department', 'pharmacy');
    $this->ledger->catalog->dimensionValue($this->entity, 'department', 'quarantine');
    $source = $this->ledger->inventory->pool($this->book, 'PHARMACY', 'SKU', $this->stock, ValuationMethod::Fifo, ['department' => 'pharmacy']);
    $destination = $this->ledger->inventory->pool($this->book, 'QUARANTINE', 'SKU', $this->otherStock, ValuationMethod::Fifo, ['department' => 'quarantine']);
    $this->ledger->inventory->receive($this->book, $source, 'receive', '2026-01-01', new Quantity('1'), Money::of('10', $this->kes), $this->clearing, 'Receive');
    $issue = $this->ledger->inventory->issue($this->book, $source, 'issue', '2026-01-02', new Quantity('1'), $this->cogs, 'Issue');
    $returned = $this->ledger->inventory->returnIssue($this->book, $issue->id, 'return', '2026-01-03', new Quantity('1'), 'Inspect returned goods', $destination);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01', ['department' => 'pharmacy'])['expenses']->decimal())->toBe('0.00');
    expect($this->ledger->inventory->balance($snapshot, $destination)->value->decimal())->toBe('10.00');

    try {
        $this->ledger->inventory->reverse($this->book, $issue->id, 'invalid-reverse', '2026-01-04', 'Invalid correction');
        $this->fail('A cross-pool return must protect its original issue.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('dependent stock returns');
    }

    $this->ledger->inventory->reverse($this->book, $returned->id, 'restore-return', '2026-01-04', 'Correct return');
    $this->ledger->inventory->reverse($this->book, $issue->id, 'restore-issue', '2026-01-04', 'Correct issue');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('moves exact cost between pools and reverses the complete transfer', function (ValuationMethod $method) {
    $source = $this->ledger->inventory->pool($this->book, 'MAIN', 'SKU', $this->stock, $method);
    $destination = $this->ledger->inventory->pool($this->book, 'OTHER', 'SKU', $this->otherStock, ValuationMethod::MovingAverage);
    $this->ledger->inventory->receive($this->book, $source, 'receive', '2026-01-01', new Quantity('0.3'), Money::of('0.01', $this->kes), $this->cash, 'Fractional stock');
    $transfer = $this->ledger->inventory->transfer($this->book, $source, $destination, 'move-all', '2026-01-02', new Quantity('0.3'), $this->clearing, 'Move between stores');
    expect($transfer->receipt->value->decimal())->toBe('0.01');
    expect($transfer->issue->value->decimal())->toBe('-0.01');
    expect($this->ledger->inventory->transfer($this->book, $source, $destination, 'move-all', '2026-01-02', new Quantity('0.3'), $this->clearing, 'Move between stores')->id)->toBe($transfer->id);
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($before, $source)->value->decimal())->toBe('0.00');
    expect($this->ledger->inventory->balance($before, $destination)->value->decimal())->toBe('0.01');

    try {
        $this->ledger->inventory->reverse($this->book, $transfer->issue->id, 'one-side', '2026-01-03', 'Invalid one-sided reversal');
        $this->fail('Transfer movements must be reversed together.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('both transfer movements');
    }

    $reversal = $this->ledger->inventory->reverseTransfer($this->book, $transfer->id, 'restore-transfer', '2026-01-03', 'Transfer entered incorrectly');
    expect($this->ledger->inventory->reverseTransfer($this->book, $transfer->id, 'restore-transfer', '2026-01-03', 'Transfer entered incorrectly')->id)->toBe($reversal->id);
    $after = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($after, $source)->value->decimal())->toBe('0.01');
    expect($this->ledger->inventory->balance($after, $destination)->value->decimal())->toBe('0.00');
    $balances = byAccount($this->ledger->reports->trialBalance($after));
    expect($balances[$this->clearing]->closing->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage]);

it('rolls back both sides when received transfer stock has already been consumed', function () {
    $source = $this->ledger->inventory->pool($this->book, 'MAIN', 'SKU', $this->stock, ValuationMethod::Fifo);
    $destination = $this->ledger->inventory->pool($this->book, 'OTHER', 'SKU', $this->otherStock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $source, 'receive', '2026-01-01', new Quantity('10'), Money::of('10', $this->kes), $this->cash, 'Receive');
    $transfer = $this->ledger->inventory->transfer($this->book, $source, $destination, 'move', '2026-01-02', new Quantity('5'), $this->clearing, 'Move');
    $this->ledger->inventory->issue($this->book, $destination, 'sell', '2026-01-03', new Quantity('1'), $this->cogs, 'Consume received stock');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');

    try {
        $this->ledger->inventory->reverseTransfer($this->book, $transfer->id, 'invalid', '2026-01-04', 'Invalid correction');
        $this->fail('Consumed transfer stock must protect both sides.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('reverse order');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-01-31')->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->inventory->balance($before, $source)->value->decimal())->toBe('5.00');
    expect($this->ledger->inventory->balance($before, $destination)->value->decimal())->toBe('4.00');
});

it('rolls back a completed first leg when the second transfer leg or correction fails', function () {
    $source = $this->ledger->inventory->pool($this->book, 'MAIN', 'SKU', $this->stock, ValuationMethod::Fifo);
    $destination = $this->ledger->inventory->pool($this->book, 'OTHER', 'SKU', $this->otherStock, ValuationMethod::Fifo);
    $this->ledger->inventory->receive($this->book, $source, 'source-receipt', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->cash, 'Receive');
    $this->ledger->inventory->receive($this->book, $destination, 'destination-receipt', '2026-01-03', new Quantity('5'), Money::of('50', $this->kes), $this->cash, 'Receive');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');

    try {
        $this->ledger->inventory->transfer($this->book, $source, $destination, 'invalid-date', '2026-01-02', new Quantity('4'), $this->clearing, 'Invalid target date');
        $this->fail('The completed source issue must be rolled back.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('controlled reposting');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-01-31')->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->inventory->balance($before, $source)->value->decimal())->toBe('100.00');
    $transfer = $this->ledger->inventory->transfer($this->book, $source, $destination, 'move', '2026-01-04', new Quantity('4'), $this->clearing, 'Move');
    $this->ledger->inventory->receive($this->book, $source, 'later-source', '2026-01-05', new Quantity('1'), Money::of('10', $this->kes), $this->cash, 'Later source receipt');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');

    try {
        $this->ledger->inventory->reverseTransfer($this->book, $transfer->id, 'invalid-reversal', '2026-01-06', 'Invalid source dependency');
        $this->fail('The completed destination correction must be rolled back.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('reverse order');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-01-31')->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->inventory->balance($before, $source)->value->decimal())->toBe('70.00');
    expect($this->ledger->inventory->balance($before, $destination)->value->decimal())->toBe('90.00');
});

it('separates original supplier credit from current stock cost without repricing remaining inventory', function (ValuationMethod $method, string $expectedCost, string $remainingValue, string $variance) {
    $pool = $this->ledger->inventory->pool($this->book, 'SUPPLIER', 'SKU', $this->stock, $method);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'original', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Purchase receipt');
    $this->ledger->inventory->receive($this->book, $pool, 'later', '2026-01-02', new Quantity('10'), Money::of('300', $this->kes), $this->clearing, 'More expensive stock');
    $returned = $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'supplier-return', '2026-01-03', new Quantity('2'), $this->clearing, $this->cogs, 'Send rejected goods to supplier');
    expect($returned->value->decimal())->toBe($expectedCost);
    expect($returned->commercialValue?->decimal())->toBe('-20.00');
    expect($this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'supplier-return', '2026-01-03', new Quantity('2'), $this->clearing, $this->cogs, 'Send rejected goods to supplier')->id)->toBe($returned->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe($remainingValue);
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$this->cogs]->closing->decimal())->toBe($variance);
    expect($balances[$this->clearing]->closing->decimal())->toBe('-380.00');
    $this->ledger->inventory->reverse($this->book, $returned->id, 'correct-supplier-return', '2026-01-04', 'Goods were not returned');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe('400.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([
    [ValuationMethod::Fifo, '-20.00', '380.00', '0.00'],
    [ValuationMethod::MovingAverage, '-40.00', '360.00', '20.00'],
]);

it('removes adjusted FIFO carrying value and explicitly recognizes the supplier price difference', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'LANDED', 'SKU', $this->stock, ValuationMethod::Fifo);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'purchase', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Purchase');
    $this->ledger->inventory->adjustValue($this->book, $pool, 'freight', '2026-01-02', Money::of('50', $this->kes), $this->clearing, 'Freight on remaining goods');
    $returned = $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'return', '2026-01-03', new Quantity('10'), $this->clearing, $this->cogs, 'Return all goods');
    expect($returned->value->decimal())->toBe('-150.00');
    expect($returned->commercialValue?->decimal())->toBe('-100.00');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe('0.00');
    expect($this->ledger->inventory->balance($snapshot, $pool)->quantity->value->isZero())->toBeTrue();
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('50.00');
});

it('conserves every original receipt cent through partial supplier returns and corrections', function (ValuationMethod $method) {
    $pool = $this->ledger->inventory->pool($this->book, 'TINY', 'SKU', $this->stock, $method);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'purchase', '2026-01-01', new Quantity('0.3'), Money::of('0.01', $this->kes), $this->clearing, 'Fractional purchase');
    $total = Money::of('0', $this->kes);
    for ($index = 0; $index < 3; $index++) {
        $returned = $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'return-'.$index, '2026-01-02', new Quantity('0.1'), $this->clearing, $this->cogs, 'Partial supplier return');
        $total = $total->plus($returned->commercialValue ?? throw new RuntimeException('Supplier return has no commercial value.'));
    }
    expect($total->decimal())->toBe('-0.01');
    $this->ledger->inventory->reverse($this->book, $returned->id, 'restore-last', '2026-01-03', 'Correct last return');
    $again = $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'return-again', '2026-01-04', new Quantity('0.1'), $this->clearing, $this->cogs, 'Return restored goods');
    expect($again->commercialValue?->decimal())->toBe(($returned->commercialValue ?? throw new RuntimeException('Supplier return has no commercial value.'))->decimal());
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe('0.00');
    expect($this->ledger->inventory->balance($snapshot, $pool)->quantity->value->isZero())->toBeTrue();
    $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'too-many', '2026-01-05', new Quantity('0.1'), $this->clearing, $this->cogs, 'Excess return');
})->with([ValuationMethod::Fifo, ValuationMethod::MovingAverage])->throws(QueryException::class, 'exceeds the original receipt');

it('does not return a depleted FIFO purchase layer using unrelated newer receipts', function () {
    $pool = $this->ledger->inventory->pool($this->book, 'DEPLETED', 'SKU', $this->stock, ValuationMethod::Fifo);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'purchase', '2026-01-01', new Quantity('10'), Money::of('100', $this->kes), $this->clearing, 'Original purchase');
    $this->ledger->inventory->issue($this->book, $pool, 'consume', '2026-01-02', new Quantity('10'), $this->cogs, 'Consume original layer');
    $this->ledger->inventory->receive($this->book, $pool, 'new-purchase', '2026-01-03', new Quantity('10'), Money::of('300', $this->kes), $this->clearing, 'Unrelated lot');
    $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'invalid-return', '2026-01-04', new Quantity('1'), $this->clearing, $this->cogs, 'Original lot no longer held');
})->throws(QueryException::class, 'remaining FIFO layer');

it('handles supplier returns when commercial or carrying values are zero', function (ValuationMethod $method, string $originalCost, string $adjustment, string $carryingEffect, string $commercialEffect, string $variance, string $remaining) {
    $pool = $this->ledger->inventory->pool($this->book, 'ZERO-VALUES', 'SKU', $this->stock, $method);
    $receipt = $this->ledger->inventory->receive($this->book, $pool, 'purchase', '2026-01-01', new Quantity('10'), Money::of($originalCost, $this->kes), $this->clearing, 'Purchase');
    if ($adjustment !== '0') {
        $this->ledger->inventory->adjustValue($this->book, $pool, 'adjust', '2026-01-02', Money::of($adjustment, $this->kes), $this->clearing, 'Reviewed carrying adjustment');
    }
    $returned = $this->ledger->inventory->returnReceipt($this->book, $receipt->id, 'return', '2026-01-03', new Quantity('2'), $this->clearing, $this->cogs, 'Supplier return');
    expect($returned->value->decimal())->toBe($carryingEffect);
    expect($returned->commercialValue?->decimal())->toBe($commercialEffect);
    expect($returned->journalId === null)->toBe($carryingEffect === '0.00' && $commercialEffect === '0.00');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->inventory->balance($snapshot, $pool)->value->decimal())->toBe($remaining);
    expect($this->ledger->inventory->balance($snapshot, $pool)->quantity->decimal())->toBe('8.000000000000');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$this->cogs]->closing->decimal())->toBe($variance);
    $this->ledger->inventory->reverse($this->book, $returned->id, 'restore-return', '2026-01-04', 'Correct return');
    expect($this->ledger->inventory->balance($this->ledger->reports->snapshot($this->book, '2026-01-31'), $pool)->quantity->decimal())->toBe('10.000000000000');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
})->with([
    [ValuationMethod::Fifo, '100', '-100', '0.00', '-20.00', '-20.00', '0.00'],
    [ValuationMethod::MovingAverage, '100', '-100', '0.00', '-20.00', '-20.00', '0.00'],
    [ValuationMethod::Fifo, '0', '100', '-20.00', '0.00', '20.00', '80.00'],
    [ValuationMethod::MovingAverage, '0', '100', '-20.00', '0.00', '20.00', '80.00'],
    [ValuationMethod::Fifo, '0', '0', '0.00', '0.00', '0.00', '0.00'],
    [ValuationMethod::MovingAverage, '0', '0', '0.00', '0.00', '0.00', '0.00'],
]);
