<?php

declare(strict_types=1);

use NoriaLabs\Ledger\Access\Authorizer;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Access\Scope;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Consolidation\Member;
use NoriaLabs\Ledger\Exceptions\LedgerException;
use NoriaLabs\Ledger\Ledger;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Money\Rounding;
use NoriaLabs\Ledger\Reporting\Snapshot;

beforeEach(function () {
    $entity = $this->ledger->catalog->entity('Consolidated group');
    $this->groupBook = $this->ledger->catalog->book($entity, 'Consolidation eliminations', $this->kes);
    $this->ledger->periods->open($this->groupBook, '2026', '2026-01-01', '2026-12-31');
    $this->groupCash = $this->ledger->catalog->account($this->groupBook, '1000', 'Group cash \\ 🌍', AccountType::Asset);
    $this->groupIncome = $this->ledger->catalog->account($this->groupBook, '4000', 'Group income', AccountType::Income);
    $this->groupEquity = $this->ledger->catalog->account($this->groupBook, '3000', 'Group equity', AccountType::Equity);
    $this->cta = $this->ledger->catalog->account($this->groupBook, '3100', 'Translation reserve', AccountType::Equity);
    $this->roundingReserve = $this->ledger->catalog->account($this->groupBook, '3200', 'Translation rounding', AccountType::Equity);
});

it('keeps presentation rounding separate when individual translated accounts round independently', function () {
    $usd = Currency::iso('USD');
    $entity = $this->ledger->catalog->entity('Rounding subsidiary', 'US');
    $book = $this->ledger->catalog->book($entity, 'USD', $usd);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $first = $this->ledger->catalog->account($book, '1000', 'First cash', AccountType::Asset);
    $second = $this->ledger->catalog->account($book, '1001', 'Second cash', AccountType::Asset);
    $equity = $this->ledger->catalog->account($book, '3000', 'Capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($book, 'fund', '2026-01-01', 'Two cents', [
        new Posting($first, Side::Debit, Money::of('0.01', $usd)), new Posting($second, Side::Debit, Money::of('0.01', $usd)),
        new Posting($equity, Side::Credit, Money::of('0.02', $usd)),
    ]));
    $rate = new ExchangeRate($usd, $this->kes, '1.5', '2026-01-01', 'rounding-fixture', Rounding::HalfEven);
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$first => $this->groupCash, $second => $this->groupCash, $equity => $this->groupEquity], $rate, null, [$equity => $rate]);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'rounding', '2026-01-01', [$member], $this->cta, $this->roundingReserve, 'rounding:v1');
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    $evidence = $this->ledger->consolidation->evidence($this->groupBook, $id);
    expect(hash('sha256', $evidence['result_json']))->toBe($evidence['result_hash']);
    expect($evidence['inputs']['translation_reserve_id'])->toBe($this->cta);
    expect($report['translation_adjustment']['amount'])->toBe('0.00');
    expect($report['rounding_adjustment']['amount'])->toBe('-0.01');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
});

it('preserves exact translated equity rollforwards without inventing blended exchange rates', function () {
    $usd = Currency::iso('USD');
    $entity = $this->ledger->catalog->entity('Historical equity subsidiary', 'US');
    $book = $this->ledger->catalog->book($entity, 'USD', $usd);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Cash', AccountType::Asset);
    $equity = $this->ledger->catalog->account($book, '3000', 'Capital', AccountType::Equity);
    $amount = Money::of('3', $usd);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital from distinct historical contributions', [
        new Posting($cash, Side::Debit, $amount), new Posting($equity, Side::Credit, $amount),
    ]));
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-12-31', 'closing', Rounding::HalfEven);
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash, $equity => $this->groupEquity],
        closingRate: $closing, historicalEquityAmounts: [$equity => Money::of('-410', $this->kes)]);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'historical-amount', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'historical-rollforward:v1');
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['translation_adjustment']['amount'])->toBe('-10.00');
    expect($report['balance_sheet']['equity']['amount'])->toBe('420.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
});

it('rejects omitted mappings for source balances', function () {
    $money = Money::of('1', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money)]));
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'missing-map', '2026-01-01',
        [new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [$this->cash => $this->groupCash])], $this->cta, $this->roundingReserve, 'full-scope:v1');
})->throws(LedgerException::class, 'requires a compatible group account mapping');

it('rejects consolidating parallel books of one entity twice', function () {
    $parallel = $this->ledger->catalog->book($this->entity, 'Parallel treatment', $this->kes);
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'duplicate-entity', '2026-01-01',
        [new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), []), new Member($this->ledger->reports->snapshot($parallel, '2026-12-31'), [])],
        $this->cta, $this->roundingReserve, 'full-scope:v1');
})->throws(LedgerException::class, 'parallel books');

it('rechecks source permissions when reading an existing consolidated report', function () {
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'access', '2026-01-01',
        [new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [])], $this->cta, $this->roundingReserve, 'full-scope:v1');
    $authorizer = new class($this->book) implements Authorizer
    {
        public function __construct(private string $deniedBook) {}

        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $bookId !== $this->deniedBook;
        }
    };
    $restricted = (new Ledger($this->app['db']->connection(), $authorizer))->for($this->scope);
    $restricted->consolidation->get($this->groupBook, $id);
})->throws(LedgerException::class, 'access denied');

it('rejects future source cutoffs that could change when later entries arrive', function () {
    $future = new Snapshot($this->book, '2026-12-31', '9999');
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'future', '2026-01-01',
        [new Member($future, [])], $this->cta, $this->roundingReserve, 'full-scope:v1');
})->throws(LedgerException::class, 'future knowledge cutoff');

it('materializes stable full-scope group reports and incorporates elimination entries', function () {
    $amount = Money::of('100', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->book, 'income', '2026-01-01', 'Income', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    $eliminate = Money::of('20', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'eliminate', '2026-01-01', 'Illustrative elimination', [new Posting($this->groupIncome, Side::Debit, $eliminate), new Posting($this->groupCash, Side::Credit, $eliminate)]));
    $target = $this->ledger->reports->snapshot($this->groupBook, '2026-12-31');
    $members = [new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [$this->cash => $this->groupCash, $this->income => $this->groupIncome])];
    $id = $this->ledger->consolidation->run($target, 'group-2026', '2026-01-01', $members, $this->cta, $this->roundingReserve, 'full-scope:v1');
    expect($this->ledger->consolidation->run($target, 'group-2026', '2026-01-01', $members, $this->cta, $this->roundingReserve, 'full-scope:v1'))->toBe($id);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['balance_sheet']['assets']['amount'])->toBe('80.00');
    expect($report['income_statement']['profit']['amount'])->toBe('80.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
    $this->ledger->journals->post(new JournalRequest($this->book, 'later-backdate', '2026-01-01', 'Later backdated income', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]));
    expect($this->ledger->consolidation->get($this->groupBook, $id))->toBe($report);
});

it('separates currency translation reserves from presentation rounding', function () {
    $usd = Currency::iso('USD');
    $entity = $this->ledger->catalog->entity('Foreign subsidiary', 'US');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $usd);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'USD cash', AccountType::Asset);
    $equity = $this->ledger->catalog->account($book, '3000', 'USD capital', AccountType::Equity);
    $income = $this->ledger->catalog->account($book, '4000', 'USD income', AccountType::Income);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital', [new Posting($cash, Side::Debit, Money::of('100', $usd)), new Posting($equity, Side::Credit, Money::of('100', $usd))]));
    $this->ledger->journals->post(new JournalRequest($book, 'income', '2026-06-01', 'Income', [new Posting($cash, Side::Debit, Money::of('100', $usd)), new Posting($income, Side::Credit, Money::of('100', $usd))]));
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-12-31', 'closing:v1', Rounding::HalfEven);
    $average = new ExchangeRate($usd, $this->kes, '135', '2026-12-31', 'period-average:v1', Rounding::HalfEven);
    $historical = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'capital-history:v1', Rounding::HalfEven);
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash, $equity => $this->groupEquity, $income => $this->groupIncome], $closing, $average, [$equity => $historical]);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'translated', '2026-01-01', [$member], $this->cta, $this->roundingReserve, 'current-rate:v1');
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['balance_sheet']['assets']['amount'])->toBe('28000.00');
    expect($report['income_statement']['profit']['amount'])->toBe('13500.00');
    expect($report['translation_adjustment']['amount'])->toBe('-1500.00');
    expect($report['rounding_adjustment']['amount'])->toBe('0.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
});
