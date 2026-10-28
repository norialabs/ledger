<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Associates\AssociateRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Money;

beforeEach(function () {
    $this->investee = $this->ledger->catalog->entity('Independent associate');
    $this->investment = $this->ledger->catalog->account($this->book, '1200', 'Equity-method investment', AccountType::Asset);
    $this->shareProfit = $this->ledger->catalog->account($this->book, '4200', 'Share of associate profit or loss', AccountType::Income);
    $this->shareOci = $this->ledger->catalog->account($this->book, '3100', 'Share of associate OCI', AccountType::Equity);
    $this->investmentLoss = $this->ledger->catalog->account($this->book, '5200', 'Associate impairment', AccountType::Expense);
    $this->investmentLiability = $this->ledger->catalog->account($this->book, '2300', 'Associate loss obligations', AccountType::Liability);
    $this->associateRequest = new AssociateRequest($this->book, 'acquire-associate', 'ASSOCIATE-1', $this->investee, '2026-01-01', Money::of('100', $this->kes), Money::of('300', $this->kes), '0.3',
        $this->investment, $this->cash, $this->shareProfit, $this->shareOci, $this->cash, $this->investmentLoss,
        'Board representation and participation in financial policy; control is absent', 'ias28:reviewed-v1', $this->investmentLiability);
    $this->associate = $this->ledger->associates->acquire($this->associateRequest);
});

it('keeps a one-line investment at cost plus profit and OCI less dividends without duplicating dividend income', function () {
    expect($this->ledger->associates->acquire($this->associateRequest))->toBe($this->associate);
    $profit = $this->ledger->associates->profit($this->book, $this->associate, 'profit', '2026-01-31', Money::of('60', $this->kes), 'Reviewed January profit', ['statement' => 'associate-jan-v1']);
    expect($this->ledger->associates->profit($this->book, $this->associate, 'profit', '2026-01-31', Money::of('60', $this->kes), 'Reviewed January profit', ['statement' => 'associate-jan-v1']))->toBe($profit);
    $oci = $this->ledger->associates->oci($this->book, $this->associate, 'oci', '2026-01-31', Money::of('20', $this->kes), 'Reviewed January OCI');
    $dividend = $this->ledger->associates->dividend($this->book, $this->associate, 'dividend', '2026-02-01', Money::of('10', $this->kes), 'Distribution received');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $position = $this->ledger->associates->position($snapshot, $this->associate);
    expect($position->carrying->decimal())->toBe('121.00');
    expect($position->embeddedGoodwill->decimal())->toBe('10.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('18.00');
    expect(collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId')[$this->shareOci]->closing->decimal())->toBe('-6.00');
    expect($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->investing->decimal())->toBe('-97.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $evidence = $this->ledger->associates->evidence($snapshot, $this->associate, limit: 1);
    expect($evidence['events'])->toHaveCount(1);
    expect($evidence['events'][0]->id)->toBe($profit);
    expect($this->ledger->associates->evidence($snapshot, $this->associate, afterSequence: (string) $evidence['events'][0]->sequence)['events'])->toHaveCount(2);
    $this->ledger->associates->reverse($this->book, $dividend, 'undo-dividend', '2026-02-02', 'Correct distribution');
    $this->ledger->associates->reverse($this->book, $oci, 'undo-oci', '2026-02-02', 'Correct OCI');
    $this->ledger->associates->reverse($this->book, $profit, 'undo-profit', '2026-02-02', 'Correct profit');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->associate)->carrying->decimal())->toBe('100.00');
    expect($this->ledger->associates->position($snapshot, $this->associate)->carrying->decimal())->toBe('121.00');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('suspends excess losses and offsets subsequent earnings before resuming recognition', function (bool $ociLoss) {
    if ($ociLoss) {
        $this->ledger->associates->oci($this->book, $this->associate, 'loss', '2026-01-31', Money::of('-500', $this->kes), 'OCI loss');
    } else {
        $this->ledger->associates->profit($this->book, $this->associate, 'loss', '2026-01-31', Money::of('-500', $this->kes), 'Operating loss');
    }
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $position = $this->ledger->associates->position($snapshot, $this->associate);
    expect($position->carrying->decimal())->toBe('0.00');
    expect(($ociLoss ? $position->unrecognizedOci : $position->unrecognizedProfitLoss)->decimal())->toBe('50.00');
    $this->ledger->associates->profit($this->book, $this->associate, 'recover-first', '2026-02-28', Money::of('100', $this->kes), 'Offset suspended losses');
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->associate);
    expect($position->carrying->decimal())->toBe('0.00');
    expect(($ociLoss ? $position->unrecognizedOci : $position->unrecognizedProfitLoss)->decimal())->toBe('20.00');
    $this->ledger->associates->profit($this->book, $this->associate, 'recover-second', '2026-03-31', Money::of('100', $this->kes), 'Resume after remaining suspended losses');
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $this->associate);
    expect($position->carrying->decimal())->toBe('10.00');
    expect($position->unrecognizedProfitLoss->decimal())->toBe('0.00');
    expect($position->unrecognizedOci->decimal())->toBe('0.00');
})->with([false, true]);

it('recognizes explicit loss obligations and reverses liabilities before increasing the investment', function () {
    $this->ledger->associates->profit($this->book, $this->associate, 'loss', '2026-01-31', Money::of('-500', $this->kes), 'Loss');
    $this->ledger->associates->obligate($this->book, $this->associate, 'guarantee', '2026-01-31', Money::of('20', $this->kes), 'Reviewed constructive obligation', ['contract' => 'support-20']);
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $this->associate);
    expect($position->lossLiability->decimal())->toBe('20.00');
    expect($position->unrecognizedProfitLoss->decimal())->toBe('30.00');
    $this->ledger->associates->profit($this->book, $this->associate, 'first-profit', '2026-02-28', Money::of('100', $this->kes), 'Offset suspended losses');
    $this->ledger->associates->profit($this->book, $this->associate, 'second-profit', '2026-03-31', Money::of('100', $this->kes), 'Restore liability and investment');
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $this->associate);
    expect($position->carrying->decimal())->toBe('10.00');
    expect($position->lossLiability->decimal())->toBe('0.00');
    expect($position->unrecognizedProfitLoss->decimal())->toBe('0.00');
});

it('settles recognized support obligations without inventing an additional investment asset', function () {
    $this->ledger->associates->obligate($this->book, $this->associate, 'limit', '2026-01-31', Money::of('20', $this->kes), 'Reviewed support obligation');
    $this->ledger->associates->profit($this->book, $this->associate, 'loss', '2026-01-31', Money::of('-500', $this->kes), 'Loss');
    $event = $this->ledger->associates->settleObligation($this->book, $this->associate, 'pay-support', '2026-02-01', Money::of('20', $this->kes), $this->cash, 'Pay support');
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->associate);
    expect($position->lossLiability->decimal())->toBe('0.00');
    expect($position->carrying->decimal())->toBe('0.00');
    expect($position->unrecognizedProfitLoss->decimal())->toBe('30.00');
    expect($position->obligationLimit->decimal())->toBe('0.00');
    $this->ledger->associates->reverse($this->book, $event, 'undo-support', '2026-02-02', 'Correct support payment');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->associate)->lossLiability->decimal())->toBe('20.00');
});

it('tests the entire investment for impairment and caps recovery at its unimpaired carrying value', function () {
    $this->ledger->associates->impair($this->book, $this->associate, 'impair', '2026-01-31', Money::of('60', $this->kes), 'Reviewed recoverable amount');
    $recovery = $this->ledger->associates->recover($this->book, $this->associate, 'recover', '2026-02-28', Money::of('120', $this->kes), Money::of('90', $this->kes), 'Reviewed recovery and depreciated ceiling');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $position = $this->ledger->associates->position($snapshot, $this->associate);
    expect($position->carrying->decimal())->toBe('90.00');
    expect($position->impairment->decimal())->toBe('10.00');
    $this->ledger->associates->reverse($this->book, $recovery, 'undo-recovery', '2026-03-01', 'Correct recovery');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $this->associate)->carrying->decimal())->toBe('60.00');
    expect($this->ledger->associates->position($snapshot, $this->associate)->carrying->decimal())->toBe('90.00');
});

it('recognizes suspended equity-method losses when impairment recovery restores their loss-absorbing capacity', function () {
    $this->ledger->associates->impair($this->book, $this->associate, 'impair', '2026-01-31', Money::of('50', $this->kes), 'Recoverable amount');
    $this->ledger->associates->profit($this->book, $this->associate, 'loss', '2026-02-28', Money::of('-250', $this->kes), 'Share of loss 75');
    $this->ledger->associates->recover($this->book, $this->associate, 'recover', '2026-03-31', Money::of('60', $this->kes), Money::of('25', $this->kes), 'Recover impairment and recognize suspended losses');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $position = $this->ledger->associates->position($snapshot, $this->associate);
    expect($position->carrying->decimal())->toBe('25.00');
    expect($position->unrecognizedProfitLoss->decimal())->toBe('0.00');
    expect($position->impairment->decimal())->toBe('0.00');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$this->shareProfit]->closing->decimal())->toBe('75.00');
    expect($balances[$this->investmentLoss]->closing->decimal())->toBe('0.00');
});

it('conserves cumulative ownership rounding through fractional profit events and corrections', function () {
    $events = [];
    foreach (['first', 'second', 'third'] as $key) {
        $events[] = $this->ledger->associates->profit($this->book, $this->associate, $key, '2026-01-31', Money::of('0.01', $this->kes), 'Fractional share');
    }
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $this->associate)->carrying->decimal())->toBe('100.01');
    $this->ledger->associates->reverse($this->book, $events[2], 'undo-third', '2026-02-01', 'Correct third');
    $this->ledger->associates->reverse($this->book, $events[1], 'undo-second', '2026-02-01', 'Correct second');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $this->associate)->carrying->decimal())->toBe('100.00');
});

it('recognizes acquisition bargains once while keeping goodwill embedded in the investment', function () {
    $investment = $this->ledger->catalog->account($this->book, '1201', 'Bargain associate', AccountType::Asset);
    $associate = $this->ledger->associates->acquire(new AssociateRequest($this->book, 'bargain', 'BARGAIN', $this->investee, '2026-01-01', Money::of('100', $this->kes), Money::of('400', $this->kes), '0.3',
        $investment, $this->cash, $this->shareProfit, $this->shareOci, $this->cash, $this->investmentLoss, 'Significant influence without control', 'ias28:v1'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $position = $this->ledger->associates->position($snapshot, $associate);
    expect($position->carrying->decimal())->toBe('120.00');
    expect($position->embeddedGoodwill->decimal())->toBe('0.00');
    expect($position->bargainPurchaseGain->decimal())->toBe('20.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('20.00');
});

it('cancels untouched acquisitions atomically and requires a new identity for subsequent accounting', function () {
    $this->ledger->associates->cancel($this->book, $this->associate, 'cancel', '2026-01-02', 'Incorrect acquisition');
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $this->associate);
    expect($position->carrying->decimal())->toBe('0.00');
    expect($position->cancelled)->toBeTrue();
    $this->ledger->associates->profit($this->book, $this->associate, 'after-cancel', '2026-01-31', Money::of('10', $this->kes), 'Invalid subsequent profit');
})->throws(QueryException::class, 'cancelled');

it('rejects detached journals and forged ownership effects independently in PostgreSQL', function (bool $forged) {
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->storage->run(Permission::Investments, function () use ($forged): void {
            $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'detached', '2026-01-31', 'Invalid investment movement', [new Posting($this->investment, Side::Debit, Money::of('100', $this->kes)), new Posting($this->shareProfit, Side::Credit, Money::of('100', $this->kes))]));
            if ($forged) {
                $this->storage->insert('ledger_associate_events', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'associate_id' => $this->associate, 'idempotency_key' => 'forged', 'fingerprint' => str_repeat('a', 64),
                    'effective_on' => '2026-01-31', 'kind' => 'profit', 'source_minor' => '10000', 'share_minor' => '10000', 'carrying_delta' => '10000', 'liability_delta' => '0',
                    'unrecognized_profit_delta' => '0', 'unrecognized_oci_delta' => '0', 'impairment_delta' => '0', 'obligation_limit_minor' => '0', 'prior_limit_minor' => '0',
                    'journal_id' => $journal->id, 'evidence' => '{}', 'reason' => 'Forged 100% share instead of 30%']);
            }
            $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, $this->book);
        test()->fail('Associate GL movements require exact equity-method evidence.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($forged ? 'ownership and loss-limit effects' : 'corresponding equity-method evidence');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
})->with([false, true]);

it('requires the investment permission independently of journal posting permission', function () {
    $this->app->bind(Authorizer::class, fn () => new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Investments;
        }
    });
    $ledger = $this->app->make(Ledger::class)->for($this->scope);
    $ledger->associates->profit($this->book, $this->associate, 'denied', '2026-01-31', Money::of('100', $this->kes), 'Denied');
})->throws(LedgerException::class, 'ledger.investments');

it('rejects distributions beyond carrying value and out-of-order accounting or corrections', function (string $invalid) {
    if ($invalid === 'distribution') {
        $this->ledger->associates->dividend($this->book, $this->associate, 'excess', '2026-01-31', Money::of('400', $this->kes), 'Excess distribution');
    } elseif ($invalid === 'inactive') {
        $this->ledger->catalog->setAccountActive($this->book, $this->investment, false);
        $this->ledger->associates->profit($this->book, $this->associate, 'zero', '2026-01-31', Money::of('0', $this->kes), 'Inactive investment');
    } else {
        $first = $this->ledger->associates->profit($this->book, $this->associate, 'first', '2026-02-01', Money::of('100', $this->kes), 'First profit');
        if ($invalid === 'chronology') {
            $this->ledger->associates->profit($this->book, $this->associate, 'late', '2026-01-31', Money::of('100', $this->kes), 'Invalid backdate');
        } else {
            $this->ledger->associates->oci($this->book, $this->associate, 'later', '2026-02-02', Money::of('100', $this->kes), 'Later OCI');
            $this->ledger->associates->reverse($this->book, $first, 'out-of-order', '2026-02-03', 'Invalid correction');
        }
    }
})->with(['distribution', 'inactive', 'chronology', 'correction'])->throws(QueryException::class);

it('requires a separate significant-influence basis and a proper fractional ownership interest', function (string $fraction, string $basis) {
    new AssociateRequest($this->book, 'invalid', 'INVALID', $this->investee, '2026-01-01', Money::of('100', $this->kes), Money::of('300', $this->kes), $fraction,
        $this->investment, $this->cash, $this->shareProfit, $this->shareOci, $this->cash, $this->investmentLoss, $basis, 'ias28:v1');
})->with([['0', 'Board representation'], ['1', 'Board representation'], ['0.3', '']])->throws(LedgerException::class);

it('does not permit investment ownership records to adopt the investors own entity', function () {
    $account = $this->ledger->catalog->account($this->book, '1201', 'Invalid self-investment', AccountType::Asset);
    $this->ledger->associates->acquire(new AssociateRequest($this->book, 'self', 'SELF', $this->entity, '2026-01-01', Money::of('100', $this->kes), Money::of('300', $this->kes), '0.3',
        $account, $this->cash, $this->shareProfit, $this->shareOci, $this->cash, $this->investmentLoss, 'Board representation', 'ias28:v1'));
})->throws(QueryException::class, 'distinct live investee entity');

it('rejects an overflowing cumulative investment balance atomically', function () {
    $amount = Money::minor(str_repeat('9', 38), $this->kes);
    foreach (['first', 'second', 'third'] as $key) {
        $this->ledger->associates->profit($this->book, $this->associate, $key, '2026-01-31', $amount, 'Large exact result');
    }
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->associates->profit($this->book, $this->associate, 'overflow', '2026-01-31', $amount, 'Invalid cumulative overflow');
        test()->fail('Cumulative investment balances cannot overflow storage.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('38-digit limit');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('retains required dimensions and allows exact corrections after a dimension is retired', function () {
    $this->ledger->catalog->dimensionValue($this->entity, 'department', 'west');
    $investment = $this->ledger->catalog->account($this->book, '1201', 'West investment', AccountType::Asset);
    $this->ledger->catalog->requireDimension($this->book, $investment, 'department');
    $associate = $this->ledger->associates->acquire(new AssociateRequest($this->book, 'west', 'WEST', $this->investee, '2026-01-01', Money::of('100', $this->kes), Money::of('300', $this->kes), '0.3',
        $investment, $this->cash, $this->shareProfit, $this->shareOci, $this->cash, $this->investmentLoss, 'Board representation without control', 'ias28:v1', dimensions: ['department' => 'west']));
    $event = $this->ledger->associates->profit($this->book, $associate, 'west-profit', '2026-01-31', Money::of('100', $this->kes), 'West profit');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01', ['department' => 'west'])['profit']->decimal())->toBe('30.00');
    $this->ledger->catalog->deleteDimensionValue($this->entity, 'department', 'west');
    $this->ledger->associates->reverse($this->book, $event, 'undo-west', '2026-02-01', 'Correct historical result');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $associate)->carrying->decimal())->toBe('100.00');
    $this->ledger->associates->profit($this->book, $associate, 'inactive-dimension', '2026-02-01', Money::of('0', $this->kes), 'Invalid retired dimension');
})->throws(QueryException::class, 'soft-deleted accounting dimension');

it('sells part of an associate, recycles the same share of OCI and applies the reduced ownership afterwards', function () {
    $gain = $this->ledger->catalog->account($this->book, '4300', 'Gain on associate disposal', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5300', 'Loss on associate disposal', AccountType::Expense);
    $recycled = $this->ledger->catalog->account($this->book, '4310', 'Reclassified associate OCI', AccountType::Income);
    $this->ledger->associates->profit($this->book, $this->associate, 'profit', '2026-01-31', Money::of('60', $this->kes), 'January profit');
    $this->ledger->associates->oci($this->book, $this->associate, 'oci', '2026-01-31', Money::of('20', $this->kes), 'January OCI');

    $this->ledger->associates->dispose($this->book, $this->associate, 'sell-half', '2026-03-31', '0.5', Money::of('70', $this->kes), $this->cash, $gain, $loss, 'Sold half the holding', $recycled);
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $this->associate);
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-03-31')))->keyBy('accountId');
    expect($position->carrying->decimal())->toBe('62.00')
        ->and($position->ownershipFraction)->toBe('0.15')
        ->and($position->ociReserve?->decimal())->toBe('3.00')
        ->and($position->disposed)->toBeFalse()
        ->and($balances[$gain]->closing->decimal())->toBe('-8.00')
        ->and($balances[$recycled]->closing->decimal())->toBe('-3.00')
        ->and($balances[$this->shareOci]->closing->decimal())->toBe('-3.00');

    $this->ledger->associates->profit($this->book, $this->associate, 'april-profit', '2026-04-30', Money::of('40', $this->kes), 'April profit');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-04-30'), $this->associate)->carrying->decimal())->toBe('68.00');
});

it('derecognizes an associate on loss of influence, measures the retained stake at fair value and reverses exactly', function () {
    $gain = $this->ledger->catalog->account($this->book, '4300', 'Gain on associate disposal', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5300', 'Loss on associate disposal', AccountType::Expense);
    $retainedEarnings = $this->ledger->catalog->account($this->book, '3200', 'Retained earnings', AccountType::Equity);
    $financialAsset = $this->ledger->catalog->account($this->book, '1250', 'Equity investments at FVOCI', AccountType::Asset);
    $this->ledger->associates->profit($this->book, $this->associate, 'profit', '2026-01-31', Money::of('60', $this->kes), 'January profit');
    $this->ledger->associates->oci($this->book, $this->associate, 'oci', '2026-01-31', Money::of('20', $this->kes), 'January OCI');

    $disposal = $this->ledger->associates->dispose($this->book, $this->associate, 'lose-influence', '2026-06-30', '1', Money::of('90', $this->kes), $this->cash, $gain, $loss,
        'Sold 20% and lost significant influence', $retainedEarnings, Money::of('45', $this->kes), $financialAsset);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($this->ledger->associates->position($snapshot, $this->associate)->disposed)->toBeTrue()
        ->and($balances[$this->investment]->closing->decimal())->toBe('0.00')
        ->and($balances[$financialAsset]->closing->decimal())->toBe('45.00')
        ->and($balances[$gain]->closing->decimal())->toBe('-11.00')
        ->and($balances[$retainedEarnings]->closing->decimal())->toBe('-6.00')
        ->and($balances[$this->shareOci]->closing->decimal())->toBe('0.00')
        ->and(fn () => $this->ledger->associates->profit($this->book, $this->associate, 'after', '2026-07-31', Money::of('10', $this->kes), 'After disposal'))->toThrow(QueryException::class, 'was disposed');

    $this->ledger->associates->reverse($this->book, $disposal, 'undo-disposal', '2026-07-01', 'Sale rescinded');
    $restored = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-07-01'), $this->associate);
    expect($restored->carrying->decimal())->toBe('124.00')->and($restored->disposed)->toBeFalse()->and($restored->ownershipFraction)->toBe('0.3');
});

it('adds a further stake with incremental goodwill or bargain and applies the higher ownership afterwards', function () {
    $this->ledger->associates->profit($this->book, $this->associate, 'profit', '2026-01-31', Money::of('60', $this->kes), 'January profit');
    $this->ledger->associates->increase($this->book, $this->associate, 'buy-10', '2026-03-31', '0.1', Money::of('45', $this->kes), Money::of('400', $this->kes), $this->cash, 'Bought a further 10%');
    $position = $this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $this->associate);
    expect($position->carrying->decimal())->toBe('163.00')->and($position->ownershipFraction)->toBe('0.4');

    $this->ledger->associates->profit($this->book, $this->associate, 'april-profit', '2026-04-30', Money::of('50', $this->kes), 'April profit');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-04-30'), $this->associate)->carrying->decimal())->toBe('183.00');

    $this->ledger->associates->increase($this->book, $this->associate, 'buy-cheap', '2026-05-31', '0.1', Money::of('35', $this->kes), Money::of('400', $this->kes), $this->cash, 'Bargain stake');
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-05-31')))->keyBy('accountId');
    expect($balances[$this->investment]->closing->decimal())->toBe('223.00')
        ->and($balances[$this->shareProfit]->closing->decimal())->toBe('-43.00')
        ->and(fn () => $this->ledger->associates->increase($this->book, $this->associate, 'control', '2026-06-30', '0.5', Money::of('300', $this->kes), Money::of('600', $this->kes), $this->cash, 'Would give control'))
        ->toThrow(QueryException::class, 'below control');
});

it('stops equity accounting while held for sale but still allows impairment and disposal', function () {
    $gain = $this->ledger->catalog->account($this->book, '4300', 'Gain on associate disposal', AccountType::Income);
    $loss = $this->ledger->catalog->account($this->book, '5300', 'Loss on associate disposal', AccountType::Expense);
    $classification = $this->ledger->associates->holdForSale($this->book, $this->associate, 'hfs', '2026-02-01', 'Board approved sale');
    expect($this->ledger->associates->position($this->ledger->reports->snapshot($this->book, '2026-02-01'), $this->associate)->heldForSale)->toBeTrue()
        ->and(fn () => $this->ledger->associates->profit($this->book, $this->associate, 'profit', '2026-02-28', Money::of('60', $this->kes), 'Profit'))->toThrow(QueryException::class, 'held for sale');

    $this->ledger->associates->impair($this->book, $this->associate, 'fvlcs', '2026-02-28', Money::of('90', $this->kes), 'Fair value less costs to sell');
    $this->ledger->associates->dispose($this->book, $this->associate, 'sale', '2026-03-31', '1', Money::of('92', $this->kes), $this->cash, $gain, $loss, 'Completed sale');
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-03-31')))->keyBy('accountId');
    expect($balances[$this->investment]->closing->decimal())->toBe('0.00')
        ->and($balances[$this->investmentLoss]->closing->decimal())->toBe('10.00')
        ->and($balances[$gain]->closing->decimal())->toBe('-2.00')
        ->and($classification)->not->toBeEmpty();
});
