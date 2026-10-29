<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Documents\PaymentRequest;
use Noria\Ledger\Documents\TaxedDocumentRequest;
use Noria\Ledger\Documents\TaxLine;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Session;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Tax\DeferredTaxRequest;
use Noria\Ledger\Tax\IncomeTaxRequest;
use Noria\Ledger\Tax\Kenya;
use Noria\Ledger\Tax\TaxEntry;
use Noria\Ledger\Tax\TaxRule;
use Noria\Ledger\Tests\Support\PermitAll;

it('reconciles opening deferred tax separately from current movements and restores exact correction evidence', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $opening = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'opening-tax', $configuration,
        'temporary:equipment', deferredTaxInputs(['opening' => true, 'attributions' => [['category' => 'opening', 'amount_minor' => '15000', 'reference' => 'Imported liability']]])));
    postDeferredTax($this->ledger, $this->book, $opening, 'opening', '2026-01-01');
    $request = new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'measure-tax', $configuration, 'temporary:equipment',
        deferredTaxInputs(['tax_base_minor' => '100000', 'attributions' => [['category' => 'profit', 'amount_minor' => '-15000', 'reference' => 'Difference reversal']]]));
    $id = $this->ledger->incomeTax->prepareDeferred($request);
    expect($this->ledger->incomeTax->prepareDeferred($request))->toBe($id);
    $position = $this->ledger->incomeTax->deferred($this->book, $id);
    expect($position->openingLiability->decimal())->toBe('150.00')->and($position->liability->decimal())->toBe('0.00')
        ->and($position->charge->decimal())->toBe('-150.00')->and($position->priorId)->toBe($opening);
    $event = postDeferredTax($this->ledger, $this->book, $id, 'measure', '2026-01-31');
    expect($this->ledger->incomeTax->postDeferred($this->book, $id, 'measure:post', '2026-01-31', 'Recognize deferred tax'))->toBe($event);
    $saved = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect(fn () => $this->ledger->incomeTax->reverseDeferred($this->book, $opening, 'wrong-order', '2026-01-31', 'Correction'))->toThrow(QueryException::class, 'dependent');
    $this->ledger->incomeTax->reverseDeferred($this->book, $id, 'correct', '2026-02-01', 'Correct measurement');
    $corrected = $this->ledger->reports->snapshot($this->book, '2026-02-01');
    expect($this->ledger->incomeTax->deferredPositions($corrected)[0]->id)->toBe($opening)
        ->and($this->ledger->incomeTax->deferredPositions($saved)[0]->id)->toBe($id)
        ->and($this->ledger->incomeTax->deferred($this->book, $id)->resultHash)->toBe($position->resultHash)
        ->and($this->ledger->incomeTax->deferredHistory($corrected, $id))->toHaveCount(3);
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('keeps loss availability separate from explicit partial deferred asset recognition', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    incomeTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'loss', 'trade', '2025-12-31', Money::of('1000', $this->kes), 'Reviewed loss');
    $inputs = deferredTaxInputs(['kind' => 'loss', 'loss_id' => $loss, 'carrying_minor' => null, 'tax_base_minor' => null, 'attributions' => []]);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'unrecognized', $configuration, 'loss:'.$loss, $inputs));
    postDeferredTax($this->ledger, $this->book, $id, 'unrecognized', '2026-01-01');
    expect($this->ledger->incomeTax->deferred($this->book, $id)->unrecognizedAsset->decimal())->toBe('300.00');
    $recognized = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'recognized', $configuration, 'loss:'.$loss,
        [...$inputs, 'recognize_minor' => '20000', 'attributions' => [['category' => 'profit', 'amount_minor' => '-20000', 'reference' => 'Probable future taxable profits']]]));
    postDeferredTax($this->ledger, $this->book, $recognized, 'recognized', '2026-01-31');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->incomeTax->deferred($this->book, $recognized)->asset->decimal())->toBe('200.00')
        ->and($this->ledger->incomeTax->deferred($this->book, $recognized)->unrecognizedAsset->decimal())->toBe('100.00')
        ->and($this->ledger->incomeTax->losses($snapshot)[0]->remaining->decimal())->toBe('1000.00');
});

it('attributes deferred tax on a surplus to OCI without profit expense', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'surplus-tax', $configuration,
        'temporary:surplus', deferredTaxInputs(['carrying_minor' => '400000', 'tax_base_minor' => '100000',
            'attributions' => [['category' => 'oci_non_recyclable', 'amount_minor' => '90000', 'reference' => 'Tax on revaluation surplus']]])));
    postDeferredTax($this->ledger, $this->book, $id, 'surplus', '2026-01-31');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $expense = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.deferred');
    $surplus = $this->ledger->configurations->account($this->book, $configuration, 'tax.surplus');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect(isset($balances[$expense]) ? $balances[$expense]->closing->decimal() : '0.00')->toBe('0.00')->and($balances[$surplus]->closing->decimal())->toBe('900.00');
    $journal = $this->ledger->incomeTax->deferredHistory($snapshot, $id)[1]->journalId;
    expect($journal)->not->toBeNull();
    $this->storage->run(Permission::Read, function () use ($journal) {
        $posting = $this->storage->table('ledger_postings')->where('journal_id', $journal)->whereNotNull('reporting_movement')->first();
        expect(json_decode(text(row($posting)->reporting_movement), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['kind' => 'oci', 'oci_category' => 'non_recyclable']);
    }, $this->book);
});

it('keeps posted deferred tax outside current tax source profit without repeating its role', function () {
    $deferred = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'deferred', $deferred, 'temporary:equipment', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $id, 'deferred', '2026-01-01');
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('1000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)),
    ]));
    $current = incomeTaxConfiguration($this->ledger, $this->book);
    $tax = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'current', $current, '2026-01-01', 'trade', [], [], [], 'Reviewed computation'));
    expect($this->ledger->incomeTax->get($this->book, $tax)->accountingProfit->decimal())->toBe('1000.00')
        ->and($this->ledger->incomeTax->get($this->book, $tax)->charge->decimal())->toBe('300.00');
});

it('measures deferred tax at the rate for the expected manner of recovery', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, changes: ['rates' => ['use' => '0.30', 'sale' => '0.15']]);
    $sale = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'sale', $configuration, 'temporary:building',
        deferredTaxInputs(['recovery_manner' => 'sale', 'attributions' => [['category' => 'profit', 'amount_minor' => '7500', 'reference' => 'Recovery through sale']]])));
    expect($this->ledger->incomeTax->deferred($this->book, $sale)->liability->decimal())->toBe('75.00');
    postDeferredTax($this->ledger, $this->book, $sale, 'sale', '2026-01-01');
    $use = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'use', $configuration, 'temporary:building',
        deferredTaxInputs(['attributions' => [['category' => 'profit', 'amount_minor' => '7500', 'reference' => 'Expected recovery changed to use']]])));
    expect($this->ledger->incomeTax->deferred($this->book, $use)->liability->decimal())->toBe('150.00')
        ->and($this->ledger->incomeTax->deferred($this->book, $use)->charge->decimal())->toBe('75.00');
});

it('gives an expired tax loss no deductible deferred tax capacity and requires the income tax rules to decide expiry', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'old-loss', 'trade', '2019-12-31', Money::of('1000', $this->kes), 'Reviewed 2019 assessment');
    $inputs = deferredTaxInputs(['kind' => 'loss', 'loss_id' => $loss, 'carrying_minor' => null, 'tax_base_minor' => null, 'attributions' => []]);
    $request = fn (string $key, string $recognize = '0'): DeferredTaxRequest => new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), $key, $configuration, 'loss:'.$loss, [...$inputs, 'recognize_minor' => $recognize]);
    expect(fn () => $this->ledger->incomeTax->prepareDeferred($request('no-rules')))->toThrow(QueryException::class, 'effective income tax configuration');
    incomeTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred($request('expired'));
    expect($this->ledger->incomeTax->deferred($this->book, $id)->taxBase->decimal())->toBe('0.00')
        ->and($this->ledger->incomeTax->deferred($this->book, $id)->unrecognizedAsset->decimal())->toBe('0.00');
    expect(fn () => $this->ledger->incomeTax->prepareDeferred($request('recognized', '1')))->toThrow(QueryException::class, 'exceeds eligible');
});

it('fails the year-end deferred tax check for unremeasured and re-rated positions', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'provision', $configuration, 'temporary:provision', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $id, 'provision', '2026-12-31');
    $check = fn (string $year): array => array_column($this->ledger->yearEnd->checklist($this->book, $year.'-01-01', $year.'-12-31')['checks'], null, 'check')['deferred_tax_remeasured'];
    expect($check('2026')['status'])->toBe('pass')
        ->and($check('2027')['items'])->toBe(['temporary:provision:not_remeasured']);
    deferredTaxConfiguration($this->ledger, $this->book, '2', ['rates' => ['use' => '0.25']]);
    expect($check('2026')['items'])->toBe(['temporary:provision:rate_changed']);
});

it('rejects malformed deferred tax decisions and nonconserving source attributions', function (string $field, mixed $value, string $message) {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    expect(fn () => $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'tax', $configuration,
        'temporary:provision', deferredTaxInputs([$field => $value]))))->toThrow(QueryException::class, $message);
})->with([
    'missing recognition' => ['recognize_minor', null, 'exact bounded'],
    'unsupported recognition' => ['recognize_minor', '1', 'exceeds eligible'],
    'missing decision reference' => ['recognition_reference', '', 'complete reviewed'],
    'implicit exemption' => ['exempt', null, 'complete reviewed'],
    'numeric carrying' => ['carrying_minor', 100000, 'exact bounded'],
    'negative base' => ['tax_base_minor', '-1', 'exact bounded'],
    'unknown kind' => ['kind', 'provision', 'complete reviewed'],
    'missing attribution' => ['attributions', [], 'conserve'],
    'nonconserving attribution' => ['attributions', [['category' => 'profit', 'amount_minor' => '14999', 'reference' => 'Wrong total']], 'conserve'],
    'unmapped attribution' => ['attributions', [['category' => 'unknown', 'amount_minor' => '15000', 'reference' => 'Unknown source']], 'explicit profit'],
    'opening off a period start' => ['opening', true, 'start of an accounting period'],
    'extra field' => ['guessed_rate', '0.3', 'complete reviewed'],
    'unconfigured recovery manner' => ['recovery_manner', 'sale', 'recovery manner'],
    'allowance before an opening basis' => ['allowance_minor', '100', 'after its opening measurement'],
    'missing recovery manner' => ['recovery_manner', null, 'recovery manner'],
]);

it('computes deductible liability differences and explicit exemptions without generating an automatic tax asset', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $inputs = deferredTaxInputs(['kind' => 'liability', 'carrying_minor' => '100000', 'tax_base_minor' => '0', 'attributions' => []]);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'unrecognized-provision', $configuration, 'temporary:provision', $inputs));
    $position = $this->ledger->incomeTax->deferred($this->book, $id);
    expect($position->temporaryDifference->decimal())->toBe('-1000.00')->and($position->asset->decimal())->toBe('0.00')->and($position->unrecognizedAsset->decimal())->toBe('300.00');
    postDeferredTax($this->ledger, $this->book, $id, 'unrecognized-provision', '2026-01-01');
    $this->ledger->incomeTax->reverseDeferred($this->book, $id, 'withdraw', '2026-01-01', 'Withdraw decision');
    expect($this->ledger->incomeTax->deferredPositions($this->ledger->reports->snapshot($this->book, '2026-01-01')))->toBe([]);
    $exempt = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-02'), 'exempt', $configuration, 'temporary:exempt',
        deferredTaxInputs(['exempt' => true, 'attributions' => []])));
    expect($this->ledger->incomeTax->deferred($this->book, $exempt)->liability->decimal())->toBe('0.00');
});

it('rejects stale deferred tax measurements and preserves effective date capacity after future corrections', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $ids = [];
    foreach (['a', 'b'] as $key) {
        $ids[] = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), $key, $configuration, 'temporary:equipment', deferredTaxInputs()));
        $this->ledger->incomeTax->reviewDeferred($this->book, $ids[array_key_last($ids)], $key.':approve', '2026-01-01', true, 'Approve', []);
    }
    $this->ledger->incomeTax->postDeferred($this->book, $ids[0], 'a:post', '2026-01-01', 'Post');
    expect(fn () => $this->ledger->incomeTax->postDeferred($this->book, $ids[1], 'b:post', '2026-01-01', 'Post'))->toThrow(QueryException::class, 'chronology');
    $this->ledger->incomeTax->reverseDeferred($this->book, $ids[0], 'future-correction', '2026-02-01', 'Withdraw');
    $replacement = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-02-01'), 'replacement', $configuration, 'temporary:equipment', deferredTaxInputs()));
    $this->ledger->incomeTax->reviewDeferred($this->book, $replacement, 'replacement:approve', '2026-02-01', true, 'Approve', []);
    expect(fn () => $this->ledger->incomeTax->postDeferred($this->book, $ids[1], 'backdated', '2026-01-01', 'Duplicate historical balance'))->toThrow(QueryException::class, 'backdate');
    $this->ledger->incomeTax->postDeferred($this->book, $replacement, 'replacement:post', '2026-02-01', 'Replacement');
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
});

it('pins deferred tax policy and corrects its journal after rule changes and book archival', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'tax', $configuration, 'temporary:equipment', deferredTaxInputs()));
    deferredTaxConfiguration($this->ledger, $this->book, '2', ['rates' => ['use' => '0.40']], '2026-02-01');
    postDeferredTax($this->ledger, $this->book, $id, 'tax', '2026-01-01');
    expect($this->ledger->incomeTax->deferred($this->book, $id)->liability->decimal())->toBe('150.00');
    $this->ledger->journals->post(new JournalRequest($this->book, 'later-activity', '2026-01-20', 'Later journal', [
        new Posting($this->cash, Side::Debit, Money::of('10', $this->kes)), new Posting($this->income, Side::Credit, Money::of('10', $this->kes)),
    ]));
    $this->ledger->catalog->deleteBook($this->book);
    $this->ledger->incomeTax->reverseDeferred($this->book, $id, 'correct', '2026-01-15', 'Correct archived source');
    expect($this->ledger->incomeTax->deferredPositions($this->ledger->reports->snapshot($this->book, '2026-01-31')))->toBe([]);
});

it('posts deferred tax recognised directly in equity and on prior-period restatements', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $movements = [];
    foreach (['equity_contribution' => 'contribution', 'opening_adjustment' => 'opening_adjustment'] as $category => $kind) {
        $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-03-31'), $category, $configuration, 'temporary:'.$category,
            deferredTaxInputs(['attributions' => [['category' => $category, 'amount_minor' => '15000', 'reference' => 'Reviewed '.$category]]])));
        postDeferredTax($this->ledger, $this->book, $id, $category, '2026-03-31');
        $journal = text($this->ledger->incomeTax->deferredHistory($this->ledger->reports->snapshot($this->book, '2026-03-31'), $id)[1]->journalId);
        $movements[$kind] = $this->storage->run(Permission::Read, fn () => text($this->storage->table('ledger_postings')->where('journal_id', $journal)->whereNotNull('reporting_movement')
            ->value('reporting_movement')), $this->book);
    }
    expect(json_decode($movements['contribution'], true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['kind' => 'contribution'])
        ->and(json_decode($movements['opening_adjustment'], true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['kind' => 'opening_adjustment']);
});

it('gives a tax loss no deferred tax capacity once no later assessment can use it', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    incomeTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'expiring', 'trade', '2021-12-31', Money::of('1000', $this->kes), 'Reviewed 2021 assessment');
    $basis = fn (string $date): string => $this->ledger->incomeTax->deferred($this->book, $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest(
        $this->ledger->reports->snapshot($this->book, $date), 'loss:'.$date, $configuration, 'loss:'.$loss,
        deferredTaxInputs(['kind' => 'loss', 'loss_id' => $loss, 'carrying_minor' => null, 'tax_base_minor' => null, 'attributions' => []]))))->taxBase->decimal();
    expect($basis('2026-12-30'))->toBe('1000.00')->and($basis('2026-12-31'))->toBe('0.00')
        ->and($this->ledger->incomeTax->losses($this->ledger->reports->snapshot($this->book, '2026-12-31'))[0]->remaining->decimal())->toBe('1000.00')
        ->and($this->ledger->incomeTax->losses($this->ledger->reports->snapshot($this->book, '2027-01-31'))[0]->remaining->decimal())->toBe('0.00');
});

it('remeasures deferred tax under a later configuration that adds attribution roles and changes the rate', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, changes: ['attributions' => ['profit' => 'tax.income.deferred']]);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'first', $configuration, 'temporary:plant', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $id, 'first', '2026-01-31');
    $revised = deferredTaxConfiguration($this->ledger, $this->book, '2', ['rates' => ['use' => '0.25']]);
    $next = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'second', $revised, 'temporary:plant',
        deferredTaxInputs(['attributions' => [['category' => 'profit', 'amount_minor' => '-2500', 'reference' => 'Enacted rate change']]])));
    expect($this->ledger->incomeTax->deferred($this->book, $next)->liability->decimal())->toBe('125.00');
});

it('refuses deferred tax balance accounts that have ever held other postings', function () {
    $asset = $this->ledger->catalog->account($this->book, '1800', 'Deferred tax asset', AccountType::Asset);
    $legacy = $this->ledger->journals->post(new JournalRequest($this->book, 'legacy', '2026-01-15', 'Legacy deferred tax', [
        new Posting($asset, Side::Debit, Money::of('100', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('100', $this->kes)),
    ]));
    expect(fn () => deferredTaxConfiguration($this->ledger, $this->book))->toThrow(QueryException::class, 'must be dedicated');
    $this->ledger->journals->reverse($this->book, $legacy->id, 'clear-legacy', '2026-01-16', 'Cleared legacy balance');
    expect(fn () => deferredTaxConfiguration($this->ledger, $this->book))->toThrow(QueryException::class, 'must be dedicated');
});

it('stops asking for remeasurement of settled or exempt deferred tax sources', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $first = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-06-30'), 'provision', $configuration, 'temporary:provision', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $first, 'provision', '2026-06-30');
    $settled = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'settled', $configuration, 'temporary:provision',
        deferredTaxInputs(['tax_base_minor' => '100000', 'attributions' => [['category' => 'profit', 'amount_minor' => '-15000', 'reference' => 'Provision settled']]])));
    postDeferredTax($this->ledger, $this->book, $settled, 'settled', '2026-12-31');
    $exempt = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'exempt', $configuration, 'temporary:goodwill',
        deferredTaxInputs(['exempt' => true, 'attributions' => []])));
    postDeferredTax($this->ledger, $this->book, $exempt, 'exempt', '2026-12-31');
    deferredTaxConfiguration($this->ledger, $this->book, '2', ['rates' => ['use' => '0.25']]);
    expect(array_column($this->ledger->yearEnd->checklist($this->book, '2027-01-01', '2027-12-31')['checks'], null, 'check')['deferred_tax_remeasured']['status'])->toBe('pass');
});

it('keeps deferred tax recycled out of OCI outside current tax source profit', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, changes: ['attributions' => ['profit' => 'tax.income.deferred', 'oci_recyclable' => 'tax.surplus']]);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'hedge', $configuration, 'temporary:hedge',
        deferredTaxInputs(['attributions' => [['category' => 'oci_recyclable', 'amount_minor' => '15000', 'reference' => 'Cash flow hedge reserve']]])));
    postDeferredTax($this->ledger, $this->book, $id, 'hedge', '2026-01-31');
    $journal = text($this->ledger->incomeTax->deferredHistory($this->ledger->reports->snapshot($this->book, '2026-01-31'), $id)[1]->journalId);
    $line = $this->storage->run(Permission::Read, fn (): int => (int) text($this->storage->table('ledger_postings')->where('journal_id', $journal)->whereNotNull('reporting_movement')
        ->selectRaw('line_no::text AS line')->value('line')), $this->book);
    expect(fn () => $this->ledger->journals->recycleOci($this->book, 'recycle-into-revenue', $journal, $line, '2026-06-30', Money::of('100', $this->kes),
        $this->income, 'reviewed:1', 'Recycled into revenue'))->toThrow(QueryException::class, 'goes to an income tax expense account');
    $stock = $this->ledger->catalog->account($this->book, '1300', 'Inventory', AccountType::Asset);
    $basis = new ReportingMovement('basis_adjustment', 'reviewed:1', 'Tax moved into stock', 'recyclable', $journal, $line);
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'basis-into-stock', '2026-06-30', 'Deferred tax into inventory', [
        new Posting($this->ledger->configurations->account($this->book, $configuration, 'tax.surplus'), Side::Credit, Money::of('100', $this->kes), reportingMovement: $basis),
        new Posting($stock, Side::Debit, Money::of('100', $this->kes), reportingMovement: $basis),
    ])))->toThrow(QueryException::class, 'cannot be moved into an asset or liability basis');
    $this->ledger->journals->recycleOci($this->book, 'recycle-hedge-tax', $journal, $line, '2026-06-30', Money::of('150', $this->kes),
        $this->ledger->configurations->account($this->book, $configuration, 'tax.income.deferred'), 'reviewed:1', 'Hedged item affects profit');
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('1000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)),
    ]));
    $current = incomeTaxConfiguration($this->ledger, $this->book);
    $tax = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'current', $current, '2026-01-01', 'trade', [], [], [], 'Reviewed'));
    expect($this->ledger->incomeTax->get($this->book, $tax)->accountingProfit->decimal())->toBe('1000.00');
});

it('refuses to post deferred tax measured under a configuration superseded for its date', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'tax', $configuration, 'temporary:plant', deferredTaxInputs()));
    $this->ledger->incomeTax->reviewDeferred($this->book, $id, 'review', '2026-01-31', true, 'Approve', []);
    deferredTaxConfiguration($this->ledger, $this->book, '2', ['rates' => ['use' => '0.25']]);
    expect(fn () => $this->ledger->incomeTax->postDeferred($this->book, $id, 'post', '2026-01-31', 'Superseded rate'))->toThrow(QueryException::class, 'superseded configuration');
});

it('asks for remeasurement of a difference carried at a zero rate once the rate changes', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, changes: ['rates' => ['use' => '0']]);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'zero-rate', $configuration, 'temporary:plant',
        deferredTaxInputs(['attributions' => []])));
    postDeferredTax($this->ledger, $this->book, $id, 'zero-rate', '2026-12-31');
    deferredTaxConfiguration($this->ledger, $this->book, '2', validFrom: '2027-01-01');
    expect(array_column($this->ledger->yearEnd->checklist($this->book, '2027-01-01', '2027-12-31')['checks'], null, 'check')['deferred_tax_remeasured']['items'])
        ->toBe(['temporary:plant:not_remeasured', 'temporary:plant:rate_changed']);
});

it('prepares deferred tax only under the latest configuration known at the cutoff whose posting accounts are book level', function () {
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $request = fn (Snapshot $snapshot, string $key, string $configuration): DeferredTaxRequest => new DeferredTaxRequest($snapshot, $key, $configuration, 'temporary:plant', deferredTaxInputs());
    expect(fn () => $this->ledger->incomeTax->prepareDeferred($request($snapshot, 'unknown', $configuration)))->toThrow(QueryException::class, 'latest effective configuration known');
    $newer = deferredTaxConfiguration($this->ledger, $this->book, '2');
    expect(fn () => $this->ledger->incomeTax->prepareDeferred($request($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'superseded', $configuration)))
        ->toThrow(QueryException::class, 'latest effective configuration known');
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $this->ledger->configurations->account($this->book, $newer, 'tax.income.deferred'), 'campus');
    expect(fn () => $this->ledger->incomeTax->prepareDeferred($request($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'dimensioned', $newer)))
        ->toThrow(QueryException::class, 'cannot require dimensions');
});

it('treats a renamed source loss as unavailable for deferred tax unless the old source keeps its carry-forward rule', function () {
    incomeTaxConfiguration($this->ledger, $this->book);
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'trade-loss', 'trade', '2025-12-31', Money::of('1000', $this->kes), 'Reviewed trade loss');
    $inputs = deferredTaxInputs(['kind' => 'loss', 'loss_id' => $loss, 'carrying_minor' => null, 'tax_base_minor' => null, 'attributions' => []]);
    $basis = fn (string $date): string => $this->ledger->incomeTax->deferred($this->book, $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest(
        $this->ledger->reports->snapshot($this->book, $date), 'loss:'.$date, $configuration, 'loss:'.$loss, $inputs)))->taxBase->decimal();
    $business = ['rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => 5, 'loss_sources' => ['business'], 'profit_roles' => ['profit.revenue']];
    incomeTaxConfiguration($this->ledger, $this->book, 'renamed', ['sources' => ['business' => $business]]);
    expect($basis('2026-02-28'))->toBe('0.00');
    incomeTaxConfiguration($this->ledger, $this->book, 'kept', ['sources' => ['business' => [...$business, 'loss_sources' => ['business', 'trade']],
        'trade' => ['rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => 5, 'loss_sources' => [], 'profit_roles' => []]]]);
    expect($basis('2026-03-31'))->toBe('1000.00');
    incomeTaxConfiguration($this->ledger, $this->book, 'incomplete', ['sources' => ['trade' => ['rate' => '0.30', 'rounding' => 'half_even', 'loss_sources' => ['trade'], 'profit_roles' => ['profit.revenue']]]]);
    expect(fn () => $basis('2026-04-30'))->toThrow(QueryException::class, 'Each income source requires');
});

it('measures every opening deferred tax position on one adoption date at a period start, even after earlier periods close', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $opening = fn (string $date, string $source): string => $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, $date), $source, $configuration,
        'temporary:'.$source, deferredTaxInputs(['opening' => true, 'attributions' => [['category' => 'opening', 'amount_minor' => '15000', 'reference' => 'Imported']]])));
    $this->ledger->periods->close($this->book, $this->period);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    postDeferredTax($this->ledger, $this->book, $opening('2027-01-01', 'plant'), 'plant', '2027-01-01');
    $this->ledger->periods->open($this->book, '2025', '2025-01-01', '2025-12-31');
    expect(fn () => $opening('2025-01-01', 'vehicles'))->toThrow(QueryException::class, 'one adoption date');
});

it('refuses an opening adoption date after the register is in use or after an earlier adoption', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $measure = fn (string $date, string $source, bool $opening): string => $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, $date),
        $source.':'.$date, $configuration, 'temporary:'.$source, deferredTaxInputs($opening ? ['opening' => true, 'attributions' => [['category' => 'opening', 'amount_minor' => '15000', 'reference' => 'Imported']]] : [])));
    $withdrawn = $measure('2026-01-01', 'adopted', true);
    postDeferredTax($this->ledger, $this->book, $withdrawn, 'adopted', '2026-01-01');
    $this->ledger->incomeTax->reverseDeferred($this->book, $withdrawn, 'withdraw-adoption', '2026-01-01', 'Withdraw adoption');
    expect(fn () => $measure('2027-01-01', 'late', true))->toThrow(QueryException::class, 'one adoption date');
    $ordinary = $measure('2026-01-31', 'ordinary', false);
    postDeferredTax($this->ledger, $this->book, $ordinary, 'ordinary', '2026-01-31');
    expect(fn () => $measure('2027-01-01', 'in-use', true))->toThrow(QueryException::class, 'before any other measurement')
        ->and($measure('2026-01-01', 'discovered', true))->toBeString();
});

it('reports an invalid latest deferred tax configuration instead of rate changes', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-06-30'), 'plant', $configuration, 'temporary:plant', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $id, 'plant', '2026-06-30');
    $this->ledger->configurations->install($this->book, new Pack('broken-deferred-tax', '1', settings: ['deferred_tax' => ['rates' => 'x']]));
    expect(array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check')['deferred_tax_remeasured']['items'])
        ->toBe(['deferred_tax:invalid_configuration']);
});

it('reports a missing deferred tax configuration once instead of rate changes', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, validUntil: '2026-12-31');
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'plant', $configuration, 'temporary:plant', deferredTaxInputs()));
    postDeferredTax($this->ledger, $this->book, $id, 'plant', '2026-12-31');
    expect(array_column($this->ledger->yearEnd->checklist($this->book, '2027-01-01', '2027-12-31')['checks'], null, 'check')['deferred_tax_remeasured']['items'])
        ->toBe(['deferred_tax:missing_configuration', 'temporary:plant:not_remeasured']);
});

it('reports a loss basis that a malformed income tax configuration cannot determine instead of failing the checklist', function () {
    $current = incomeTaxConfiguration($this->ledger, $this->book);
    $configuration = deferredTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'trade-loss', 'trade', '2025-12-31', Money::of('1000', $this->kes), 'Reviewed trade loss');
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-06-30'), 'loss', $configuration, 'loss:'.$loss,
        deferredTaxInputs(['kind' => 'loss', 'loss_id' => $loss, 'carrying_minor' => null, 'tax_base_minor' => null, 'attributions' => []])));
    postDeferredTax($this->ledger, $this->book, $id, 'loss', '2026-06-30');
    $malformed = incomeTaxConfiguration($this->ledger, $this->book, 'malformed', ['loss_extensions' => [$loss => ['through' => '2030-02-30', 'reference' => 'Impossible date']]]);
    expect(array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check')['deferred_tax_remeasured']['items'])
        ->toBe(['loss:'.$loss.':loss_basis_unavailable'])
        ->and(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $malformed, '2026-01-01', 'trade', [], [], [], 'Reviewed')))
        ->toThrow(QueryException::class, 'loss extension requires');
    expect($current)->toBeString();
});

it('accepts only genuine year-end closing entries on income tax expense accounts', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $retained = $this->ledger->catalog->account($this->book, '3100', 'Retained earnings', AccountType::Equity);
    $expense = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.current');
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'pseudo-close', '2026-07-01', 'Closing-flagged reclass', [
        new Posting($expense, Side::Debit, Money::of('400', $this->kes)), new Posting($retained, Side::Credit, Money::of('400', $this->kes)),
    ], closing: true)))->toThrow(QueryException::class, 'take only tax journals');
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('1000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)),
    ]));
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration, '2026-01-01', 'trade', [], [], [], 'Reviewed'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Current tax');
    expect($this->ledger->yearEnd->close($this->book, 'close-2026', '2026-01-01', '2026-12-31', $retained, 'Annual close'))->toBeString();
});

it('refuses deferred tax expense roles on accounts that already carry operating postings', function () {
    $depreciation = $this->ledger->catalog->account($this->book, '6100', 'Depreciation', AccountType::Expense);
    $this->ledger->journals->post(new JournalRequest($this->book, 'depreciation', '2026-01-31', 'Depreciation', [
        new Posting($depreciation, Side::Debit, Money::of('2000', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('2000', $this->kes)),
    ]));
    expect(fn () => $this->ledger->configurations->install($this->book, new Pack('misbound', '1', roles: ['tax.income.deferred' => '6100'], settings: ['deferred_tax' => ['version' => 'draft']])))
        ->toThrow(QueryException::class, 'hold only tax journals');
});

it('enforces a separate deferred tax reviewer and immutable rejection', function () {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, changes: ['separate_reviewer' => true]);
    $id = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'tax', $configuration, 'temporary:equipment', deferredTaxInputs()));
    expect(fn () => $this->ledger->incomeTax->reviewDeferred($this->book, $id, 'self-approve', '2026-01-01', true, 'Approve', []))->toThrow(QueryException::class, 'separate reviewer');
    $reviewer = (new Ledger($this->connection(), new PermitAll))->for(new Scope($this->scope->tenantId, 'reviewer'));
    $reviewer->incomeTax->reviewDeferred($this->book, $id, 'reject', '2026-01-01', false, 'Insufficient recognition evidence', []);
    expect(fn () => $reviewer->incomeTax->reviewDeferred($this->book, $id, 'approve-later', '2026-01-01', true, 'Approve', []))->toThrow(QueryException::class, 'review is final');
    expect(fn () => $this->ledger->incomeTax->postDeferred($this->book, $id, 'post-rejected', '2026-01-01', 'Post'))->toThrow(QueryException::class, 'requires approval');
});

it('rejects incomplete deferred tax policies and ambiguous capital allowance classes', function (string $field, mixed $value) {
    $configuration = deferredTaxConfiguration($this->ledger, $this->book, changes: [$field => $value]);
    expect(fn () => $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-01-01'), 'tax', $configuration,
        'temporary:equipment', deferredTaxInputs())))->toThrow(QueryException::class);
})->with([
    ['rates', ['use' => 0]], ['rates', ['use' => '1.01']], ['rates', []], ['rates', ['' => '0.30']], ['rounding', null], ['separate_reviewer', null],
    ['unknown', true], ['attributions', ['profit' => 'missing.role']],
    ['asset_classes', ['equipment' => [['from' => '2026-01-01', 'until' => '2026-12-31', 'method' => 'guessed', 'rate' => '0.1', 'initial_rate' => '0']]]],
    ['asset_classes', ['equipment' => [
        ['from' => '2026-01-01', 'until' => '2026-06-30', 'method' => 'straight_line', 'rate' => '0.1', 'initial_rate' => '0'],
        ['from' => '2026-06-30', 'until' => '2026-12-31', 'method' => 'straight_line', 'rate' => '0.2', 'initial_rate' => '0'],
    ]]],
]);

it('computes reviewed current income tax with exact loss usage payments and correction evidence', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.payable');
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-30', 'Accounting profit', [
        new Posting($this->cash, Side::Debit, Money::of('10000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('10000', $this->kes)),
    ]));
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'instalment', '2026-09-01', 'Booked instalment', [
        new Posting($payable, Side::Debit, Money::of('2000', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('2000', $this->kes)),
    ]));
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'opening-loss', 'trade', '2025-12-31', Money::of('2000', $this->kes), 'Reviewed 2025 assessment');
    $request = new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax:2026', $configuration, '2026-01-01', 'trade', [
        ['kind' => 'permanent', 'reference' => 'Disallowed expenses', 'amount_minor' => '100000'],
        ['kind' => 'capital_allowance', 'reference' => 'Additional reviewed allowances', 'amount_minor' => '-50000'],
    ], [$loss => '200000'], [['journal_id' => $payment->id, 'line_no' => 1, 'kind' => 'instalment', 'amount_minor' => '200000', 'reference' => 'Instalment receipt']], 'Reviewed tax working paper');
    $id = $this->ledger->incomeTax->prepare($request);
    expect($this->ledger->incomeTax->prepare($request))->toBe($id);
    $computation = $this->ledger->incomeTax->get($this->book, $id);
    expect($computation->accountingProfit->decimal())->toBe('10000.00')->and($computation->taxableProfit->decimal())->toBe('8500.00')
        ->and($computation->charge->decimal())->toBe('2550.00')->and($computation->credits->decimal())->toBe('2000.00')->and($computation->payable->decimal())->toBe('550.00');
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approved working paper', ['review_reference' => 'approval:1']);
    $posted = $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Recognize current income tax');
    expect($this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Recognize current income tax'))->toBe($posted);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->losses($snapshot)[0]->remaining->decimal())->toBe('0.00');
    expect(fn () => $this->ledger->incomeTax->reverseLoss($this->book, $loss, 'withdraw-used-loss', '2026-12-31', 'Incorrect opening'))->toThrow(QueryException::class, 'active tax loss usage');
    $checks = array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check');
    expect($checks['income_tax_reviewed']['status'])->toBe('pass');
    $reconciliation = $this->ledger->incomeTax->reconciliation($snapshot, $id);
    expect($reconciliation->bookedPayable->decimal())->toBe('550.00')->and($reconciliation->difference->decimal())->toBe('0.00')
        ->and($reconciliation->bookedCharge->decimal())->toBe('2550.00')->and($reconciliation->bookedCredits->decimal())->toBe('2000.00');
    $this->ledger->incomeTax->reverse($this->book, $id, 'reverse', '2026-12-31', 'Correct assessment');
    $corrected = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->losses($corrected)[0]->remaining->decimal())->toBe('2000.00')
        ->and($this->ledger->incomeTax->losses($snapshot)[0]->remaining->decimal())->toBe('0.00')
        ->and($this->ledger->incomeTax->history($corrected, $id))->toHaveCount(3)
        ->and($this->ledger->incomeTax->get($this->book, $id)->resultHash)->toBe($computation->resultHash);
});

it('rejects ineligible current income tax loss vintages and source restrictions', function (string $source, string $vintage, string $message) {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'loss', $source, $vintage, Money::of('2000', $this->kes), 'Reviewed opening loss');
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax',
        $configuration, '2026-01-01', 'trade', [], [$loss => '10000'], [], 'Working paper')))->toThrow(QueryException::class, $message);
})->with([['trade', '2020-12-31', 'expired'], ['capital', '2025-12-31', 'not eligible'], ['trade', '2026-01-01', 'not eligible']]);

it('pins current income tax rates loss extensions and reviewed inputs independently of later years rules and refuses superseded rules', function () {
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'expired-loss', 'trade', '2020-12-31', Money::of('2000', $this->kes), 'Extended loss assessment');
    $configuration = incomeTaxConfiguration($this->ledger, $this->book, 'extended', ['loss_extensions' => [$loss => ['through' => '2026-12-31', 'reference' => 'Approved statutory extension']]]);
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-30', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('10000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('10000', $this->kes)),
    ]));
    $request = new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration, '2026-01-01', 'trade', [], [$loss => '200000'], [], 'Reviewed basis');
    $id = $this->ledger->incomeTax->prepare($request);
    $before = $this->ledger->incomeTax->get($this->book, $id);
    incomeTaxConfiguration($this->ledger, $this->book, 'new-law', trade: ['rate' => '0.40', 'loss_years' => 1], validFrom: '2027-01-01');
    $this->ledger->incomeTax->review($this->book, $id, 'approve', '2026-12-31', true, 'Approve original rules', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Post original assessment');
    $replacement = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'replacement', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Replacement under the same rules'));
    $this->ledger->incomeTax->review($this->book, $replacement, 'approve-replacement', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->reverse($this->book, $id, 'withdraw', '2026-12-31', 'Replace');
    incomeTaxConfiguration($this->ledger, $this->book, 'retroactive', trade: ['rate' => '0.35'], changes: ['loss_extensions' => [$loss => ['through' => '2026-12-31', 'reference' => 'Approved statutory extension']]]);
    expect(fn () => $this->ledger->incomeTax->post($this->book, $replacement, 'post-replacement', '2026-12-31', 'Superseded rules'))->toThrow(QueryException::class, 'superseded configuration');
    expect($this->ledger->incomeTax->get($this->book, $id)->charge->decimal())->toBe('2400.00')
        ->and($this->ledger->incomeTax->get($this->book, $id)->policy)->toBe($before->policy)
        ->and($this->ledger->incomeTax->get($this->book, $id)->resultHash)->toBe($before->resultHash);
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($request->snapshot, 'tax', $configuration, '2026-01-01', 'trade', [], [], [], 'Changed inputs')))->toThrow(LedgerException::class, 'different inputs');
});

it('corrects current income tax after later activity rule changes and book archival without rewriting prior cutoffs', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'loss', 'trade', '2025-12-31', Money::of('100', $this->kes), 'Opening loss');
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('1000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)),
    ]));
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [$loss => '10000'], [], 'Reviewed computation'));
    $this->ledger->incomeTax->review($this->book, $id, 'approve', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Current tax');
    $before = $this->ledger->reports->snapshot($this->book, '2027-12-31');
    incomeTaxConfiguration($this->ledger, $this->book, '2027', trade: ['rate' => '0.40']);
    $later = $this->ledger->journals->post(new JournalRequest($this->book, 'later', '2027-01-20', 'Later activity', [
        new Posting($this->cash, Side::Debit, Money::of('1', $this->kes)), new Posting($this->income, Side::Credit, Money::of('1', $this->kes)),
    ]));
    $this->ledger->catalog->deleteBook($this->book);
    $history = $this->ledger->incomeTax->history($before, $id);
    $original = $this->ledger->journals->get($this->book, text($history[1]->journalId));
    expect(fn () => $this->ledger->journals->reverse($this->book, $original->id, 'direct-correction', '2027-01-15', 'Bypass the tax service'))
        ->toThrow(QueryException::class, 'corrected only by the income tax service');
    $afterSequence = $this->ledger->reports->snapshot($this->book, '2027-12-31')->throughSequence;
    $event = $this->ledger->incomeTax->reverse($this->book, $id, 'correction', '2027-01-15', 'Correct before later activity');
    $after = $this->ledger->reports->snapshot($this->book, '2027-01-16');
    $events = $this->ledger->incomeTax->history($after, $id);
    $reversal = $this->ledger->journals->get($this->book, text($events[2]->journalId));
    expect($events[2]->id)->toBe($event)->and($reversal->reversesId)->toBe($original->id)->and($events[2]->sequence)->toBe($reversal->sequence)
        ->and((int) $after->throughSequence)->toBe((int) $afterSequence + 1)
        ->and($this->ledger->incomeTax->losses($after)[0]->remaining->decimal())->toBe('100.00')
        ->and($this->ledger->incomeTax->losses($before)[0]->remaining->decimal())->toBe('0.00')
        ->and($this->ledger->incomeTax->reconciliation($after, $id)->difference->decimal())->toBe('0.00');
    expect($this->ledger->incomeTax->reverse($this->book, $id, 'correction', '2027-01-15', 'Correct before later activity'))->toBe($event);
    expect($this->ledger->journals->get($this->book, $later->id)->effectiveOn)->toBe('2027-01-20');
});

it('requires final current income tax review and rejects stale profit before posting', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $request = new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration, '2026-01-01', 'trade', [], [], [], 'Reviewed basis');
    $id = $this->ledger->incomeTax->prepare($request);
    expect(fn () => $this->ledger->incomeTax->post($this->book, $id, 'unapproved', '2026-12-31', 'Unapproved'))->toThrow(QueryException::class, 'prior approval');
    $review = $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    expect($this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []))->toBe($review);
    expect(fn () => $this->ledger->incomeTax->review($this->book, $id, 'reject', '2026-12-31', false, 'Too late', []))->toThrow(QueryException::class, 'review is final');
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-profit', '2026-12-31', 'Late profit', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    expect(fn () => $this->ledger->incomeTax->post($this->book, $id, 'stale', '2026-12-31', 'Stale computation'))->toThrow(QueryException::class, 'stale');
    $checks = array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check');
    expect($checks['income_tax_reviewed']['status'])->toBe('fail');
});

it('carries a current income tax loss forward and corrects dependent usage before withdrawing its origin', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'loss-year', $configuration,
        '2026-01-01', 'trade', [['kind' => 'temporary', 'reference' => 'Reviewed deductible adjustment', 'amount_minor' => '-100000']], [], [], 'Loss-year review'));
    $this->ledger->incomeTax->review($this->book, $id, 'approve-loss', '2026-12-31', true, 'Approve tax loss', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post-loss', '2026-12-31', 'Recognize loss vintage');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $loss = $this->ledger->incomeTax->losses($snapshot)[0];
    expect(fn () => $this->ledger->incomeTax->reverse($this->book, $id, 'too-early', '2026-12-30', 'Backdate before original'))->toThrow(QueryException::class, 'on or after its date');
    expect($loss->amount->decimal())->toBe('1000.00')->and($loss->computationId)->toBe($id)
        ->and($loss->sequence)->toBe($this->ledger->incomeTax->history($snapshot, $id)[1]->sequence);
    $this->ledger->journals->post(new JournalRequest($this->book, '2027-profit', '2027-12-30', 'Next year profit', [
        new Posting($this->cash, Side::Debit, Money::of('2000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('2000', $this->kes)),
    ]));
    $next = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2027-12-31'), 'profit-year', $configuration,
        '2027-01-01', 'trade', [], [$loss->id => '100000'], [], 'Next year review'));
    $this->ledger->incomeTax->review($this->book, $next, 'approve-next', '2027-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $next, 'post-next', '2027-12-31', 'Recognize current tax');
    expect($this->ledger->incomeTax->get($this->book, $next)->charge->decimal())->toBe('300.00');
    expect(fn () => $this->ledger->incomeTax->reverse($this->book, $id, 'dependent', '2027-01-15', 'Withdraw old computation'))->toThrow(QueryException::class, 'dependent loss usage');
    $this->ledger->incomeTax->reverse($this->book, $next, 'undo-next', '2027-12-31', 'Withdraw use of loss');
    $this->ledger->catalog->deleteBook($this->book);
    $this->ledger->incomeTax->reverse($this->book, $id, 'undo-origin', '2027-01-15', 'Correct origin before later events');
    expect($this->ledger->incomeTax->losses($this->ledger->reports->snapshot($this->book, '2027-12-31'))[0]->remaining->decimal())->toBe('0.00')
        ->and($this->ledger->incomeTax->losses($snapshot)[0]->remaining->decimal())->toBe('1000.00');
});

it('corrects opening current income tax losses exactly while protecting active allocations', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('1000', $this->kes), 'Opening assessment');
    expect($this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('1000', $this->kes), 'Opening assessment'))->toBe($loss);
    expect(fn () => $this->ledger->incomeTax->reverseLoss($this->book, $loss, 'early', '2025-12-30', 'Too early'))->toThrow(QueryException::class, 'exactly reverse its original');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $reversal = $this->ledger->incomeTax->reverseLoss($this->book, $loss, 'correct', '2026-01-01', 'Correct imported loss');
    expect($this->ledger->incomeTax->reverseLoss($this->book, $loss, 'correct', '2026-01-01', 'Correct imported loss'))->toBe($reversal);
    $after = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->losses($after)[0]->remaining->decimal())->toBe('0.00')
        ->and($this->ledger->incomeTax->losses($before)[0]->remaining->decimal())->toBe('1000.00')
        ->and($this->ledger->incomeTax->losses($after, true)[1]->reversesId)->toBe($loss);
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($after, 'withdrawn-loss', $configuration, '2026-01-01', 'trade', [], [$loss => '1'], [], 'Invalid usage')))->toThrow(QueryException::class, 'corrected');
});

it('reconciles current income tax payments after correction without blocking the payment reversal', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.payable');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'payment', '2026-09-01', 'Tax paid', [
        new Posting($payable, Side::Debit, Money::of('20', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('20', $this->kes)),
    ]));
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration, '2026-01-01', 'trade', [], [], [
        ['journal_id' => $payment->id, 'line_no' => 1, 'kind' => 'withholding', 'amount_minor' => '2000', 'reference' => 'Withholding certificate'],
    ], 'Reviewed payment'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve refund position', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Current tax zero');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->reconciliation($before, $id)->bookedPayable->decimal())->toBe('-20.00');
    $this->ledger->journals->reverse($this->book, $payment->id, 'correct-payment', '2026-12-31', 'Wrong withholding certificate');
    $after = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->reconciliation($after, $id)->difference->decimal())->toBe('20.00')
        ->and($this->ledger->incomeTax->reconciliation($before, $id)->difference->decimal())->toBe('0.00');
    $checks = array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check');
    expect($checks['income_tax_reviewed']['items'])->toContain('trade:reversed_tax_payment');
});

it('prevents future current income tax corrections from freeing losses at an earlier effective cutoff', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $this->ledger->periods->open($this->book, '2028', '2028-01-01', '2028-12-31');
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('1500', $this->kes), 'Opening losses');
    foreach (['2026', '2027'] as $year) {
        $this->ledger->journals->post(new JournalRequest($this->book, 'profit-'.$year, $year.'-12-30', 'Profit', [
            new Posting($this->cash, Side::Debit, Money::of('2000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('2000', $this->kes)),
        ]));
    }
    $first = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax-2026', $configuration,
        '2026-01-01', 'trade', [], [$loss => '100000'], [], 'Reviewed 2026'));
    $this->ledger->incomeTax->review($this->book, $first, 'review-2026', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $first, 'post-2026', '2026-12-31', 'Current tax');
    $this->ledger->incomeTax->reverse($this->book, $first, 'reverse-2026', '2028-01-20', 'Future correction');
    $second = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2027-12-31'), 'tax-2027', $configuration,
        '2027-01-01', 'trade', [], [$loss => '100000'], [], 'Reviewed 2027'));
    $this->ledger->incomeTax->review($this->book, $second, 'review-2027', '2027-12-31', true, 'Approve', []);
    expect(fn () => $this->ledger->incomeTax->post($this->book, $second, 'backdated-use', '2027-12-31', 'Overlapping use'))->toThrow(QueryException::class, 'effective-date cutoff');
    $this->ledger->incomeTax->post($this->book, $second, 'post-after-correction', '2028-01-20', 'Use released loss');
    $mid = $this->ledger->reports->snapshot($this->book, '2028-01-19');
    $after = $this->ledger->reports->snapshot($this->book, '2028-01-20');
    expect($this->ledger->incomeTax->losses($mid)[0]->remaining->decimal())->toBe('500.00')->and($this->ledger->incomeTax->losses($after)[0]->remaining->decimal())->toBe('500.00');
});

it('refuses to use a generated current income tax loss on a date before its origin is posted', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    foreach (['2027', '2028'] as $year) {
        $this->ledger->periods->open($this->book, $year, $year.'-01-01', $year.'-12-31');
    }
    $origin = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'loss-year', $configuration,
        '2026-01-01', 'trade', [['kind' => 'temporary', 'reference' => 'Reviewed deduction', 'amount_minor' => '-100000']], [], [], 'Loss-year review'));
    $this->ledger->incomeTax->review($this->book, $origin, 'approve-loss', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $origin, 'post-loss', '2028-03-01', 'Late-dated loss recognition');
    $loss = $this->ledger->incomeTax->losses($this->ledger->reports->snapshot($this->book, '2028-03-01'))[0];
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit-2027', '2027-12-30', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('2000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('2000', $this->kes)),
    ]));
    $next = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2027-12-31'), 'profit-year', $configuration,
        '2027-01-01', 'trade', [], [$loss->id => '100000'], [], 'Next year review'));
    $this->ledger->incomeTax->review($this->book, $next, 'approve-next', '2027-12-31', true, 'Approve', []);
    expect(fn () => $this->ledger->incomeTax->post($this->book, $next, 'early-use', '2027-12-31', 'Use before origin'))->toThrow(QueryException::class, 'before its originating computation is posted');
    $this->ledger->incomeTax->post($this->book, $next, 'later-use', '2028-03-01', 'Use once recognized');
    expect($this->ledger->incomeTax->losses($this->ledger->reports->snapshot($this->book, '2028-03-01'))[0]->remaining->decimal())->toBe('0.00');
});

it('reports a current income tax loss with a known future-dated withdrawal as unavailable, matching preparation', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('1000', $this->kes), 'Opening assessment');
    $this->ledger->incomeTax->reverseLoss($this->book, $loss, 'withdraw', '2027-06-30', 'Assessment withdrawn');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->losses($snapshot)[0]->amount->decimal())->toBe('1000.00')
        ->and($this->ledger->incomeTax->losses($snapshot)[0]->remaining->decimal())->toBe('0.00');
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($snapshot, 'tax', $configuration, '2026-01-01', 'trade', [], [$loss => '100'], [], 'Uses withdrawn loss')))
        ->toThrow(QueryException::class, 'corrected');
});

it('applies each income source its own rate and keeps income tax expense accounts dedicated and out of source profit', function () {
    $pack = fn (string $version, array $sources): Pack => new Pack('multi-source-tax', $version, accounts: [
        ['code' => '2300', 'name' => 'Current income tax payable', 'type' => 'liability'], ['code' => '4100', 'name' => 'Capital gains', 'type' => 'income'],
        ['code' => '5300', 'name' => 'Current income tax expense', 'type' => 'expense'], ['code' => '5310', 'name' => 'Deferred tax expense', 'type' => 'expense'],
    ], roles: ['tax.income.current' => '5300', 'tax.income.deferred' => '5310', 'tax.income.payable' => '2300', 'revenue' => '4000', 'gains' => '4100', 'deferred' => '5310'],
        settings: ['income_tax' => ['version' => 'reviewed:1', 'separate_reviewer' => false, 'loss_extensions' => [], 'sources' => $sources]]);
    $trade = ['rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => null, 'loss_sources' => ['trade'], 'profit_roles' => ['revenue']];
    $configuration = $this->ledger->configurations->install($this->book, $pack('1', ['trade' => $trade,
        'capital' => ['rate' => '0.15', 'rounding' => 'down', 'loss_years' => 0, 'loss_sources' => [], 'profit_roles' => ['gains']]]));
    $gains = $this->ledger->configurations->account($this->book, $configuration, 'gains');
    $deferred = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.deferred');
    $this->ledger->journals->post(new JournalRequest($this->book, 'activity', '2026-12-31', 'Trading and a gain', [
        new Posting($this->cash, Side::Debit, Money::of('1200', $this->kes)),
        new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)), new Posting($gains, Side::Credit, Money::of('200', $this->kes)),
    ]));
    expect(fn () => $this->ledger->journals->post(new JournalRequest($this->book, 'manual-tax', '2026-12-31', 'Operating cost booked as deferred tax', [
        new Posting($deferred, Side::Debit, Money::of('50', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('50', $this->kes)),
    ])))->toThrow(QueryException::class, 'take only tax journals');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $charges = [];
    foreach (['trade', 'capital'] as $source) {
        $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($snapshot, 'tax-'.$source, $configuration, '2026-01-01', $source, [], [], [], 'Reviewed '.$source));
        $charges[$source] = $this->ledger->incomeTax->get($this->book, $id)->charge->decimal();
    }
    expect($charges)->toBe(['trade' => '300.00', 'capital' => '30.00']);
    $leaking = $this->ledger->configurations->install($this->book, $pack('2', ['trade' => $trade,
        'capital' => ['rate' => '0.15', 'rounding' => 'down', 'loss_years' => 0, 'loss_sources' => [], 'profit_roles' => ['gains', 'deferred']]]));
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'leaking', $leaking, '2026-01-01', 'capital', [], [], [], 'Deferred tax as profit')))
        ->toThrow(QueryException::class, 'excluding income tax expense');
});

it('rejects a second computation that taxes the same profit accounts under another source name', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('10000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('10000', $this->kes)),
    ]));
    $assess = function (string $configuration, string $source): string {
        $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax-'.$source, $configuration, '2026-01-01', $source, [], [], [], 'Reviewed '.$source));
        $this->ledger->incomeTax->review($this->book, $id, 'review-'.$source, '2026-12-31', true, 'Approve', []);

        return $id;
    };
    $this->ledger->incomeTax->post($this->book, $assess($configuration, 'trade'), 'post-trade', '2026-12-31', 'Current tax');
    $renamed = incomeTaxConfiguration($this->ledger, $this->book, 'renamed', ['sources' => ['business' => [
        'rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => 5, 'loss_sources' => ['business'], 'profit_roles' => ['profit.revenue'],
    ]]]);
    $business = $assess($renamed, 'business');
    expect(fn () => $this->ledger->incomeTax->post($this->book, $business, 'post-business', '2026-12-31', 'Same profit again'))->toThrow(QueryException::class, 'profit accounts in an overlapping period');
    expect(array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check')['income_tax_reviewed']['items'])
        ->toBe(['trade:superseded_configuration']);
});

it('requires every earnings posting of the year to be assessed under the rules in force at the year end', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book, validFrom: '2026-07-01');
    $this->ledger->journals->post(new JournalRequest($this->book, 'march-profit', '2026-03-31', 'Profit before the rule change', [
        new Posting($this->cash, Side::Debit, Money::of('10000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('10000', $this->kes)),
    ]));
    $check = fn (): array => array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check')['income_tax_reviewed'];
    expect($check()['items'])->toBe(['income_tax:missing_reviewed_computation', 'account:4000:unassessed']);
    $assess = function (string $key, string $startsOn) use ($configuration): string {
        $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), $key, $configuration, $startsOn, 'trade', [], [], [], 'Reviewed '.$key));
        $this->ledger->incomeTax->review($this->book, $id, $key.':review', '2026-12-31', true, 'Approve', []);
        $this->ledger->incomeTax->post($this->book, $id, $key.':post', '2026-12-31', 'Current tax');

        return $id;
    };
    $halfYear = $assess('second-half', '2026-07-01');
    expect($check()['items'])->toBe(['account:4000:unassessed']);
    $this->ledger->incomeTax->reverse($this->book, $halfYear, 'second-half:reverse', '2026-12-31', 'Assess the whole year');
    $fullYear = $assess('full-year', '2026-01-01');
    expect($this->ledger->incomeTax->get($this->book, $fullYear)->charge->decimal())->toBe('3000.00')->and($check()['status'])->toBe('pass');
});

it('refuses journal-free tax evidence outside an open accounting period', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $opening = $this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('100', $this->kes), 'Opening assessment');
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'loss-year', $configuration,
        '2026-01-01', 'trade', [['kind' => 'temporary', 'reference' => 'Reviewed deduction', 'amount_minor' => '-100000']], [], [], 'Loss-year review'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    expect(fn () => $this->ledger->incomeTax->post($this->book, $id, 'undated', '2031-05-05', 'No period'))->toThrow(QueryException::class, 'open accounting period');
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Recognize loss');
    $deferred = deferredTaxConfiguration($this->ledger, $this->book);
    $exempt = $this->ledger->incomeTax->prepareDeferred(new DeferredTaxRequest($this->ledger->reports->snapshot($this->book, '2026-06-30'), 'exempt', $deferred, 'temporary:goodwill',
        deferredTaxInputs(['exempt' => true, 'attributions' => []])));
    $this->ledger->incomeTax->reviewDeferred($this->book, $exempt, 'exempt:review', '2026-06-30', true, 'Approve', []);
    $this->ledger->periods->close($this->book, $this->period);
    expect(fn () => $this->ledger->incomeTax->reverse($this->book, $id, 'closed', '2026-12-31', 'Closed year'))->toThrow(QueryException::class, 'open accounting period')
        ->and(fn () => $this->ledger->incomeTax->reverseLoss($this->book, $opening, 'closed-loss', '2026-06-30', 'Closed year'))->toThrow(QueryException::class, 'open accounting period')
        ->and(fn () => $this->ledger->incomeTax->postDeferred($this->book, $exempt, 'exempt:post', '2026-06-30', 'Closed year'))->toThrow(QueryException::class, 'open accounting period');
});

it('reports remaining losses net of known later-dated usage exactly as preparation accepts them', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $loss = $this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('1500', $this->kes), 'Opening losses');
    foreach (['2026', '2027'] as $year) {
        $this->ledger->journals->post(new JournalRequest($this->book, 'profit-'.$year, $year.'-12-30', 'Profit', [
            new Posting($this->cash, Side::Debit, Money::of('2000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('2000', $this->kes)),
        ]));
    }
    $later = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2027-12-31'), 'tax-2027', $configuration,
        '2027-01-01', 'trade', [], [$loss => '100000'], [], 'Reviewed 2027'));
    $this->ledger->incomeTax->review($this->book, $later, 'review-2027', '2027-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $later, 'post-2027', '2027-12-31', 'Current tax');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->incomeTax->losses($snapshot)[0]->remaining->decimal())->toBe('500.00');
    $request = fn (string $key, string $amount): IncomeTaxRequest => new IncomeTaxRequest($snapshot, $key, $configuration, '2026-01-01', 'trade', [], [$loss => $amount], [], 'Reviewed 2026');
    expect(fn () => $this->ledger->incomeTax->prepare($request('excess', '50001')))->toThrow(QueryException::class, 'exceeds remaining')
        ->and($this->ledger->incomeTax->get($this->book, $this->ledger->incomeTax->prepare($request('remaining', '50000')))->lossUsed->decimal())->toBe('500.00');
});

it('records a generated loss against its reviewed basis whatever the length of the posting reason', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'loss-year', $configuration,
        '2026-01-01', 'trade', [['kind' => 'temporary', 'reference' => 'Reviewed deduction', 'amount_minor' => '-100000']], [], [], 'Loss-year working paper'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', str_repeat('Detailed posting narrative. ', 30));
    expect($this->ledger->incomeTax->losses($this->ledger->reports->snapshot($this->book, '2026-12-31'))[0]->reference)->toBe('Loss-year working paper');
});

it('expires a tax loss under its own source carry-forward rule rather than the using source', function () {
    $configuration = $this->ledger->configurations->install($this->book, new Pack('income-tax-sources', '1', accounts: [
        ['code' => '2300', 'name' => 'Current income tax payable', 'type' => 'liability'], ['code' => '5300', 'name' => 'Current income tax expense', 'type' => 'expense'],
    ], roles: ['tax.income.current' => '5300', 'tax.income.payable' => '2300', 'revenue' => '4000'], settings: ['income_tax' => [
        'version' => 'reviewed:1', 'separate_reviewer' => false, 'loss_extensions' => [], 'sources' => [
            'trade' => ['rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => 0, 'loss_sources' => ['trade', 'discontinued'], 'profit_roles' => ['revenue']],
            'discontinued' => ['rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => null, 'loss_sources' => [], 'profit_roles' => []],
        ],
    ]]));
    $discontinued = $this->ledger->incomeTax->registerLoss($this->book, 'discontinued', 'discontinued', '2020-12-31', Money::of('100', $this->kes), 'Reviewed discontinued-activity loss');
    $trade = $this->ledger->incomeTax->registerLoss($this->book, 'trade', 'trade', '2025-12-31', Money::of('100', $this->kes), 'Reviewed trade loss');
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('1000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('1000', $this->kes)),
    ]));
    $request = fn (string $key, string $loss): IncomeTaxRequest => new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), $key, $configuration,
        '2026-01-01', 'trade', [], [$loss => '10000'], [], 'Reviewed computation');
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'assess-discontinued', $configuration,
        '2026-01-01', 'discontinued', [['kind' => 'permanent', 'reference' => 'Add-back', 'amount_minor' => '100000']], [], [], 'Reviewed')))->toThrow(QueryException::class, 'loss-only source');
    expect($this->ledger->incomeTax->get($this->book, $this->ledger->incomeTax->prepare($request('discontinued', $discontinued)))->lossUsed->decimal())->toBe('100.00')
        ->and(fn () => $this->ledger->incomeTax->prepare($request('trade', $trade)))->toThrow(QueryException::class, 'expired');
});

it('excludes from source profit only expense accounts that tax configurations designate as income tax', function () {
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('10000', $this->kes)), new Posting($this->income, Side::Credit, Money::of('10000', $this->kes)),
    ]));
    $this->ledger->configurations->install($this->book, new Pack('unrelated', '1', roles: ['tax.income.refund' => '4000']));
    $profit = function (string $configuration, string $key): string {
        $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), $key, $configuration, '2026-01-01', 'trade', [], [], [], 'Reviewed'));

        return $this->ledger->incomeTax->get($this->book, $id)->accountingProfit->decimal();
    };
    expect($profit(incomeTaxConfiguration($this->ledger, $this->book), 'unrelated-role'))->toBe('10000.00');
    $incomeRole = $this->ledger->configurations->install($this->book, new Pack('income-tax-example', 'income-role', roles: [
        'tax.income.current' => '5300', 'tax.income.payable' => '2300', 'tax.income.rebate' => '4000', 'profit.revenue' => '4000',
    ], settings: ['income_tax' => ['version' => 'reviewed:income-role', 'separate_reviewer' => false, 'loss_extensions' => [], 'sources' => ['trade' => [
        'rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => 5, 'loss_sources' => ['trade'], 'profit_roles' => ['profit.revenue'],
    ]]]]));
    expect($profit($incomeRole, 'income-role'))->toBe('10000.00');
});

it('prepares income tax only under the latest configuration known at the cutoff whose posting accounts are book level', function () {
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $request = fn (Snapshot $snapshot, string $key, string $configuration): IncomeTaxRequest => new IncomeTaxRequest($snapshot, $key, $configuration, '2026-01-01', 'trade', [], [], [], 'Reviewed');
    expect(fn () => $this->ledger->incomeTax->prepare($request($snapshot, 'unknown', $configuration)))->toThrow(QueryException::class, 'latest effective configuration known');
    $newer = incomeTaxConfiguration($this->ledger, $this->book, '2');
    expect(fn () => $this->ledger->incomeTax->prepare($request($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'superseded', $configuration)))
        ->toThrow(QueryException::class, 'latest effective configuration known');
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $this->ledger->configurations->account($this->book, $newer, 'tax.income.payable'), 'campus');
    expect(fn () => $this->ledger->incomeTax->prepare($request($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'dimensioned', $newer)))
        ->toThrow(QueryException::class, 'cannot require dimensions');
});

it('requires a separate current income tax reviewer when selected by the host', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book, changes: ['separate_reviewer' => true]);
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Reviewed working paper'));
    expect(fn () => $this->ledger->incomeTax->review($this->book, $id, 'self-review', '2026-12-31', true, 'Self approval', []))->toThrow(QueryException::class, 'separate reviewer');
    $reviewer = (new Ledger($this->connection(), new PermitAll))->for(new Scope($this->scope->tenantId, 'tax-reviewer'));
    $reviewer->incomeTax->review($this->book, $id, 'review', '2026-12-31', false, 'Rejected working paper', ['reference' => 'review:1']);
    expect(fn () => $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Cannot post rejection'))->toThrow(QueryException::class, 'prior approval');
    expect($this->ledger->incomeTax->history($this->ledger->reports->snapshot($this->book, '2026-12-31'), $id)[0]->actorId)->toBe('tax-reviewer');
});

it('independently rejects forged current income tax journals and unrelated posting evidence', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $expense = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.current');
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.payable');
    $profit = $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Approved source'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $charge = fn (string $key, string $amount, Session $ledger): string => $ledger->journals->post(new JournalRequest($this->book, $key, '2026-12-31', 'Direct charge', [
        new Posting($expense, Side::Debit, Money::of($amount, $this->kes)), new Posting($payable, Side::Credit, Money::of($amount, $this->kes)),
    ], source: new Source('ledger.income_tax', $id)))->id;
    expect(fn () => $this->storage->run(Permission::Tax, function () use ($charge, $id): void {
        $this->storage->connection->select("SELECT set_config('noria.ledger_income_tax', ?, true)", [$id]);
        $this->storage->insert('ledger_income_tax_events', [
            'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'computation_id' => $id, 'idempotency_key' => 'wrong-charge', 'fingerprint' => str_repeat('a', 64),
            'kind' => 'post', 'effective_on' => '2026-12-31', 'reason' => 'Forged charge', 'evidence' => '{}', 'journal_id' => $charge('wrong-charge', '29', $this->ledger),
        ]);
    }, $this->book))->toThrow(QueryException::class, 'postings do not match');
    expect(fn () => $this->storage->run(Permission::Tax, fn () => $this->storage->insert('ledger_income_tax_events', [
        'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'computation_id' => $id, 'idempotency_key' => 'forged-event', 'fingerprint' => str_repeat('a', 64),
        'kind' => 'post', 'effective_on' => '2026-12-31', 'reason' => 'Unrelated journal', 'evidence' => '{}', 'journal_id' => $profit->id,
    ]), $this->book))->toThrow(QueryException::class, 'journal must match');
    $denyTax = new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Tax;
        }
    };
    $poster = (new Ledger($this->connection(), $denyTax))->for($this->scope);
    expect(fn () => $charge('bypass', '30', $poster))->toThrow(QueryException::class, 'only by the income tax service');
    expect($this->ledger->reports->snapshot($this->book, '2026-12-31')->throughSequence)->toBe($before->throughSequence);
    expect(fn () => $poster->incomeTax->post($this->book, $id, 'no-tax-permission', '2026-12-31', 'Bypass'))->toThrow(LedgerException::class, 'ledger.tax');
    $posted = $this->ledger->incomeTax->post($this->book, $id, 'real-post', '2026-12-31', 'Approved charge');
    $history = $this->ledger->incomeTax->history($this->ledger->reports->snapshot($this->book, '2026-12-31'), $id);
    $journal = $this->ledger->journals->get($this->book, text($history[1]->journalId));
    expect($history[1]->id)->toBe($posted)->and($history[1]->sequence)->toBe($journal->sequence)->and((int) $journal->sequence)->toBe((int) $before->throughSequence + 1);
});

it('derives current income tax financial evidence in PostgreSQL instead of trusting caller supplied results', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $id = (string) Str::uuid7();
    $this->storage->run(Permission::Tax, fn () => $this->storage->insert('ledger_income_tax_computations', [
        'id' => $id, 'book_id' => $this->book, 'configuration_id' => $configuration, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'through_sequence' => $snapshot->throughSequence, 'source' => 'trade', 'idempotency_key' => 'forged-result', 'fingerprint' => str_repeat('a', 64),
        'inputs' => Canonical::json(['adjustments' => [], 'losses' => [], 'credits' => [], 'basis_reference' => 'Reviewed inputs']),
        'policy' => '{}', 'result' => '{"charge_minor":"1"}', 'result_hash' => str_repeat('b', 64),
        'expense_account_id' => $this->cash, 'payable_account_id' => $this->income,
    ]), $this->book);
    $computation = $this->ledger->incomeTax->get($this->book, $id);
    expect($computation->charge->decimal())->toBe('30.00')->and($computation->resultHash)->toBe(hash('sha256', $computation->resultJson))
        ->and($computation->policy['version'])->toBe('reviewed:1');
});

it('requires complete explicit current income tax rules instead of silently defaulting policy choices', function (bool $perSource, string $field, mixed $value) {
    $configuration = $perSource ? incomeTaxConfiguration($this->ledger, $this->book, trade: [$field => $value]) : incomeTaxConfiguration($this->ledger, $this->book, changes: [$field => $value]);
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Reviewed inputs')))->toThrow(QueryException::class);
})->with([
    [true, 'rate', '1.01'], [true, 'rate', '-0.30'], [true, 'rounding', 'implicit'], [true, 'loss_years', -1], [true, 'profit_roles', 'profit.revenue'],
    [true, 'loss_sources', ['trade', 'unconfigured']],
    [true, 'loss_sources', null], [true, 'unsupported_rule', true], [false, 'version', ''], [false, 'separate_reviewer', 'false'],
    [false, 'sources', []], [false, 'unsupported_rule', true],
]);

it('rounds current income tax exactly under the host selected convention', function (string $rounding, string $expected) {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book, trade: ['rate' => '0.5', 'rounding' => $rounding]);
    $this->ledger->journals->post(new JournalRequest($this->book, 'tiny-profit', '2026-12-31', 'One minor unit of profit', [
        new Posting($this->cash, Side::Debit, Money::minor('1', $this->kes)), new Posting($this->income, Side::Credit, Money::minor('1', $this->kes)),
    ]));
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Reviewed rounding'));
    expect($this->ledger->incomeTax->get($this->book, $id)->charge->decimal())->toBe($expected);
})->with([['half_even', '0.00'], ['half_up', '0.01'], ['down', '0.00']]);

it('rejects forged current income tax payment claims with noncanonical identities or excess amounts', function (string $field, string $value) {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'tax.income.payable');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'payment', '2026-12-31', 'Payment', [
        new Posting($payable, Side::Debit, Money::of('100', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $credit = ['journal_id' => $payment->id, 'line_no' => 1, 'amount_minor' => '10000', 'kind' => 'instalment', 'reference' => 'Receipt'];
    $credit[$field] = $value === 'uppercase' ? strtoupper($payment->id) : $value;
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect(fn () => $this->storage->run(Permission::Tax, fn () => $this->storage->insert('ledger_income_tax_computations', [
        'id' => (string) Str::uuid7(), 'book_id' => $this->book, 'configuration_id' => $configuration, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
        'through_sequence' => $snapshot->throughSequence, 'source' => 'trade', 'idempotency_key' => 'forged', 'fingerprint' => str_repeat('a', 64),
        'inputs' => Canonical::json(['adjustments' => [], 'losses' => [], 'credits' => [$credit], 'basis_reference' => 'Forged claim']),
    ]), $this->book))->toThrow(QueryException::class);
})->with([['journal_id', 'uppercase'], ['line_no', '1'], ['amount_minor', '10001'], ['amount_minor', '0.5'], ['kind', 'unknown']]);

it('checks current income tax capabilities independently of read and journal permissions', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $request = new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration, '2026-01-01', 'trade', [], [], [], 'Reviewed inputs');
    $denyTax = new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Tax;
        }
    };
    $restricted = (new Ledger($this->connection(), $denyTax))->for($this->scope);
    expect(fn () => $restricted->incomeTax->prepare($request))->toThrow(LedgerException::class, 'ledger.tax');
    $id = $this->ledger->incomeTax->prepare($request);
    expect($restricted->incomeTax->get($this->book, $id)->charge->decimal())->toBe('0.00');
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approved', []);
    $denyPost = new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Post;
        }
    };
    $restricted = (new Ledger($this->connection(), $denyPost))->for($this->scope);
    expect(fn () => $restricted->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'No posting permission'))->toThrow(LedgerException::class, 'ledger.post');
});

it('flags stale posted current income tax and refuses to omit unmapped earnings from accounting profit', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Reviewed year'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'No tax due');
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-profit', '2026-12-31', 'Late profit', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $checks = array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check');
    expect($checks['income_tax_reviewed']['items'])->toContain('trade:stale_accounting_profit');
    $expense = $this->ledger->catalog->account($this->book, '5100', 'Unmapped cost', AccountType::Expense);
    $this->ledger->journals->post(new JournalRequest($this->book, 'unmapped-cost', '2026-12-31', 'Cost', [
        new Posting($expense, Side::Debit, Money::of('10', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('10', $this->kes)),
    ]));
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'replacement', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Incomplete mapping')))->toThrow(QueryException::class, 'earnings activity unmapped');
    $checks = array_column($this->ledger->yearEnd->checklist($this->book, '2026-01-01', '2026-12-31')['checks'], null, 'check');
    expect($checks['income_tax_reviewed']['items'])->toContain('trade:unmapped_earnings');
});

it('keeps current income tax outside source profit after role changes and accepts a host selected short fiscal period', function () {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->journals->post(new JournalRequest($this->book, 'profit', '2026-12-31', 'Profit', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($this->income, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Reviewed inputs'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    $this->ledger->incomeTax->post($this->book, $id, 'post', '2026-12-31', 'Recognize tax');
    $revision = fn (string $name, bool $legacyAsProfit): string => $this->ledger->configurations->install($this->book, new Pack($name, '1', accounts: [
        ['code' => '5340', 'name' => 'Replacement tax expense', 'type' => 'expense'],
    ], roles: ['tax.income.current' => '5340', 'tax.income.payable' => '2300', 'revenue' => '4000', 'legacy.expense' => '5300'], settings: ['income_tax' => [
        'version' => 'reviewed:2', 'separate_reviewer' => false, 'loss_extensions' => [], 'sources' => ['trade' => [
            'rate' => '0.30', 'rounding' => 'half_even', 'loss_years' => null, 'loss_sources' => ['trade'], 'profit_roles' => $legacyAsProfit ? ['revenue', 'legacy.expense'] : ['revenue'],
        ]],
    ]]));
    $legacy = $revision('legacy-tax-roles', true);
    expect(fn () => $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'legacy', $legacy,
        '2026-01-01', 'trade', [], [], [], 'Former tax expense as profit')))->toThrow(QueryException::class, 'excluding income tax expense');
    $replacement = $revision('revised-tax-roles', false);
    $short = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'short-period', $replacement,
        '2026-12-31', 'trade', [], [], [], 'Reviewed short fiscal period'));
    expect($this->ledger->incomeTax->get($this->book, $short)->accountingProfit->decimal())->toBe('100.00')
        ->and($this->ledger->incomeTax->get($this->book, $short)->charge->decimal())->toBe('30.00');
});

it('keeps current income tax inputs results losses and review evidence immutable', function (string $table, string $operation) {
    $configuration = incomeTaxConfiguration($this->ledger, $this->book);
    $this->ledger->incomeTax->registerLoss($this->book, 'opening', 'trade', '2025-12-31', Money::of('100', $this->kes), 'Opening loss');
    $id = $this->ledger->incomeTax->prepare(new IncomeTaxRequest($this->ledger->reports->snapshot($this->book, '2026-12-31'), 'tax', $configuration,
        '2026-01-01', 'trade', [], [], [], 'Reviewed working paper'));
    $this->ledger->incomeTax->review($this->book, $id, 'review', '2026-12-31', true, 'Approve', []);
    expect(fn () => $this->storage->run(Permission::Tax, fn () => match ($operation) {
        'update' => $this->storage->table($table)->where('book_id', $this->book)->update(['fingerprint' => str_repeat('b', 64)]),
        'delete' => $this->storage->table($table)->where('book_id', $this->book)->delete(),
        'truncate' => $this->storage->connection->statement('TRUNCATE '.$table.' CASCADE'),
        default => throw new LogicException('Unknown evidence mutation.'),
    }, $this->book))->toThrow(QueryException::class);
})->with([
    ['ledger_income_tax_computations', 'update'], ['ledger_income_tax_computations', 'delete'], ['ledger_income_tax_computations', 'truncate'],
    ['ledger_income_tax_losses', 'update'], ['ledger_income_tax_losses', 'delete'], ['ledger_income_tax_losses', 'truncate'],
    ['ledger_income_tax_events', 'update'], ['ledger_income_tax_events', 'delete'], ['ledger_income_tax_events', 'truncate'],
]);

/** @param list<TaxRule> $rules */
function taxDocumentConfiguration(Session $ledger, string $book, array $rules, bool $foreignControl = false): string
{
    $receivable = ['code' => '1100', 'name' => 'Receivables', 'type' => 'asset', 'control' => true];
    if ($foreignControl) {
        $receivable['currency'] = 'USD';
    }

    return $ledger->configurations->install($book, new Pack('example-tax-documents', 'v1', [
        $receivable,
        ['code' => '2000', 'name' => 'Payables', 'type' => 'liability', 'control' => true],
        ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'],
        ['code' => '1200', 'name' => 'Recoverable input tax', 'type' => 'asset'],
        ['code' => '5000', 'name' => 'Purchase costs', 'type' => 'expense'],
        ['code' => '5999', 'name' => 'Explicit rounding', 'type' => 'expense'],
    ], ['receivable' => '1100', 'payable' => '2000', 'revenue' => '4000', 'cost' => '5000', 'tax.output' => '2200', 'tax.input' => '1200', 'rounding' => '5999'], $rules));
}

it('keeps school sponsorship, installments, and excess payments distinct', function () {
    $parent = $this->ledger->catalog->party($this->entity, 'guardian-1', 'Guardian');
    $sponsor = $this->ledger->catalog->party($this->entity, 'sponsor-1', 'Sponsor');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $invoice = new DocumentRequest($this->book, 'fees-1', '2026-01-01', new Source('school.fees', 'FEES-1'), DocumentKind::SalesInvoice, $ar, [
        new Obligation($parent, Money::of('800', $this->kes), '2026-01-31'),
        new Obligation($sponsor, Money::of('400', $this->kes), '2026-03-31'),
    ], [new Charge($this->income, Money::of('1200', $this->kes), 'Tuition')], beneficiaryReference: 'student-1');
    $first = $this->ledger->documents->issue($invoice);
    expect($this->ledger->documents->issue($invoice)->id)->toBe($first->id);
    $this->ledger->documents->payment(new PaymentRequest($this->book, 'receipt-1', '2026-02-01', new Source('mpesa.receipt', 'RCPT-1'), $this->cash, $ar, $parent, Money::of('1000', $this->kes), payerReference: 'different-family-member'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $parentItems = $this->ledger->reports->openItems($snapshot, $parent);
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'apply-fees', $parentItems[0]->id, $parentItems[1]->id, Money::of('800', $this->kes), '2026-02-01', 'Pay guardian share'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $items = $this->ledger->reports->openItems($snapshot, $parent);
    expect($items)->toHaveCount(1);
    expect($items[0]->side)->toBe(Side::Credit);
    expect($items[0]->outstanding->decimal())->toBe('200.00');
    expect($this->ledger->reports->aging($snapshot, $sponsor)['KES']['current']->decimal())->toBe('400.00');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('1200.00');
});

it('separates the hospital beneficiary from patient and insurer obligations', function () {
    $patient = $this->ledger->catalog->party($this->entity, 'patient-guarantor', 'Patient guarantor');
    $insurer = $this->ledger->catalog->party($this->entity, 'insurer', 'Insurer');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'hospital-bill', '2026-01-01', new Source('hospital.invoice', 'H-1'), DocumentKind::SalesInvoice, $ar, [
        new Obligation($patient, Money::of('150', $this->kes), '2026-01-01'),
        new Obligation($insurer, Money::of('450', $this->kes), '2026-02-28'),
    ], [new Charge($this->income, Money::of('600', $this->kes), 'Services at agreed contract price')], beneficiaryReference: 'encounter-reference-1'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->openItems($snapshot, $patient)[0]->outstanding->decimal())->toBe('150.00');
    expect($this->ledger->reports->openItems($snapshot, $insurer)[0]->outstanding->decimal())->toBe('450.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
});

it('applies a utility deposit to arrears without recognizing the deposit as revenue', function () {
    $customer = $this->ledger->catalog->party($this->entity, 'meter-account', 'Water customer');
    $ar = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $deposits = $this->ledger->catalog->account($this->book, '2100', 'Refundable deposits', AccountType::Liability, control: true);
    $money = Money::of('300', $this->kes);
    $this->ledger->documents->payment(new PaymentRequest($this->book, 'deposit', '2026-01-01', new Source('utility.deposit', 'D-1'), $this->cash, $deposits, $customer, $money));
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'consumption', '2026-02-01', new Source('utility.bill', 'B-1'), DocumentKind::SalesInvoice, $ar,
        [new Obligation($customer, $money, '2026-02-28')], [new Charge($this->income, $money, 'Metered consumption')]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'apply-deposit', '2026-02-02', 'Apply refundable deposit', [
        new Posting($deposits, Side::Debit, $money, partyId: $customer), new Posting($ar, Side::Credit, $money, partyId: $customer),
    ]));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-28'));

    foreach ([$ar, $deposits] as $account) {
        $debit = collect($items)->first(fn ($item) => $item->accountId === $account && $item->side === Side::Debit) ?? throw new RuntimeException('Debit open item is missing.');
        $credit = collect($items)->first(fn ($item) => $item->accountId === $account && $item->side === Side::Credit) ?? throw new RuntimeException('Credit open item is missing.');
        $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'allocate-'.$account, $debit->id, $credit->id, $money, '2026-02-02', 'Apply deposit'));
    }

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->reports->openItems($snapshot))->toBe([]);
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('300.00');
});

it('posts supplier bills and disbursements with the opposite control direction', function () {
    $supplier = $this->ledger->catalog->party($this->entity, 'supplier', 'Supplier');
    $ap = $this->ledger->catalog->account($this->book, '2000', 'Payables', AccountType::Liability, control: true);
    $expense = $this->ledger->catalog->account($this->book, '5000', 'Supplies', AccountType::Expense);
    $money = Money::of('100', $this->kes);
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'supplier-bill', '2026-01-01', new Source('supplier.bill', 'S-1'), DocumentKind::PurchaseBill, $ap,
        [new Obligation($supplier, $money, '2026-01-31')], [new Charge($expense, $money, 'Supplies')]));
    $this->ledger->documents->payment(new PaymentRequest($this->book, 'supplier-payment', '2026-01-15', new Source('bank.payment', 'P-1'), $this->cash, $ap, $supplier, $money, receipt: false));
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'));
    $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'pay-supplier', $items[1]->id, $items[0]->id, $money, '2026-01-15', 'Settle bill'));
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31')))->toBe([]);
});

it('posts versioned tax documents with the correct sales and purchase credit directions', function (DocumentKind $kind, string $controlRole, string $chargeRole, string $taxRole, string $expectedTax) {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:v1')]);
    $party = $this->ledger->catalog->party($this->entity, 'counterparty', 'Counterparty');
    $request = new TaxedDocumentRequest($this->book, 'tax-document', '2026-01-01', new Source('tax.example', '1'), $kind, $configuration, $controlRole,
        [new Obligation($party, Money::of('174', $this->kes), '2026-01-31')], [new TaxLine($chargeRole, '100', '1.5', 'Example taxable service', ['EXAMPLE'])], ['EXAMPLE' => $taxRole], beneficiaryReference: 'project:alpha');
    $journal = $this->ledger->documents->issueTaxed($request);
    $this->ledger->periods->close($this->book, $this->period, 'Close after posting');
    expect($this->ledger->documents->issueTaxed($request)->id)->toBe($journal->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    $taxAccount = $this->ledger->configurations->account($this->book, $configuration, $taxRole);
    expect($balances[$taxAccount]->closing->decimal())->toBe($expectedTax);
    expect($this->ledger->reports->controlReconciliation($snapshot, $this->ledger->configurations->account($this->book, $configuration, $controlRole))->decimal())->toBe('0.00');
    expect($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $this->storage->run(Permission::Read, function () use ($journal): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        $evidence = data_get(json_decode(text($row->metadata), true, 512, JSON_THROW_ON_ERROR), 'tax_document');
        expect(data_get($evidence, 'lines.0.result.net.amount'))->toBe('150.00');
        expect(data_get($evidence, 'lines.0.rules.0.policy_version'))->toBe('example:v1');
    }, $this->book);
    $this->ledger->periods->reopen($this->book, $this->period, 'Correct posted example');
    $this->ledger->journals->reverse($this->book, $journal->id, 'correct-tax-document', '2026-01-02', 'Cancel document');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$taxAccount]->closing->decimal())->toBe('0.00');
})->with([
    [DocumentKind::SalesInvoice, 'receivable', 'revenue', 'tax.output', '-24.00'],
    [DocumentKind::SalesCredit, 'receivable', 'revenue', 'tax.output', '24.00'],
    [DocumentKind::PurchaseBill, 'payable', 'cost', 'tax.input', '24.00'],
    [DocumentKind::PurchaseCredit, 'payable', 'cost', 'tax.input', '-24.00'],
]);

it('posts signed tax rounding only to an explicitly selected rounding account', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [
        new TaxRule('A', '0.2', '2026-01-01', null, 'example:v1'), new TaxRule('B', '0.2', '2026-01-01', null, 'example:v1'),
    ]);
    $party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $obligations = [new Obligation($party, Money::of('0.04', $this->kes), '2026-01-31')];
    $lines = [new TaxLine('revenue', '0.03', '1', 'Rounding example', ['A', 'B'])];
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'no-rounding', '2026-01-01', new Source('tax.example', 'bad'),
            DocumentKind::SalesInvoice, $configuration, 'receivable', $obligations, $lines, ['A' => 'tax.output', 'B' => 'tax.output']));
        $this->fail('Rounding requires an explicit account.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('explicit rounding');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'rounding', '2026-01-01', new Source('tax.example', 'good'),
        DocumentKind::SalesInvoice, $configuration, 'receivable', $obligations, $lines, ['A' => 'tax.output', 'B' => 'tax.output'], roundingRole: 'rounding'));
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')))->keyBy('code');
    expect($balances['1100']?->closing->decimal())->toBe('0.04');
    expect($balances['4000']?->closing->decimal())->toBe('-0.03');
    expect($balances['2200']?->closing->decimal())->toBe('-0.02');
    expect($balances['5999']?->closing->decimal())->toBe('0.01');
});

it('retains inclusive discounted tax evidence and splits the gross among debtors', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'inclusive:v1', inclusive: true)]);
    $guardian = $this->ledger->catalog->party($this->entity, 'guardian', 'Guardian');
    $sponsor = $this->ledger->catalog->party($this->entity, 'sponsor', 'Sponsor');
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'inclusive', '2026-01-01', new Source('tax.example', 'inclusive'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($guardian, Money::of('100', $this->kes), '2026-01-31'), new Obligation($sponsor, Money::of('56.60', $this->kes), '2026-02-28')],
        [new TaxLine('revenue', '116', '1.5', 'Inclusive discounted service', ['EXAMPLE'], '0.1')], ['EXAMPLE' => 'tax.output']));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['4000']?->closing->decimal())->toBe('-135.00');
    expect($balances['2200']?->closing->decimal())->toBe('-21.60');
    expect($this->ledger->reports->openItems($snapshot, $sponsor)[0]->outstanding->decimal())->toBe('56.60');
});

it('posts foreign split-obligation rounding as an explicit functional adjustment', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [], foreignControl: true);
    $usd = Currency::iso('USD');
    $first = $this->ledger->catalog->party($this->entity, 'first', 'First debtor');
    $second = $this->ledger->catalog->party($this->entity, 'second', 'Second debtor');
    $rate = new ExchangeRate($usd, $this->kes, '1.5', '2026-01-01', 'rounding-example', Rounding::HalfEven);
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'foreign', '2026-01-01', new Source('tax.example', 'foreign'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($first, Money::of('0.01', $usd), '2026-01-31'), new Obligation($second, Money::of('0.01', $usd), '2026-01-31')],
        [new TaxLine('revenue', '0.02', '1', 'Foreign split obligation')], roundingRole: 'rounding', exchangeRate: $rate));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1100']?->closing->decimal())->toBe('0.04');
    expect($balances['4000']?->closing->decimal())->toBe('-0.03');
    expect($balances['5999']?->closing->decimal())->toBe('-0.01');
    expect($this->ledger->reports->controlReconciliation($snapshot, $this->ledger->configurations->account($this->book, $configuration, 'receivable'))->decimal())->toBe('0.00');
});

function taxTreatmentConfiguration(Session $ledger, string $book, string $version = '1'): string
{
    return $ledger->configurations->install($book, new Pack('example-localization', $version, accounts: [
        ['code' => '1100', 'name' => 'Receivables', 'type' => 'asset', 'control' => true],
        ['code' => '1200', 'name' => 'Recoverable input tax', 'type' => 'asset'],
        ['code' => '1210', 'name' => 'Withholding credits', 'type' => 'asset'],
        ['code' => '2000', 'name' => 'Payables', 'type' => 'liability', 'control' => true],
        ['code' => '2210', 'name' => 'Withholding payable', 'type' => 'liability'],
        ['code' => '2220', 'name' => 'Reverse-charge tax payable', 'type' => 'liability'],
        ['code' => '5000', 'name' => 'Purchases', 'type' => 'expense'],
        ['code' => '5200', 'name' => 'Irrecoverable tax', 'type' => 'expense'],
    ], roles: ['cash' => '1000', 'receivable' => '1100', 'payable' => '2000', 'tax.input' => '1200',
        'tax.withholding.receivable' => '1210', 'tax.withholding.payable' => '2210', 'tax.reverse_charge.payable' => '2220', 'tax.irrecoverable' => '5200'],
        taxRules: [new TaxRule('EXAMPLE-WHT', '0.05', '2026-01-01', '2026-12-31', 'example-wht:1'), new TaxRule('EXAMPLE-RC', '0.16', '2026-01-01', '2026-12-31', 'example-rc:1')],
        statements: [new StatementDefinition('Illustrative tax worksheet', $version, [
            'reverse_charge' => ['label' => 'Self-assessed tax', 'accounts' => ['2220'], 'sign' => 'credit', 'include_closing' => false],
            'recoverable' => ['label' => 'Recoverable input', 'accounts' => ['1200'], 'include_closing' => false],
            'net' => ['label' => 'Net tax', 'terms' => ['reverse_charge' => '1', 'recoverable' => '-1']],
            'withholding' => ['label' => 'Withholding payable', 'accounts' => ['2210'], 'sign' => 'credit', 'include_closing' => false],
        ])], validFrom: '2026-01-01', validUntil: '2026-12-31'));
}

/** @param array<string, mixed> $inputs */
function taxTreatmentRequest(string $book, string $configuration, string $key, array $inputs, string $date = '2026-01-31'): TreatmentRequest
{
    return new TreatmentRequest($book, $key, $date, new Source('tax.treatment.example', $key), 'ledger.tax', '1', $configuration,
        [...$inputs, 'rounding' => 'half_even', 'policy_version' => 'example-eligibility:1', 'basis_reference' => 'approved-tax-category:42']);
}

it('settles the full supplier obligation while separating cash and withholding liability', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $supplier = $this->ledger->catalog->party($this->entity, 'withheld-supplier', 'Supplier');
    $payable = $this->ledger->configurations->account($this->book, $configuration, 'payable');
    $expense = $this->ledger->catalog->account($this->book, '5100', 'Services', AccountType::Expense);
    $bill = $this->ledger->documents->issue(new DocumentRequest($this->book, 'withheld-bill', '2026-01-01', new Source('example.bill', 'W-1'), DocumentKind::PurchaseBill,
        $payable, [new Obligation($supplier, Money::of('1160', $this->kes), '2026-01-31')], [new Charge($expense, Money::of('1160', $this->kes), 'Services and separately determined tax')]));
    $request = taxTreatmentRequest($this->book, $configuration, 'withheld-payment', [
        'operation' => 'withholding.payment', 'party_id' => $supplier, 'settlement' => '1160', 'tax_base' => '1000', 'tax_code' => 'EXAMPLE-WHT', 'cash_flow' => 'operating',
    ]);
    $payment = $this->ledger->treatments->post($request);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $items = $this->ledger->reports->openItems($snapshot, $supplier);
    $debit = collect($items)->first(fn ($item) => $item->journalId === $payment->id) ?? throw new RuntimeException('Payment open item is missing.');
    $credit = collect($items)->first(fn ($item) => $item->journalId === $bill->id) ?? throw new RuntimeException('Bill open item is missing.');
    $allocation = $this->ledger->settlements->allocate(new AllocationRequest($this->book, 'allocate-withheld', $debit->id, $credit->id, Money::of('1160', $this->kes), '2026-01-31', 'Full supplier settlement'));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1000']?->closing->decimal())->toBe('-1110.00')
        ->and($balances['2210']?->closing->decimal())->toBe('-50.00')
        ->and($balances['2000']?->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->openItems($snapshot, $supplier))->toBe([])
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->operating->decimal())->toBe('-1110.00');
    $this->ledger->settlements->release($this->book, $allocation, 'release-withheld', '2026-02-01', 'Correct withholding');
    $this->ledger->journals->reverse($this->book, $payment->id, 'reverse-withheld', '2026-02-01', 'Correct withholding');
    expect($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-02-01'), $supplier)[0]->outstanding->decimal())->toBe('1160.00');
});

it('records customer withholding credits without counting them as cash or reducing revenue', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $customer = $this->ledger->catalog->party($this->entity, 'withheld-customer', 'Customer');
    $receivable = $this->ledger->configurations->account($this->book, $configuration, 'receivable');
    $this->ledger->documents->issue(new DocumentRequest($this->book, 'withheld-invoice', '2026-01-01', new Source('example.invoice', 'W-1'), DocumentKind::SalesInvoice,
        $receivable, [new Obligation($customer, Money::of('1000', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('1000', $this->kes), 'Services')]));
    $request = taxTreatmentRequest($this->book, $configuration, 'withheld-receipt', [
        'operation' => 'withholding.receipt', 'party_id' => $customer, 'settlement' => '1000', 'tax_base' => '1000', 'tax_code' => 'EXAMPLE-WHT', 'cash_flow' => 'operating',
    ]);
    $journal = $this->ledger->treatments->post($request);
    $this->ledger->periods->close($this->book, $this->period, 'Close receipt period');
    expect($this->ledger->treatments->post($request)->id)->toBe($journal->id);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1000']?->closing->decimal())->toBe('950.00')
        ->and($balances['1210']?->closing->decimal())->toBe('50.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('1000.00')
        ->and($this->ledger->reports->controlReconciliation($snapshot, $receivable)->decimal())->toBe('0.00');
});

it('self-assesses reverse charge with explicit partial recoverability and no duplicate supplier debt', function (string $fraction, string $recoverable, string $expense) {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $journal = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'reverse-charge', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => $fraction, 'dimensions' => ['campus' => 'west'],
    ]));
    expect(array_unique(array_map(fn ($line) => $line->dimensions, $this->ledger->journals->lines($this->book, $journal->id)), SORT_REGULAR))->toBe([['campus' => 'west']]);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('code');
    expect($balances['1200']?->closing->decimal())->toBe($recoverable)
        ->and($balances['5200']?->closing->decimal())->toBe($expense)
        ->and($balances['2220']?->closing->decimal())->toBe('-160.00')
        ->and($balances['2000']?->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->openItems($snapshot))->toBe([])
        ->and($this->ledger->reports->balanceSheet($snapshot)['difference']->decimal())->toBe('0.00');
    $this->ledger->journals->reverse($this->book, $journal->id, 'rc-correction', '2026-02-01', 'Cancel imported service assessment');
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-01')))->keyBy('code');
    expect($balances['2220']?->closing->decimal())->toBe('0.00');
})->with([['0', '0.00', '160.00'], ['0.75', '120.00', '40.00'], ['1', '160.00', '0.00']]);

it('saves a configuration-pinned return worksheet with immutable cutoffs and correction continuity', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $journal = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'rc-return', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '0.75',
    ]));
    $columns = [new StatementColumn('current', 'January 2026', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01')];
    $id = $this->ledger->reports->saveConfiguredStatement($this->book, 'january-tax', $configuration, 'Illustrative tax worksheet', $columns);
    $evidence = $this->ledger->reports->statementEvidence($this->book, $id);
    $pinned = $evidence['result']->configuration ?? throw new RuntimeException('Missing statement configuration.');
    expect($pinned['id'])->toBe($configuration)
        ->and($pinned['content_hash'])->toBe($this->ledger->configurations->get($this->book, $configuration)['content_hash'])
        ->and($evidence['result']->rows['net']['values']['current']->decimal())->toBe('40.00')
        ->and(hash('sha256', $evidence['result_json']))->toBe($evidence['result_hash']);
    $this->ledger->journals->reverse($this->book, $journal->id, 'rc-backdated-correction', '2026-01-31', 'Amend January assessment');
    expect($this->ledger->reports->saveConfiguredStatement($this->book, 'january-tax', $configuration, 'Illustrative tax worksheet', $columns))->toBe($id)
        ->and($this->ledger->reports->statementEvidence($this->book, $id))->toEqual($evidence);
    $amended = $this->ledger->reports->saveConfiguredStatement($this->book, 'january-tax-amended', $configuration, 'Illustrative tax worksheet', [
        new StatementColumn('current', 'January 2026', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01'),
    ]);
    expect($this->ledger->reports->statementEvidence($this->book, $amended)['result']->rows['net']['values']['current']->decimal())->toBe('0.00');
});

it('rejects altered worksheet definitions claiming a pinned localization configuration', function () {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $definition = new StatementDefinition('Illustrative tax worksheet', '1', ['false' => ['label' => 'False zero', 'accounts' => []]]);
    $this->ledger->reports->saveStatement($this->book, 'forged-return', $definition, [
        new StatementColumn('current', 'January', $this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01'),
    ], $configuration);
})->throws(LedgerException::class, 'differs from its pinned configuration');

it('rolls back invalid localization policy inputs before posting cash or tax', function (string $case) {
    $configuration = taxTreatmentConfiguration($this->ledger, $this->book);
    $before = $this->ledger->events->cutoff($this->book);
    $inputs = ['operation' => $case === 'operation' ? 'self_supply' : 'reverse_charge', 'tax_code' => $case === 'code' ? 'UNKNOWN' : 'EXAMPLE-RC', 'tax_base' => '1000',
        'recoverable_fraction' => $case === 'recovery' ? '1.01' : '1'];
    try {
        $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'invalid-tax-treatment', $inputs));
        $this->fail('Invalid localization policy must fail.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain(['code' => 'configured exclusive', 'recovery' => 'between zero and one', 'operation' => 'Unknown tax treatment operation.'][$case]);
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
})->with(['code', 'recovery', 'operation']);

it('installs the Kenya VAT pack with separate pending import tax and country-pinned worksheet evidence', function () {
    $entity = $this->ledger->catalog->entity('Kenyan VAT taxpayer', 'KE');
    $book = $this->ledger->catalog->book($entity, 'VAT book', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $definitions = [
        'cash' => ['1000', 'Bank', AccountType::Asset, false, 'cash'],
        'receivable' => ['1100', 'Receivables', AccountType::Asset, true, null],
        'payable' => ['2000', 'Payables', AccountType::Liability, true, null],
        'tax.output' => ['2200', 'Output VAT', AccountType::Liability, false, null],
        'tax.input' => ['1200', 'Eligible input VAT', AccountType::Asset, false, null],
        'tax.irrecoverable' => ['5200', 'Irrecoverable tax', AccountType::Expense, false, null],
        'tax.reverse_charge.input' => ['1220', 'Import VAT pending payment', AccountType::Asset, false, null],
        'tax.reverse_charge.payable' => ['2220', 'Import VAT payable', AccountType::Liability, false, null],
        'tax.withholding.receivable' => ['1230', 'Withholding VAT credits', AccountType::Asset, false, null],
        'tax.withholding.payable' => ['2230', 'VAT withheld as agent', AccountType::Liability, false, null],
    ];
    $roles = [];
    foreach ($definitions as $role => [$code, $name, $type, $control, $cashFlow]) {
        $this->ledger->catalog->account($book, $code, $name, $type, control: $control, cashFlow: $cashFlow);
        $roles[$role] = $code;
    }
    $pack = Kenya::vatPack('2026.1', '2026-01-01', '2026-12-31', 'organization-vat-policy:1', $roles);
    $configuration = $this->ledger->configurations->install($book, $pack);
    expect($this->ledger->configurations->install($book, $pack))->toBe($configuration);
    $rules = $this->ledger->configurations->taxRules($book, $configuration, '2026-01-31');
    expect(array_map(fn (TaxRule $rule) => $rule->rate, $rules))->toBe(['0.16', '0', '0.02']);
    $this->ledger->treatments->post(taxTreatmentRequest($book, $configuration, 'ke-import-vat', [
        'operation' => 'reverse_charge', 'tax_code' => 'KE-VAT-STANDARD', 'tax_base' => '1000', 'recoverable_fraction' => '1',
    ]));
    $report = $this->ledger->reports->saveConfiguredStatement($book, 'ke-vat-january', $configuration, 'Kenya VAT ledger worksheet', [
        new StatementColumn('current', 'January 2026', $this->ledger->reports->snapshot($book, '2026-01-31'), '2026-01-01'),
    ]);
    $rows = $this->ledger->reports->statementEvidence($book, $report)['result']->rows;
    expect($rows['input']['values']['current']->decimal())->toBe('0.00')
        ->and($rows['net_movement']['values']['current']->decimal())->toBe('0.00')
        ->and($rows['import_assessed']['values']['current']->decimal())->toBe('160.00')
        ->and($rows['import_pending']['values']['current']->decimal())->toBe('160.00');
    $definition = $this->ledger->configurations->get($book, $configuration)['definition'];
    expect($definition->country)->toBe('KE')
        ->and($definition->settings['exempt_is_zero_rated'])->toBeFalse()
        ->and($definition->settings)->toHaveKey('statutory_submission_format', null);
    try {
        $this->ledger->treatments->post(taxTreatmentRequest($book, $configuration, 'ke-wrong-tax-kind', [
            'operation' => 'reverse_charge', 'tax_code' => 'KE-WH-VAT', 'tax_base' => '1000', 'recoverable_fraction' => '1',
        ]));
        $this->fail('Withholding VAT is not the imported-service assessment rate.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('not configured for this treatment');
    }
});

/** @param array{codes?: list<string>, reconciliations?: list<string>} $withdrawn */
function taxRegisterConfiguration(Session $ledger, string $book, string $version = '1', array $withdrawn = []): string
{
    taxTreatmentConfiguration($ledger, $book);
    $policy = Kenya::vatReturnPolicy();
    $policy['codes'] = ['EXAMPLE-RC' => ['label' => 'Illustrative standard tax'], 'EXAMPLE-WHT' => ['label' => 'Illustrative withholding'], 'ZERO' => ['label' => 'Zero-rated']];
    $policy['codes'] = array_diff_key($policy['codes'], array_flip($withdrawn['codes'] ?? []));
    $policy['reconciliations'] = array_diff_key(items($policy['reconciliations']), array_flip($withdrawn['reconciliations'] ?? []));

    return $ledger->configurations->install($book, new Pack('return-fixture', $version, accounts: [
        ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'], ['code' => '1220', 'name' => 'Pending import input tax', 'type' => 'asset'],
    ], roles: ['cash' => '1000', 'receivable' => '1100', 'payable' => '2000', 'revenue' => '4000', 'cost' => '5000', 'tax.output' => '2200', 'tax.input' => '1200',
        'tax.reverse_charge.input' => '1220', 'tax.reverse_charge.payable' => '2220', 'tax.withholding.receivable' => '1210', 'tax.withholding.payable' => '2210', 'tax.irrecoverable' => '5200'],
        taxRules: [new TaxRule('EXAMPLE-RC', '0.16', '2026-01-01', '2026-12-31', 'example:1'), new TaxRule('EXAMPLE-WHT', '0.05', '2026-01-01', '2026-12-31', 'example:1'),
            new TaxRule('ZERO', '0', '2026-01-01', '2026-12-31', 'example-zero:1')],
        settings: ['tax_returns' => ['vat' => $policy], 'import_input_requires_payment_and_claim_review' => true], validFrom: '2026-01-01', validUntil: '2026-12-31'));
}

function taxRegisterDocument(Session $ledger, string $book, string $configuration, string $party, string $key, DocumentKind $kind, string $price = '100', string $date = '2026-01-15', string $code = 'EXAMPLE-RC'): Journal
{
    $purchase = in_array($kind, [DocumentKind::PurchaseBill, DocumentKind::PurchaseCredit], true);
    $amount = Money::of($price, Currency::iso('KES'))->multipliedBy($code === 'ZERO' ? '1' : '1.16', Rounding::HalfEven);

    return $ledger->documents->issueTaxed(new TaxedDocumentRequest($book, $key, $date, new Source('return-fixture', $key), $kind, $configuration,
        $purchase ? 'payable' : 'receivable', [new Obligation($party, $amount, $date)], [new TaxLine($purchase ? 'cost' : 'revenue', $price, '1', 'Reviewed source line', [$code])],
        [$code => $purchase ? 'tax.input' : 'tax.output']));
}

it('derives an invoice-level tax register and finalizes a reconciled immutable return', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'return-party', 'Tax counterparty');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'return-sale', DocumentKind::SalesInvoice, '200');
    $purchase = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'return-purchase', DocumentKind::PurchaseBill);
    $decision = $this->ledger->taxes->decide($this->book, 'allow-input', $configuration, 'vat', '2026-01-31', $purchase->id, 1, 'EXAMPLE-RC', 'claim', '0.75', 'Reviewed recoverability', ['invoice_evidence' => 'supplier-42']);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $register = $this->ledger->taxes->register($snapshot, '2026-01-01');
    expect($register)->toHaveCount(2);
    $preview = $this->ledger->taxes->preview($snapshot, $configuration, 'vat', '2026-01-01');
    expect($preview['totals']['output_tax'])->toBe('3200')->and($preview['totals']['input_claimed'])->toBe('1200')
        ->and($preview['net_minor'])->toBe('2000')->and($preview['unreviewed'])->toBe([])->and($preview['unreconciled'])->toBe([]);
    $return = $this->ledger->taxes->prepare($snapshot, 'january-return', $configuration, 'vat', '2026-01-01');
    $before = $this->ledger->taxes->evidence($this->book, $return);
    expect(hash('sha256', $before['result_json']))->toBe($before['result_hash'])->and($before['status'])->toBe('prepared');
    $finalized = $this->ledger->taxes->finalize($this->book, $return, 'finalize-january', '2026-02-01', 'Reviewed return reconciliation');
    expect($this->ledger->taxes->finalize($this->book, $return, 'finalize-january', '2026-02-01', 'Reviewed return reconciliation'))->toBe($finalized);
    $this->ledger->taxes->file($this->book, $return, 'record-filing', '2026-02-02', 'external-receipt-42', 'Record external filing evidence');
    expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('filed')
        ->and($this->ledger->taxes->prepare($snapshot, 'january-return', $configuration, 'vat', '2026-01-01'))->toBe($return)
        ->and($this->ledger->taxes->decide($this->book, 'allow-input', $configuration, 'vat', '2026-01-31', $purchase->id, 1, 'EXAMPLE-RC', 'claim', '0.75', 'Reviewed recoverability', ['invoice_evidence' => 'supplier-42']))->toBe($decision);
});

it('moves deferred input claims between periods without claiming the invoice twice', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'deferred-input', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'deferred-bill', DocumentKind::PurchaseBill);
    $this->ledger->taxes->decide($this->book, 'defer-january', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'defer', '0', 'Await supporting documents');
    $january = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'deferred-january', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $january, 'approve-deferred-january', '2026-02-01', 'Reviewed deferral');
    $this->ledger->taxes->file($this->book, $january, 'file-deferred-january', '2026-02-01', 'JAN-DEFERRED', 'External filing receipt');
    $this->ledger->taxes->decide($this->book, 'claim-half-february', $configuration, 'vat', '2026-02-28', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '0.5', 'Half now eligible');
    $february = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'deferred-february', $configuration, 'vat', '2026-02-01', priorPeriodId: $january);
    expect($this->ledger->taxes->evidence($this->book, $february)['result']['totals']['input_claimed'])->toBe('800');
    $this->ledger->taxes->finalize($this->book, $february, 'approve-deferred-february', '2026-03-01', 'Reviewed half claim');
    $this->ledger->taxes->file($this->book, $february, 'file-deferred-february', '2026-03-01', 'FEB-DEFERRED', 'External filing receipt');
    $this->ledger->taxes->decide($this->book, 'claim-rest-march', $configuration, 'vat', '2026-03-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Remaining eligibility confirmed');
    $march = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-03-31'), 'deferred-march', $configuration, 'vat', '2026-03-01', priorPeriodId: $february);
    $result = $this->ledger->taxes->evidence($this->book, $march)['result'];
    expect($result['entries'])->toBe([])->and($result['totals']['input_claimed'])->toBe('800')
        ->and($result['opening_credit_minor'])->toBe('800')->and($result['credit_carried_minor'])->toBe('1600');
    $this->ledger->taxes->finalize($this->book, $march, 'approve-deferred-march', '2026-04-01', 'Reviewed remaining claim');
});

it('reverses previously claimed input in the correction period and preserves earlier return evidence', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'corrected-tax-input', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'corrected-bill', DocumentKind::PurchaseBill);
    $this->ledger->taxes->decide($this->book, 'claim-corrected-bill', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '0.75', 'Reviewed initial eligibility');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $return = $this->ledger->taxes->prepare($snapshot, 'before-correction', $configuration, 'vat', '2026-01-01');
    $before = $this->ledger->taxes->evidence($this->book, $return);
    $reversal = $this->ledger->journals->reverse($this->book, $invoice->id, 'correct-bill', '2026-02-01', 'Cancel supplier bill');
    $february = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-02-28'), $configuration, 'vat', '2026-02-01');
    expect($february['totals']['input_claimed'])->toBe('-1200')->and($february['net_minor'])->toBe('1200')
        ->and($february['entries'][0]->originId)->toBe($invoice->id)->and($february['entries'][0]->journalId)->toBe($reversal->id)
        ->and($this->ledger->taxes->evidence($this->book, $return))->toEqual($before);
    $march = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-03-31'), $configuration, 'vat', '2026-03-01');
    expect($march['totals']['input_claimed'])->toBe('0');
});

it('requires reviewed tax mappings and per-posting reconciliation even for offsetting manual activity', function (string $case) {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'tax-review-case', 'Counterparty');
    if ($case === 'unreviewed') {
        taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'unreviewed-input', DocumentKind::PurchaseBill);
    } elseif ($case === 'unmapped') {
        $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'unmapped-exempt', '2026-01-15', new Source('return-fixture', 'exempt'), DocumentKind::SalesInvoice,
            $configuration, 'receivable', [new Obligation($party, Money::of('100', $this->kes), '2026-01-31')], [new TaxLine('revenue', '100', '1', 'Unclassified untaxed supply')]));
    } else {
        $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
        foreach ([Side::Debit, Side::Credit] as $index => $side) {
            $this->ledger->journals->post(new JournalRequest($this->book, 'manual-offset-'.$index, '2026-01-31', 'Unclassified offsetting tax entry', [
                new Posting($tax, $side, Money::of('1', $this->kes)), new Posting($this->cash, $side->opposite(), Money::of('1', $this->kes)),
            ]));
        }
    }
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'unresolved-return', $configuration, 'vat', '2026-01-01');
    $result = $this->ledger->taxes->evidence($this->book, $return)['result'];
    expect(match ($case) {
        'unreviewed' => $result['unreviewed'], 'unmapped' => $result['unmapped'], default => $result['unreconciled'],
    })->toHaveCount($case === 'unreconciled' ? 2 : 1);
    $this->ledger->taxes->finalize($this->book, $return, 'reject-unresolved', '2026-02-01', 'Attempt incomplete return');
})->with(['unreviewed', 'unmapped', 'unreconciled'])->throws(QueryException::class, 'unresolved claims, mappings or ledger reconciliation');

it('lists earlier claims and reconciliations that a revised return policy no longer maps', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'withdrawn-mapping-party', 'Supplier');
    $purchase = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'withdrawn-mapping-bill', DocumentKind::PurchaseBill);
    $claim = $this->ledger->taxes->decide($this->book, 'claim-before-revision', $configuration, 'vat', '2026-01-31', $purchase->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Reviewed recoverability');
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'withdrawn-purpose-payment', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('10', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('10', $this->kes)),
    ]));
    $settlement = $this->ledger->taxes->reconcile($this->book, 'settle-before-revision', $configuration, 'vat', '2026-01-31', $payment->id, 1, 'settlement', 'Reviewed payment evidence');
    $revised = taxRegisterConfiguration($this->ledger, $this->book, '2', ['codes' => ['EXAMPLE-RC'], 'reconciliations' => ['settlement']]);
    $result = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-01-31'), $revised, 'vat', '2026-01-01');
    expect($result['unmapped'])->toBe([
        ['journal_id' => $purchase->id, 'line_no' => 1, 'tax_code' => 'EXAMPLE-RC'],
        ['decision_id' => $claim, 'tax_code' => 'EXAMPLE-RC'],
        ['decision_id' => $settlement, 'classification' => 'settlement'],
    ]);
});

it('reconciles tax settlements without treating payments as reduced output-tax liability for the return', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'settled-tax-party', 'Customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'settled-tax-sale', DocumentKind::SalesInvoice, '200');
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'pay-tax', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('10', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('10', $this->kes)),
    ]));
    $this->ledger->taxes->reconcile($this->book, 'classify-payment', $configuration, 'vat', '2026-01-31', $payment->id, 1, 'settlement', 'Reviewed payment evidence', ['receipt' => 'tax-payment-42']);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'settled-return', $configuration, 'vat', '2026-01-01');
    $result = $this->ledger->taxes->evidence($this->book, $return)['result'];
    expect($result['net_minor'])->toBe('3200')->and($result['unreconciled'])->toBe([])
        ->and($result['reconciliation_decisions'][0]['classification'])->toBe('settlement');
    $this->ledger->taxes->finalize($this->book, $return, 'approve-settled-return', '2026-02-01', 'Reviewed liability reconciliation');
});

it('requires explicit linked amendments after backdated tax changes and retains filed evidence', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'tax-amendment-party', 'Customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'original-tax-sale', DocumentKind::SalesInvoice);
    $original = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'original-tax-return', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $original, 'finalize-original-tax', '2026-02-01', 'Original approval');
    $this->ledger->taxes->file($this->book, $original, 'file-original-tax', '2026-02-01', 'ORIGINAL-RECEIPT', 'Original external filing');
    $before = $this->ledger->taxes->evidence($this->book, $original);
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'late-tax-credit', DocumentKind::SalesCredit, '50', '2026-01-31');
    $amended = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'amended-tax-return', $configuration, 'vat', '2026-01-01', previousId: $original);
    expect($this->ledger->taxes->evidence($this->book, $amended)['result']['net_minor'])->toBe('800')
        ->and($this->ledger->taxes->evidence($this->book, $amended)['definition']->previousId)->toBe($original)
        ->and($this->ledger->taxes->evidence($this->book, $original))->toEqual($before);
    $this->ledger->taxes->finalize($this->book, $amended, 'finalize-tax-amendment', '2026-02-02', 'Reviewed amendment');
    $this->ledger->taxes->file($this->book, $amended, 'file-tax-amendment', '2026-02-02', 'AMENDED-RECEIPT', 'Amended external filing');
});

it('rejects stale finalization while preserving external filing receipts for the approved version', function (bool $alreadyFinalized) {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'stale-tax-party', 'Customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'stale-tax-sale', DocumentKind::SalesInvoice);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'stale-tax-return', $configuration, 'vat', '2026-01-01');
    if ($alreadyFinalized) {
        $this->ledger->taxes->finalize($this->book, $return, 'finalized-before-change', '2026-02-01', 'Original review');
    }
    taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'backdated-tax-sale', DocumentKind::SalesInvoice, '100', '2026-01-31');
    if ($alreadyFinalized) {
        $this->ledger->taxes->file($this->book, $return, 'file-stale-return', '2026-02-02', 'STALE-RECEIPT', 'Record receipt for the approved version');
        expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('filed')
            ->and($this->ledger->taxes->evidence($this->book, $return)['result']['net_minor'])->toBe('1600')
            ->and($this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-01-31'), $configuration, 'vat', '2026-01-01')['net_minor'])->toBe('3200');
    } else {
        try {
            $this->ledger->taxes->finalize($this->book, $return, 'finalize-stale-return', '2026-02-01', 'Attempt outdated approval');
            $this->fail('Stale prepared figures require a linked replacement.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('stale; prepare a new linked version');
        }
    }
})->with([false, true]);

it('requires an exclusive unreversed tax payment before claiming pending imported input VAT', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $assessment = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'import-tax-assessment', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '0.75',
    ]));
    try {
        $this->ledger->taxes->decide($this->book, 'unpaid-import-claim', $configuration, 'vat', '2026-01-31', $assessment->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Attempt unpaid claim');
        $this->fail('Pending imported input requires actual payment evidence.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('exclusive unreversed full tax payment');
    }
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.reverse_charge.payable');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'pay-import-tax', '2026-01-31', 'Pay full import tax', [
        new Posting($tax, Side::Debit, Money::of('160', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('160', $this->kes)),
    ]));
    $this->ledger->taxes->decide($this->book, 'paid-import-claim', $configuration, 'vat', '2026-01-31', $assessment->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Reviewed paid input claim', ['payment_journal_id' => $payment->id]);
    $this->ledger->taxes->reconcile($this->book, 'import-payment-classification', $configuration, 'vat', '2026-01-31', $payment->id, 1, 'settlement', 'Classify import tax payment');
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'paid-import-return', $configuration, 'vat', '2026-01-01');
    $result = $this->ledger->taxes->evidence($this->book, $return)['result'];
    expect($result['totals']['input_claimed'])->toBe('12000')->and($result['totals']['reverse_charge_tax'])->toBe('16000')
        ->and($result['net_minor'])->toBe('-12000')->and($result['invalid_claims'])->toBe([]);
    $this->ledger->taxes->finalize($this->book, $return, 'approve-paid-import-return', '2026-02-01', 'Review paid import claim');
    $other = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'second-import-assessment', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '1',
    ]));
    $this->ledger->taxes->decide($this->book, 'reused-import-payment', $configuration, 'vat', '2026-01-31', $other->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Attempt reused payment', ['payment_journal_id' => $payment->id]);
})->throws(QueryException::class, 'exclusive unreversed full tax payment');

it('derives tax register functional amounts from the original foreign calculation and paginates without losing lines', function () {
    $configuration = taxDocumentConfiguration($this->ledger, $this->book, [new TaxRule('EXAMPLE', '0.16', '2026-01-01', null, 'example:1')], foreignControl: true);
    $party = $this->ledger->catalog->party($this->entity, 'foreign-tax-register', 'Foreign customer');
    $usd = Currency::iso('USD');
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'foreign-tax-register', '2026-01-15', new Source('tax-register', 'USD-1'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($party, Money::of('232', $usd), '2026-01-31')],
        [new TaxLine('revenue', '100', '1', 'First foreign line', ['EXAMPLE']), new TaxLine('revenue', '100', '1', 'Second foreign line', ['EXAMPLE'])], ['EXAMPLE' => 'tax.output'],
        exchangeRate: new ExchangeRate($usd, $this->kes, '1.5', '2026-01-15', 'approved-rate', Rounding::HalfEven)));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $first = $this->ledger->taxes->register($snapshot, limit: 1);
    $second = $this->ledger->taxes->register($snapshot, limit: 1, afterSequence: $first[0]->sequence, afterLine: $first[0]->lineNo, afterCode: $first[0]->taxCode);
    expect($first[0]->base->decimal())->toBe('150.00')->and($first[0]->tax->decimal())->toBe('24.00')
        ->and($first[0]->lineNo)->toBe(1)->and($second[0]->lineNo)->toBe(2);
});

it('prepares and records tax returns after the financial period closes without reopening the ledger', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $this->ledger->periods->close($this->book, $this->period, 'Close financial year');
    $return = $this->ledger->taxes->prepare($snapshot, 'closed-period-return', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $return, 'closed-period-approval', '2026-02-01', 'Review closed financial period tax');
    $this->ledger->taxes->file($this->book, $return, 'closed-period-filing', '2026-02-01', 'CLOSED-PERIOD-RECEIPT', 'Record external filing');
    expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('filed');
});

it('invalidates a prepared carry-forward when its preceding filed return is amended', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'carry-forward-party', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'carry-forward-bill', DocumentKind::PurchaseBill);
    $this->ledger->taxes->decide($this->book, 'initial-carry-claim', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Original eligibility');
    $january = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'carry-january', $configuration, 'vat', '2026-01-01');
    $this->ledger->taxes->finalize($this->book, $january, 'approve-carry-january', '2026-02-01', 'Approve original credit');
    $this->ledger->taxes->file($this->book, $january, 'file-carry-january', '2026-02-01', 'CARRY-JANUARY', 'Original external receipt');
    $february = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'carry-february', $configuration, 'vat', '2026-02-01', priorPeriodId: $january);
    $this->ledger->taxes->decide($this->book, 'withdraw-carry-claim', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'deny', '0', 'Correct original eligibility assessment');
    $amended = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'carry-january-amended', $configuration, 'vat', '2026-01-01', previousId: $january);
    $this->ledger->taxes->finalize($this->book, $amended, 'approve-carry-amendment', '2026-02-02', 'Review corrected credit');
    $this->ledger->taxes->file($this->book, $amended, 'file-carry-amendment', '2026-02-02', 'CARRY-JANUARY-AMENDED', 'Amended external receipt');
    expect($this->ledger->taxes->evidence($this->book, $february)['result']['opening_credit_minor'])->toBe('1600');
    $this->ledger->taxes->finalize($this->book, $february, 'reject-old-carry', '2026-03-01', 'Attempt stale credit carry-forward');
})->throws(QueryException::class, 'opening-credit return was amended');

it('does not silently omit an available filed credit from the next return', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'imported-credit-january', $configuration, 'vat', '2026-01-01',
        openingCredit: '100', openingReference: 'reviewed-opening-credit-certificate');
    $this->ledger->taxes->finalize($this->book, $return, 'approve-imported-credit', '2026-02-01', 'Approve opening credit evidence');
    $this->ledger->taxes->file($this->book, $return, 'file-imported-credit', '2026-02-01', 'IMPORTED-CREDIT', 'External receipt');
    $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-02-28'), 'omit-prior-credit', $configuration, 'vat', '2026-02-01');
})->throws(QueryException::class, 'Link the preceding filed tax credit');

it('retains source parties and complete claim decision history across partial claim rounding', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $party = $this->ledger->catalog->party($this->entity, 'small-input-party', 'Supplier');
    $invoice = taxRegisterDocument($this->ledger, $this->book, $configuration, $party, 'small-input', DocumentKind::PurchaseBill, '0.04');
    $half = $this->ledger->taxes->decide($this->book, 'half-small-input', $configuration, 'vat', '2026-01-31', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '0.5', 'Partial eligibility');
    $before = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $this->ledger->taxes->decide($this->book, 'all-small-input', $configuration, 'vat', '2026-02-28', $invoice->id, 1, 'EXAMPLE-RC', 'claim', '1', 'Full eligibility');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $register = $this->ledger->taxes->register($snapshot);
    expect($register[0]->partyIds)->toBe([$party])
        ->and($register[0]->sourceReference)->toBe('small-input')
        ->and($this->ledger->taxes->decisions($before, 'vat', $invoice->id))->toHaveCount(1)
        ->and($this->ledger->taxes->decisions($snapshot, 'vat', $invoice->id))->toHaveCount(2)
        ->and($this->ledger->taxes->decisions($before, 'vat', $invoice->id)[0]->id)->toBe($half)
        ->and($this->ledger->taxes->preview($snapshot, $configuration, 'vat', '2026-02-01')['totals']['input_claimed'])->toBe('1');
});

it('rejects unknown tax reconciliation purposes instead of manufacturing an empty payable', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'unknown-purpose-journal', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('1', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('1', $this->kes)),
    ]));
    $this->ledger->taxes->reconcile($this->book, 'unknown-purpose', $configuration, 'vat', '2026-01-31', $journal->id, 1, 'misspelled-purpose', 'Invalid purpose');
})->throws(QueryException::class, 'approved purpose');

it('invalidates import claims after their payment is corrected and records the recapture', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $assessment = $this->ledger->treatments->post(taxTreatmentRequest($this->book, $configuration, 'reversed-payment-assessment', [
        'operation' => 'reverse_charge', 'tax_code' => 'EXAMPLE-RC', 'tax_base' => '1000', 'recoverable_fraction' => '1',
    ]));
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.reverse_charge.payable');
    $payment = $this->ledger->journals->post(new JournalRequest($this->book, 'reversed-import-payment', '2026-01-31', 'Tax payment', [
        new Posting($tax, Side::Debit, Money::of('160', $this->kes)), new Posting($this->cash, Side::Credit, Money::of('160', $this->kes)),
    ]));
    $claim = $this->ledger->taxes->decide($this->book, 'original-paid-claim', $configuration, 'vat', '2026-01-31', $assessment->id, 2, 'EXAMPLE-RC', 'claim', '1', 'Reviewed paid input', ['payment_journal_id' => $payment->id]);
    $this->ledger->journals->reverse($this->book, $payment->id, 'cancel-import-payment', '2026-02-01', 'Correct failed payment');
    $preview = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-02-28'), $configuration, 'vat', '2026-02-01');
    expect($preview['invalid_claims'])->toBe([$claim]);
    $this->ledger->taxes->decide($this->book, 'recapture-unpaid-input', $configuration, 'vat', '2026-02-28', $assessment->id, 2, 'EXAMPLE-RC', 'deny', '0', 'Recapture unpaid claim');
    $preview = $this->ledger->taxes->preview($this->ledger->reports->snapshot($this->book, '2026-02-28'), $configuration, 'vat', '2026-02-01');
    expect($preview['invalid_claims'])->toBe([])->and($preview['totals']['input_claimed'])->toBe('-16000');
});

it('requires tax permission independently of financial read and posting permissions', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $denied = (new Ledger($this->storage->connection, new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Tax;
        }
    }))->for($this->scope);
    $snapshot = $denied->reports->snapshot($this->book, '2026-01-31');
    expect($denied->taxes->register($snapshot))->toBe([]);
    $denied->taxes->prepare($snapshot, 'denied-tax-prepare', $configuration, 'vat', '2026-01-01');
})->throws(LedgerException::class, 'ledger.tax');

it('enforces the tax return filing and void state transitions', function (bool $filed) {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $return = $this->ledger->taxes->prepare($this->ledger->reports->snapshot($this->book, '2026-01-31'), 'tax-state-machine', $configuration, 'vat', '2026-01-01');
    if ($filed) {
        $this->ledger->taxes->finalize($this->book, $return, 'approve-state-return', '2026-02-01', 'Review nil return');
        $this->ledger->taxes->file($this->book, $return, 'file-state-return', '2026-02-01', 'STATE-RETURN-RECEIPT', 'Record external filing');
    }
    try {
        if ($filed) {
            $this->ledger->taxes->void($this->book, $return, 'void-filed-return', '2026-02-02', 'Attempt to erase filed history');
        } else {
            $this->ledger->taxes->file($this->book, $return, 'file-unapproved-return', '2026-02-01', 'UNAPPROVED-RECEIPT', 'Attempt unreviewed filing');
        }
        $this->fail('Invalid tax return state transition must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($filed ? 'Filed or voided tax returns cannot be voided' : 'Filing requires a finalized return');
    }
    expect($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe($filed ? 'filed' : 'prepared');
})->with([false, true]);

it('derives exact compound taxable bases from inclusive and exclusive source rules', function (bool $inclusive) {
    taxRegisterConfiguration($this->ledger, $this->book);
    $configuration = $this->ledger->configurations->install($this->book, new Pack('compound-register', '1', roles: ['receivable' => '1100', 'revenue' => '4000', 'tax.output' => '2200'],
        taxRules: [new TaxRule('FIRST', '0.1', '2026-01-01', null, 'first:1', inclusive: $inclusive), new TaxRule('SECOND', '0.05', '2026-01-01', null, 'second:1', inclusive: $inclusive, compound: true)]));
    $party = $this->ledger->catalog->party($this->entity, 'compound-register-party', 'Customer');
    $this->ledger->documents->issueTaxed(new TaxedDocumentRequest($this->book, 'compound-register-sale', '2026-01-15', new Source('tax-register', 'compound'), DocumentKind::SalesInvoice,
        $configuration, 'receivable', [new Obligation($party, Money::of('115.50', $this->kes), '2026-01-31')],
        [new TaxLine('revenue', $inclusive ? '115.50' : '100', '1', 'Ordered compound tax', ['FIRST', 'SECOND'])], ['FIRST' => 'tax.output', 'SECOND' => 'tax.output']));
    $rows = collect($this->ledger->taxes->register($this->ledger->reports->snapshot($this->book, '2026-01-31')))->keyBy('taxCode');
    expect($rows['FIRST']?->base->decimal())->toBe('100.00')->and($rows['FIRST']?->tax->decimal())->toBe('10.00')
        ->and($rows['SECOND']?->base->decimal())->toBe('110.00')->and($rows['SECOND']?->tax->decimal())->toBe('5.50');
})->with([false, true]);

it('summarizes the tax register into Kenya VAT3 sections split by customer and supplier PIN', function () {
    $configuration = taxRegisterConfiguration($this->ledger, $this->book);
    $registered = $this->ledger->catalog->party($this->entity, 'vat3-registered', 'Registered counterparty');
    $walkIn = $this->ledger->catalog->party($this->entity, 'vat3-walk-in', 'Walk-in customer');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $registered, 'vat3-standard-sale', DocumentKind::SalesInvoice, '200');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $walkIn, 'vat3-zero-sale', DocumentKind::SalesInvoice, '100', code: 'ZERO');
    taxRegisterDocument($this->ledger, $this->book, $configuration, $registered, 'vat3-purchase', DocumentKind::PurchaseBill);
    $register = $this->ledger->taxes->register($this->ledger->reports->snapshot($this->book, '2026-01-31'), '2026-01-01');
    $first = $register[0];
    $unmapped = new TaxEntry($first->journalId, $first->originId, $first->effectiveOn, $first->sequence, $first->lineNo, 'UNMAPPED', $first->kind, $first->configurationId,
        $first->accountId, $first->sourceType, $first->sourceReference, [], Money::minor('999', $this->kes), Money::minor('99', $this->kes), $first->ledger, $first->claimable, $first->requiresPayment, $first->isReversal);
    $sections = Kenya::vat3Sections([...$register, $unmapped], [$registered => 'A123456789B'], ['EXAMPLE-RC' => 'B', 'ZERO' => 'D']);
    expect(array_keys($sections))->toBe(['B', 'C', 'D', 'E', 'F'])
        ->and($sections['B']['with_pin'])->toBe(['base_minor' => '20000', 'tax_minor' => '3200', 'documents' => 1])
        ->and($sections['B']['without_pin']['documents'])->toBe(0)
        ->and($sections['D']['without_pin'])->toBe(['base_minor' => '10000', 'tax_minor' => '0', 'documents' => 1])
        ->and($sections['F']['with_pin'])->toBe(['base_minor' => '10000', 'tax_minor' => '1600', 'documents' => 1])
        ->and($sections['F']['label'])->toBe('Purchases and input tax');
    expect(fn () => Kenya::vat3Sections([], [], ['EXAMPLE-RC' => 'F']))->toThrow(LedgerException::class, 'VAT3 sections');
});
