<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Assets\StubConvention;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Scheduling\Schedule;

beforeEach(function () {
    $this->assetCost = $this->ledger->catalog->account($this->book, '1500', 'Equipment', AccountType::Asset, cashFlow: 'investing');
    $this->assetDep = $this->ledger->catalog->account($this->book, '1590', 'Accumulated depreciation', AccountType::Asset, normalSide: Side::Credit);
    $this->assetImp = $this->ledger->catalog->account($this->book, '1591', 'Accumulated impairment', AccountType::Asset, normalSide: Side::Credit);
    $this->depExpense = $this->ledger->catalog->account($this->book, '5100', 'Depreciation expense', AccountType::Expense);
    $this->impExpense = $this->ledger->catalog->account($this->book, '5200', 'Impairment loss', AccountType::Expense);
    $this->funding = $this->ledger->catalog->account($this->book, '2300', 'Capitalization clearing', AccountType::Liability);
    $this->disposalGain = $this->ledger->catalog->account($this->book, '4100', 'Disposal gain', AccountType::Income);
    $this->disposalLoss = $this->ledger->catalog->account($this->book, '5300', 'Disposal loss', AccountType::Expense);
    $this->assetDates = ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30', '2026-10-31', '2026-11-30', '2026-12-31'];
    $this->assetRequest = new AssetRequest($this->book, 'capitalize-equipment', 'EQUIPMENT-1', '2026-01-01', '2026-01-01', Money::of('12000', $this->kes), Money::of('0', $this->kes),
        Schedule::equal(Money::of('12000', $this->kes), $this->assetDates), $this->assetCost, $this->assetDep, $this->assetImp, $this->depExpense, $this->funding, 'straight-line-monthly:v1');
    $this->assetId = $this->ledger->assets->capitalize($this->assetRequest);
});

it('capitalizes and depreciates an asset without exceeding its plan', function () {
    expect($this->ledger->assets->capitalize($this->assetRequest))->toBe($this->assetId);
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->assets->depreciate($this->book, $this->assetId, '2026-12-31'))->toHaveCount(12);
    expect($this->ledger->assets->depreciate($this->book, $this->assetId, '2026-12-31'))->toBe([]);
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $this->assetId);
    expect($position->depreciation->decimal())->toBe('12000.00');
    expect($position->carrying->decimal())->toBe('0.00');
    expect($this->ledger->assets->position($before, $this->assetId)->carrying->decimal())->toBe('12000.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('recognizes depreciation through disposal and reverses the exact disposal', function () {
    $event = $this->ledger->assets->dispose($this->book, $this->assetId, 'dispose-equipment', '2026-02-14', Money::of('8000', $this->kes),
        $this->cash, $this->disposalGain, $this->disposalLoss, 'Sell equipment');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $position = $this->ledger->assets->position($snapshot, $this->assetId);
    expect($position->disposed)->toBeTrue();
    expect($position->depreciation->decimal())->toBe('1500.00');
    expect($position->carrying->decimal())->toBe('0.00');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$this->assetCost]->closing->decimal())->toBe('0.00');
    expect($balances[$this->assetDep]->closing->decimal())->toBe('0.00');
    expect($balances[$this->disposalLoss]->closing->decimal())->toBe('2500.00');
    expect($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->investing->decimal())->toBe('8000.00');
    $this->ledger->assets->reverse($this->book, $event, 'reverse-disposal', '2026-02-15', 'Sale cancelled');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->assetId);
    expect($position->disposed)->toBeFalse();
    expect($position->depreciation->decimal())->toBe('1000.00');
    expect($position->carrying->decimal())->toBe('11000.00');
    $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-02-28');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('impairs an asset and recalculates depreciation prospectively', function () {
    $this->ledger->assets->impair($this->book, $this->assetId, 'impair-equipment', '2026-01-31', Money::of('3000', $this->kes), $this->impExpense,
        Money::of('0', $this->kes), Schedule::equal(Money::of('8000', $this->kes), array_slice($this->assetDates, 1)), 'revised-life:v1', 'Recoverable amount declined');
    $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-12-31');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $this->assetId);
    expect($position->impairment->decimal())->toBe('3000.00');
    expect($position->depreciation->decimal())->toBe('9000.00');
    expect($position->carrying->decimal())->toBe('0.00');
});

it('cancels an untouched capitalization without inventing a disposal loss', function () {
    $this->ledger->assets->cancel($this->book, $this->assetId, 'cancel-equipment', '2026-01-02', 'Incorrect source asset');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->assets->position($snapshot, $this->assetId)->disposed)->toBeTrue();
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('imports opening balances and retains the residual value after all depreciation', function () {
    $asset = $this->ledger->assets->capitalize(new AssetRequest($this->book, 'import', 'IMPORTED-1', '2026-01-01', '2020-01-01',
        Money::of('10000', $this->kes), Money::of('1000', $this->kes), Schedule::equal(Money::of('5000', $this->kes), $this->assetDates),
        $this->assetCost, $this->assetDep, $this->assetImp, $this->depExpense, $this->funding, 'reviewed-opening:v1',
        openingDepreciation: Money::of('3000', $this->kes), openingImpairment: Money::of('1000', $this->kes)));
    $this->ledger->assets->depreciate($this->book, $asset, '2026-12-31');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $asset);
    expect($position->carrying->decimal())->toBe('1000.00');
    expect($position->depreciation->decimal())->toBe('8000.00');
    expect($position->impairment->decimal())->toBe('1000.00');
});

it('revises estimates after a day-prorated stub and restores the original plan on reversal', function () {
    $before = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $event = $this->ledger->assets->revise($this->book, $this->assetId, 'revise', '2026-02-14', Money::of('500', $this->kes),
        Schedule::equal(Money::of('10000', $this->kes), array_slice($this->assetDates, 1)), 'reviewed-estimate:v2', 'Revised remaining life');
    $revised = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->assets->position($revised, $this->assetId)->depreciation->decimal())->toBe('1500.00');
    expect($this->ledger->assets->position($revised, $this->assetId)->residual->decimal())->toBe('500.00');
    $this->ledger->assets->reverse($this->book, $event, 'restore-plan', '2026-02-15', 'Restore original estimate');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->assetId);
    expect($position->depreciation->decimal())->toBe('1000.00');
    expect($position->residual->decimal())->toBe('0.00');
    expect($this->ledger->assets->position($revised, $this->assetId)->residual->decimal())->toBe('500.00');
    expect($this->ledger->assets->position($before, $this->assetId)->depreciation->decimal())->toBe('0.00');
    $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-12-31');
    expect($this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $this->assetId)->carrying->decimal())->toBe('0.00');
});

it('requires dependent depreciation to be reversed before cancelling capitalization', function () {
    $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-01-31');
    $before = $this->ledger->reports->snapshot($this->book, '2026-02-28');

    try {
        $this->ledger->assets->cancel($this->book, $this->assetId, 'invalid-cancel', '2026-02-01', 'Invalid correction');
        test()->fail('Downstream depreciation must be restored first.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('downstream asset events');
    }

    expect($this->ledger->reports->snapshot($this->book, '2026-02-28')->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->assets->position($before, $this->assetId)->carrying->decimal())->toBe('11000.00');
});

it('keeps registered asset accounts outside inventory valuation', function () {
    $this->ledger->inventory->pool($this->book, 'INVALID-STOCK', 'SKU', $this->assetCost, ValuationMethod::Fifo);
})->throws(QueryException::class, 'non-cash asset account');

it('rejects raw asset events that hide a replacement plan in a depreciation row', function () {
    $this->storage->run(Permission::Assets, function () {
        $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'raw-depreciation', '2026-01-31', 'Depreciation', [
            new Posting($this->depExpense, Side::Debit, Money::of('1000', $this->kes)),
            new Posting($this->assetDep, Side::Credit, Money::of('1000', $this->kes)),
        ]));
        $this->storage->insert('ledger_asset_events', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'asset_id' => $this->assetId,
            'idempotency_key' => 'forged-plan', 'fingerprint' => str_repeat('0', 64), 'effective_on' => '2026-01-31', 'kind' => 'depreciation',
            'depreciation_minor' => '100000', 'impairment_minor' => '0', 'disposal_delta' => 0, 'plan_id' => $this->assetId, 'line_no' => 1,
            'plan' => '[]', 'residual_minor' => '1100000', 'policy_version' => 'forged', 'reason' => 'Invalid plan', 'journal_id' => $journal->id]);
    }, $this->book);
})->throws(QueryException::class);

it('applies the explicitly selected disposal depreciation convention', function (StubConvention $convention, string $depreciation) {
    $asset = $this->ledger->assets->capitalize(new AssetRequest($this->book, 'convention', 'CONVENTION-1', '2026-01-01', '2026-01-01',
        Money::of('12000', $this->kes), Money::of('0', $this->kes), Schedule::equal(Money::of('12000', $this->kes), $this->assetDates),
        $this->assetCost, $this->assetDep, $this->assetImp, $this->depExpense, $this->funding, 'reviewed-convention:v1', stubConvention: $convention));
    $this->ledger->assets->dispose($this->book, $asset, 'dispose-convention', '2026-02-14', Money::of('8000', $this->kes),
        $this->cash, $this->disposalGain, $this->disposalLoss, 'Sell equipment');
    expect($this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $asset)->depreciation->decimal())->toBe($depreciation);
})->with([[StubConvention::ActualDays, '1500.00'],
    [StubConvention::CompletedPeriods, '1000.00'], [StubConvention::FullPeriod, '2000.00']]);

it('catches up only requested due slots in an open period and safely retries', function () {
    $events = $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-01-31');
    $this->ledger->assets->reverse($this->book, $events[0], 'restore-january', '2026-02-01', 'Correct depreciation');
    $this->ledger->periods->close($this->book, $this->period);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    expect($this->ledger->assets->depreciate($this->book, $this->assetId, '2026-01-31', '2027-01-31'))->toHaveCount(1);
    expect($this->ledger->assets->depreciate($this->book, $this->assetId, '2026-01-31', '2027-01-31'))->toBe([]);
    expect($this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2027-01-31'), $this->assetId)->depreciation->decimal())->toBe('1000.00');
});

it('rejects incomplete or ambiguous raw asset plans independently in PostgreSQL', function (array $plan) {
    $this->storage->run(Permission::Assets, fn () => $this->storage->connection->selectOne('SELECT ledger_asset_plan_check(?::jsonb, ?::date, ?::numeric)',
        [json_encode($plan, JSON_THROW_ON_ERROR), '2026-01-01', '100000']));
})->with([
    [[['due_on' => '2026-01-31']]],
    [[['amount_minor' => '100000']]],
    [[['due_on' => '01/02/2026', 'amount_minor' => '100000']]],
])->throws(QueryException::class, 'Invalid asset plan');

it('capitalizes additions prospectively and disposes the entire enhanced gross cost', function () {
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $plan = Schedule::equal(Money::of('13000', $this->kes), array_slice($this->assetDates, 1));
    $addition = $this->ledger->assets->addCost($this->book, $this->assetId, 'upgrade', '2026-01-31', Money::of('3000', $this->kes), $this->cash,
        Money::of('1000', $this->kes), $plan, 'capital-improvement:v1', 'Approved capital improvement');
    expect($this->ledger->assets->addCost($this->book, $this->assetId, 'upgrade', '2026-01-31', Money::of('3000', $this->kes), $this->cash,
        Money::of('1000', $this->kes), $plan, 'capital-improvement:v1', 'Approved capital improvement'))->toBe($addition);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $position = $this->ledger->assets->position($snapshot, $this->assetId);
    expect($position->cost->decimal())->toBe('15000.00');
    expect($position->depreciation->decimal())->toBe('1000.00');
    expect($position->carrying->decimal())->toBe('14000.00');
    expect($position->residual->decimal())->toBe('1000.00');
    expect($this->ledger->assets->position($before, $this->assetId)->cost->decimal())->toBe('12000.00');
    expect($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->investing->decimal())->toBe('-3000.00');
    $disposal = $this->ledger->assets->dispose($this->book, $this->assetId, 'dispose-upgraded', '2026-01-31', Money::of('14000', $this->kes),
        $this->cash, $this->disposalGain, $this->disposalLoss, 'Sell the upgraded asset');
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')))->keyBy('accountId');
    expect($balances[$this->assetCost]->closing->decimal())->toBe('0.00');
    expect($balances[$this->assetDep]->closing->decimal())->toBe('0.00');
    expect($balances[$this->disposalGain]->closing->decimal())->toBe('0.00');
    $this->ledger->assets->reverse($this->book, $disposal, 'restore-disposal', '2026-02-01', 'Correct sale');
    $this->ledger->assets->reverse($this->book, $addition, 'restore-addition', '2026-02-01', 'Correct capitalization');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-02-01'), $this->assetId);
    expect($position->cost->decimal())->toBe('12000.00');
    expect($position->carrying->decimal())->toBe('11000.00');
    expect($position->residual->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('derecognizes a proportionate asset part and preserves the remaining depreciation plan', function () {
    $plan = Schedule::equal(Money::of('8250', $this->kes), array_slice($this->assetDates, 1));
    $event = $this->ledger->assets->disposePart($this->book, $this->assetId, 'sell-quarter', '2026-01-31', '0.25', Money::of('2500', $this->kes),
        $this->cash, $this->disposalGain, $this->disposalLoss, Money::of('0', $this->kes), $plan, 'proportionate-disposal:v1', 'Sell one quarter');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $position = $this->ledger->assets->position($snapshot, $this->assetId);
    expect($position->disposed)->toBeFalse();
    expect($position->cost->decimal())->toBe('9000.00');
    expect($position->depreciation->decimal())->toBe('750.00');
    expect($position->carrying->decimal())->toBe('8250.00');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$this->assetCost]->closing->decimal())->toBe('9000.00');
    expect($balances[$this->assetDep]->closing->decimal())->toBe('-750.00');
    expect($balances[$this->disposalLoss]->closing->decimal())->toBe('250.00');
    expect($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->investing->decimal())->toBe('2500.00');
    $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-12-31');
    expect($this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $this->assetId)->carrying->decimal())->toBe('0.00');
    $this->ledger->assets->reverse($this->book, $event, 'invalid-reverse', '2026-12-31', 'Dependent depreciation still exists');
})->throws(QueryException::class, 'reverse order');

it('allocates accumulated impairment on partial disposal and restores all exact carrying effects', function () {
    $this->ledger->assets->impair($this->book, $this->assetId, 'impair', '2026-01-31', Money::of('3000', $this->kes), $this->impExpense,
        Money::of('0', $this->kes), Schedule::equal(Money::of('8000', $this->kes), array_slice($this->assetDates, 1)), 'impairment:v1', 'Reviewed impairment');
    $event = $this->ledger->assets->disposePart($this->book, $this->assetId, 'sell-impaired-quarter', '2026-01-31', '0.25', Money::of('2100', $this->kes),
        $this->cash, $this->disposalGain, $this->disposalLoss, Money::of('0', $this->kes), Schedule::equal(Money::of('6000', $this->kes), array_slice($this->assetDates, 1)),
        'proportionate-disposal:v1', 'Sell impaired component fraction');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $position = $this->ledger->assets->position($snapshot, $this->assetId);
    expect($position->cost->decimal())->toBe('9000.00');
    expect($position->depreciation->decimal())->toBe('750.00');
    expect($position->impairment->decimal())->toBe('2250.00');
    expect($position->carrying->decimal())->toBe('6000.00');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$this->disposalGain]->closing->decimal())->toBe('-100.00');
    $this->ledger->assets->reverse($this->book, $event, 'restore-quarter', '2026-02-01', 'Sale was entered incorrectly');
    $position = $this->ledger->assets->position($this->ledger->reports->snapshot($this->book, '2026-02-01'), $this->assetId);
    expect($position->cost->decimal())->toBe('12000.00');
    expect($position->depreciation->decimal())->toBe('1000.00');
    expect($position->impairment->decimal())->toBe('3000.00');
    expect($position->carrying->decimal())->toBe('8000.00');
});

it('rejects raw asset additions with forged gross cost effects', function () {
    $this->ledger->assets->depreciate($this->book, $this->assetId, '2026-01-31');
    $this->storage->run(Permission::Assets, function (): void {
        $this->storage->insert('ledger_asset_events', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'asset_id' => $this->assetId,
            'idempotency_key' => 'forged-addition', 'fingerprint' => str_repeat('a', 64), 'effective_on' => '2026-01-31', 'kind' => 'addition',
            'amount_minor' => '300000', 'cost_delta' => '300001', 'depreciation_minor' => '0', 'impairment_minor' => '0', 'disposal_delta' => 0,
            'counter_account_id' => $this->funding, 'plan' => json_encode(Schedule::equal(Money::of('13000', $this->kes), array_slice($this->assetDates, 1)), JSON_THROW_ON_ERROR),
            'residual_minor' => '100000', 'policy_version' => 'forged', 'reason' => 'Incorrect gross cost']);
    }, $this->book);
})->throws(QueryException::class, 'Incorrect asset carrying effects');

it('rolls back due depreciation and all evidence when an addition has an incomplete replacement plan', function () {
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $events = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->assets->addCost($this->book, $this->assetId, 'invalid-improvement', '2026-01-31', Money::of('3000', $this->kes), $this->funding,
            Money::of('0', $this->kes), Schedule::equal(Money::of('13999.99', $this->kes), array_slice($this->assetDates, 1)), 'improvement:v1', 'Invalid remaining plan');
        test()->fail('Incomplete replacement plans must roll back their entire command.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('conserve the remaining depreciable value');
    }
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($snapshot->throughSequence)->toBe($before->throughSequence);
    expect($this->ledger->events->cutoff($this->book))->toBe($events);
    expect($this->ledger->assets->position($snapshot, $this->assetId)->cost->decimal())->toBe('12000.00');
    expect($this->ledger->assets->position($snapshot, $this->assetId)->depreciation->decimal())->toBe('0.00');
});

it('rejects partial disposal fractions with excess precision without quantizing the requested fraction', function () {
    $this->ledger->assets->disposePart($this->book, $this->assetId, 'invalid-fraction', '2026-01-31', '0.0000000000000000001', Money::of('0', $this->kes),
        $this->cash, $this->disposalGain, $this->disposalLoss, Money::of('0', $this->kes), Schedule::equal(Money::of('11000', $this->kes), array_slice($this->assetDates, 1)),
        'disposal:v1', 'Invalid fraction precision');
})->throws(QueryException::class, 'exact fraction');
