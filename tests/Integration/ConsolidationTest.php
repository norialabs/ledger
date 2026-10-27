<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Consolidation\Investment;
use Noria\Ledger\Consolidation\Member;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Canonical;

beforeEach(function () {
    $entity = $this->ledger->catalog->entity('Consolidated group');
    $this->groupBook = $this->ledger->catalog->book($entity, 'Consolidation eliminations', $this->kes);
    $this->ledger->periods->open($this->groupBook, '2026', '2026-01-01', '2026-12-31');
    $this->groupCash = $this->ledger->catalog->account($this->groupBook, '1000', 'Group cash \\ 🌍', AccountType::Asset);
    $this->groupIncome = $this->ledger->catalog->account($this->groupBook, '4000', 'Group income', AccountType::Income);
    $this->groupEquity = $this->ledger->catalog->account($this->groupBook, '3000', 'Group equity', AccountType::Equity);
    $this->cta = $this->ledger->catalog->account($this->groupBook, '3100', 'Translation reserve', AccountType::Equity);
    $this->roundingReserve = $this->ledger->catalog->account($this->groupBook, '3200', 'Translation rounding', AccountType::Equity);
    $this->nci = $this->ledger->catalog->account($this->groupBook, '3300', 'Non-controlling interest', AccountType::Equity);
    $this->retained = $this->ledger->catalog->account($this->groupBook, '3400', 'Group retained earnings', AccountType::Equity);
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

it('attributes non-controlling interest at the closing-rate share of net assets', function () {
    $usd = Currency::iso('USD');
    $entity = $this->ledger->catalog->entity('Minority subsidiary', 'US');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $usd);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'USD cash', AccountType::Asset);
    $equity = $this->ledger->catalog->account($book, '3000', 'USD capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital', [new Posting($cash, Side::Debit, Money::of('100', $usd)), new Posting($equity, Side::Credit, Money::of('100', $usd))]));
    $closing = new ExchangeRate($usd, $this->kes, '140', '2026-12-31', 'closing:v1', Rounding::HalfEven);
    $historical = new ExchangeRate($usd, $this->kes, '130', '2026-01-01', 'capital-history:v1', Rounding::HalfEven);
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash, $equity => $this->groupEquity],
        $closing, null, [$equity => $historical], ownershipFraction: '0.8');
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'nci', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'nci:v1', $this->nci, retainedEarningsAccountId: $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['non_controlling_interest']['amount'])->toBe('2800.00');
    expect($report['balance_sheet']['assets']['amount'])->toBe('14000.00');
    expect($report['balance_sheet']['equity']['amount'])->toBe('14000.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
    $balances = collect($report['trial_balance'])->keyBy('account_id');
    expect($balances[$this->nci]['closing']['amount'])->toBe('-2800.00');
    expect($balances[$this->cta]['closing']['amount'])->toBe('-800.00');
    expect($balances[$this->groupEquity]['closing']['amount'])->toBe('-10400.00');
});

it('attributes deficit net assets to NCI without a zero floor or a fictitious translation reserve', function () {
    $entity = $this->ledger->catalog->entity('Net-liability subsidiary');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $loan = $this->ledger->catalog->account($book, '1000', 'Cash overdraft', AccountType::Liability);
    $equity = $this->ledger->catalog->account($book, '3000', 'Deficit capital', AccountType::Equity);
    $groupLiability = $this->ledger->catalog->account($this->groupBook, '2000', 'Group liabilities', AccountType::Liability);
    $this->ledger->journals->post(new JournalRequest($book, 'deficit', '2026-01-01', 'Borrowed into deficit', [new Posting($equity, Side::Debit, Money::of('100', $this->kes)), new Posting($loan, Side::Credit, Money::of('100', $this->kes))]));
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$loan => $groupLiability, $equity => $this->groupEquity], ownershipFraction: '0.7');
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'net-liability', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'nci:v1', $this->nci, retainedEarningsAccountId: $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['non_controlling_interest']['amount'])->toBe('-30.00');
    expect($report['translation_adjustment']['amount'])->toBe('0.00');
    expect(collect($report['trial_balance'])->keyBy('account_id')[$this->groupEquity]['closing']['amount'])->toBe('70.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
});

it('rejects ownership fractions outside the unit interval and missing NCI accounts', function (string $fraction, string $message) {
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [], ownershipFraction: $fraction);
})->with([['0', 'positive'], ['1.01', 'at most one'], ['-0.5', 'positive']])->throws(LedgerException::class);

it('requires an explicit NCI account whenever a member is not wholly owned', function () {
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [], ownershipFraction: '0.9');
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'missing-nci', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'nci:v1');
})->throws(LedgerException::class, 'NCI equity account');

it('rejects a non-equity NCI account', function () {
    $income = $this->ledger->catalog->account($this->groupBook, '4100', 'Not equity', AccountType::Income);
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [], ownershipFraction: '0.9');
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'bad-nci-account', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'nci:v1', $income, retainedEarningsAccountId: $this->retained);
})->throws(LedgerException::class, 'distinct equity posting account');

it('eliminates an investment in a wholly owned subsidiary and recognizes goodwill', function () {
    $entity = $this->ledger->catalog->entity('Acquired subsidiary');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Subsidiary cash', AccountType::Asset);
    $capital = $this->ledger->catalog->account($book, '3000', 'Subsidiary capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital', [new Posting($cash, Side::Debit, Money::of('100', $this->kes)), new Posting($capital, Side::Credit, Money::of('100', $this->kes))]));
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment in subsidiary', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain purchase gain', AccountType::Income);
    $parentCapital = $this->ledger->catalog->account($this->groupBook, '3001', 'Parent capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'invest', '2026-01-01', 'Acquire subsidiary', [new Posting($investment, Side::Debit, Money::of('120', $this->kes)), new Posting($parentCapital, Side::Credit, Money::of('120', $this->kes))]));
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash]);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'acquisition', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'acquisition:v1', investments: [new Investment($book, $investment, $goodwill, $bargain, $this->ledger->reports->snapshot($book, '2026-01-01'), Money::of('120', $this->kes))], retainedEarningsAccountId: $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['goodwill'][$book]['amount'])->toBe('20.00');
    expect($report['balance_sheet']['assets']['amount'])->toBe('120.00');
    expect($report['balance_sheet']['equity']['amount'])->toBe('120.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
    $balances = collect($report['trial_balance'])->keyBy('account_id');
    expect($balances[$investment]['closing']['amount'])->toBe('0.00');
    expect($balances[$goodwill]['closing']['amount'])->toBe('20.00');
    expect($balances[$this->groupCash]['closing']['amount'])->toBe('100.00');
});

it('recognizes a bargain purchase gain when consideration is below net assets', function () {
    $entity = $this->ledger->catalog->entity('Bargain subsidiary');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Subsidiary cash', AccountType::Asset);
    $capital = $this->ledger->catalog->account($book, '3000', 'Subsidiary capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital', [new Posting($cash, Side::Debit, Money::of('100', $this->kes)), new Posting($capital, Side::Credit, Money::of('100', $this->kes))]));
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment in subsidiary', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain purchase gain', AccountType::Income);
    $parentCapital = $this->ledger->catalog->account($this->groupBook, '3001', 'Parent capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'invest', '2026-01-01', 'Acquire subsidiary', [new Posting($investment, Side::Debit, Money::of('80', $this->kes)), new Posting($parentCapital, Side::Credit, Money::of('80', $this->kes))]));
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash]);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'bargain', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'acquisition:v1', investments: [new Investment($book, $investment, $goodwill, $bargain, $this->ledger->reports->snapshot($book, '2026-01-01'), Money::of('80', $this->kes))], retainedEarningsAccountId: $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['goodwill'][$book]['amount'])->toBe('0.00');
    expect($report['bargain_purchase_gain'][$book]['amount'])->toBe('20.00');
    expect($report['income_statement']['profit']['amount'])->toBe('20.00');
    $balances = collect($report['trial_balance'])->keyBy('account_id');
    expect($balances[$bargain]['closing']['amount'])->toBe('-20.00');
    expect($balances[$goodwill]['closing']['amount'])->toBe('0.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
    $later = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'bargain-later-period', '2026-07-01',
        [$member], $this->cta, $this->roundingReserve, 'acquisition:v1', investments: [new Investment($book, $investment, $goodwill, $bargain, $this->ledger->reports->snapshot($book, '2026-01-01'), Money::of('80', $this->kes))], retainedEarningsAccountId: $this->retained);
    $laterReport = $this->ledger->consolidation->get($this->groupBook, $later);
    expect($laterReport['income_statement']['profit']['amount'])->toBe('0.00');
    expect(collect($laterReport['trial_balance'])->keyBy('account_id')[$this->retained]['closing']['amount'])->toBe('-20.00');
});

it('eliminates investment against the parent share with NCI and partial goodwill', function () {
    $entity = $this->ledger->catalog->entity('Minority acquired subsidiary');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Subsidiary cash', AccountType::Asset);
    $capital = $this->ledger->catalog->account($book, '3000', 'Subsidiary capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital', [new Posting($cash, Side::Debit, Money::of('100', $this->kes)), new Posting($capital, Side::Credit, Money::of('100', $this->kes))]));
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment in subsidiary', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain purchase gain', AccountType::Income);
    $parentCapital = $this->ledger->catalog->account($this->groupBook, '3001', 'Parent capital', AccountType::Equity);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'invest', '2026-01-01', 'Acquire 80%', [new Posting($investment, Side::Debit, Money::of('90', $this->kes)), new Posting($parentCapital, Side::Credit, Money::of('90', $this->kes))]));
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash], ownershipFraction: '0.8');
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'minority-acquisition', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'acquisition:v1', $this->nci, [new Investment($book, $investment, $goodwill, $bargain, $this->ledger->reports->snapshot($book, '2026-01-01'), Money::of('90', $this->kes))], $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['goodwill'][$book]['amount'])->toBe('10.00');
    expect($report['non_controlling_interest']['amount'])->toBe('20.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
    $balances = collect($report['trial_balance'])->keyBy('account_id');
    expect($balances[$this->nci]['closing']['amount'])->toBe('-20.00');
    expect($balances[$goodwill]['closing']['amount'])->toBe('10.00');
});

it('rejects investment elimination with invalid accounts or unknown investees', function (string $kind, string $message) {
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain', AccountType::Income);
    $unknown = (string) Str::uuid7();
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-01');
    $cost = Money::of('10', $this->kes);
    $investmentFor = match ($kind) {
        'unknown' => new Investment($unknown, $investment, $goodwill, $bargain, new Snapshot($unknown, '2026-01-01', '0'), $cost),
        'wrong-investment' => new Investment($this->book, $this->groupEquity, $goodwill, $bargain, $snapshot, $cost),
        'wrong-goodwill' => new Investment($this->book, $investment, $this->groupEquity, $bargain, $snapshot, $cost),
        'wrong-bargain' => new Investment($this->book, $investment, $goodwill, $this->groupEquity, $snapshot, $cost),
    };
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), []);
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'bad-investment', '2026-01-01',
        [$member], $this->cta, $this->roundingReserve, 'acquisition:v1', investments: [$investmentFor], retainedEarningsAccountId: $this->retained);
})->with([
    ['unknown', 'consolidated investee member'], ['wrong-investment', 'asset investment account'],
    ['wrong-goodwill', 'asset account'], ['wrong-bargain', 'income account'],
])->throws(LedgerException::class);

it('preserves acquisition goodwill and attributes post-acquisition earnings before and after source closing', function (bool $foreign, string $ownership, bool $closed, bool $loss) {
    $currency = $foreign ? Currency::iso('USD') : $this->kes;
    $entity = $this->ledger->catalog->entity('Acquisition rollforward');
    $book = $this->ledger->catalog->book($entity, 'Statutory', $currency);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $cash = $this->ledger->catalog->account($book, '1000', 'Cash', AccountType::Asset);
    $capital = $this->ledger->catalog->account($book, '3000', 'Capital', AccountType::Equity);
    $retained = $this->ledger->catalog->account($book, '3100', 'Retained earnings', AccountType::Equity);
    $sales = $this->ledger->catalog->account($book, '4000', 'Sales', AccountType::Income);
    $groupSales = $this->groupIncome;
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain', AccountType::Income);
    $this->ledger->journals->post(new JournalRequest($book, 'capital', '2026-01-01', 'Capital', [new Posting($cash, Side::Debit, Money::of('100', $currency)), new Posting($capital, Side::Credit, Money::of('100', $currency))]));
    $acquired = $this->ledger->reports->snapshot($book, '2026-01-01');
    $cost = Money::of($ownership === '1' ? ($foreign ? '15600' : '120') : ($foreign ? '11700' : '90'), $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'acquire', '2026-01-01', 'Acquire', [new Posting($investment, Side::Debit, $cost), new Posting($this->groupEquity, Side::Credit, $cost)]));
    $movement = Money::of($loss ? '150' : '40', $currency);
    $this->ledger->journals->post(new JournalRequest($book, 'profit', '2026-02-01', 'Earnings movement', [new Posting($cash, $loss ? Side::Credit : Side::Debit, $movement), new Posting($sales, $loss ? Side::Debit : Side::Credit, $movement)]));
    if ($closed) {
        $this->ledger->yearEnd->close($book, 'close', '2026-01-01', '2026-12-31', $retained, 'Close source year');
    }
    $historical = $foreign ? new ExchangeRate($currency, $this->kes, '130', '2026-01-01', 'acquisition', Rounding::HalfEven) : null;
    $closing = $foreign ? new ExchangeRate($currency, $this->kes, '140', '2026-12-31', 'closing', Rounding::HalfEven) : null;
    $income = $foreign ? new ExchangeRate($currency, $this->kes, '135', '2026-12-31', 'income', Rounding::HalfEven) : null;
    $member = new Member($this->ledger->reports->snapshot($book, '2026-12-31'), [$cash => $this->groupCash, $sales => $groupSales, $retained => $this->retained],
        $closing, $income, $income === null ? [] : [$retained => $income], ownershipFraction: $ownership);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'rollforward', '2026-01-01', [$member],
        $this->cta, $this->roundingReserve, 'acquisition:v2', $ownership === '1' ? null : $this->nci,
        [new Investment($book, $investment, $goodwill, $bargain, $acquired, $cost, $historical)], $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    expect($report['goodwill'][$book]['amount'])->toBe($ownership === '1' ? ($foreign ? '2800.00' : '20.00') : ($foreign ? '1400.00' : '10.00'));
    expect($report['bargain_purchase_gain'][$book]['amount'])->toBe('0.00');
    expect($report['income_statement']['profit']['amount'])->toBe($loss ? ($foreign ? '-20250.00' : '-150.00') : ($foreign ? '5400.00' : '40.00'));
    expect($report['profit_attribution']['non_controlling_interest']['amount'])->toBe($ownership === '1' ? '0.00' : ($loss ? ($foreign ? '-4050.00' : '-30.00') : ($foreign ? '1080.00' : '8.00')));
    expect($report['profit_attribution']['parent']['amount'])->toBe($ownership === '1' ? ($loss ? ($foreign ? '-20250.00' : '-150.00') : ($foreign ? '5400.00' : '40.00')) : ($loss ? ($foreign ? '-16200.00' : '-120.00') : ($foreign ? '4320.00' : '32.00')));
    expect($report['non_controlling_interest']['amount'])->toBe($ownership === '1' ? '0.00' : ($loss ? ($foreign ? '-1400.00' : '-10.00') : ($foreign ? '3920.00' : '28.00')));
    expect($report['translation_adjustment']['amount'])->toBe($foreign ? ($loss ? ($ownership === '1' ? '-450.00' : '-300.00') : ($ownership === '1' ? '-1400.00' : '-1060.00')) : '0.00');
    expect($report['rounding_adjustment']['amount'])->toBe('0.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
    expect($this->ledger->consolidation->evidence($this->groupBook, $id)['inputs']['investments'][0]['acquisition_snapshot']['through_sequence'])->toBe($acquired->throughSequence);
})->with([false, true])->with(['1', '0.8'])->with([false, true])->with([false, true]);

it('accepts equivalent wholly-owned decimal fractions without requiring NCI', function (string $ownership) {
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [], ownershipFraction: $ownership);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'whole', '2026-01-01', [$member], $this->cta, $this->roundingReserve, 'whole:v1');
    expect($this->ledger->consolidation->get($this->groupBook, $id)['non_controlling_interest']['amount'])->toBe('0.00');
})->with(['1.0', '1.000']);

it('rejects inconsistent acquisition cutoffs unclosed pre-acquisition earnings and changed consideration', function (string $kind) {
    $capital = $this->ledger->catalog->account($this->book, '3000', 'Capital', AccountType::Equity);
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain', AccountType::Income);
    $this->ledger->journals->post(new JournalRequest($this->book, 'capital', '2026-01-01', 'Capital', [new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($capital, Side::Credit, Money::of('100', $this->kes))]));
    $cost = Money::of('120', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'acquire', '2026-01-01', 'Acquire', [new Posting($investment, Side::Debit, $cost), new Posting($this->groupEquity, Side::Credit, $cost)]));
    if ($kind === 'unclosed') {
        $this->ledger->journals->post(new JournalRequest($this->book, 'pre-acquisition-profit', '2026-01-01', 'Profit', [new Posting($this->cash, Side::Debit, Money::of('40', $this->kes)), new Posting($this->income, Side::Credit, Money::of('40', $this->kes))]));
    }
    $acquisition = $this->ledger->reports->snapshot($this->book, $kind === 'late-posting' ? '2026-06-01' : '2026-01-01');
    if ($kind === 'late-posting') {
        $this->ledger->journals->post(new JournalRequest($this->book, 'late-history', '2026-05-01', 'Late pre-acquisition profit', [new Posting($this->cash, Side::Debit, Money::of('40', $this->kes)), new Posting($this->income, Side::Credit, Money::of('40', $this->kes))]));
    }
    if ($kind === 'future-date') {
        $acquisition = new Snapshot($this->book, '2027-01-01', $acquisition->throughSequence);
    } elseif ($kind === 'future-sequence') {
        $acquisition = new Snapshot($this->book, '2026-01-01', '9223372036854775807');
    }
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [$this->cash => $this->groupCash, $this->income => $this->groupIncome]);
    $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'invalid-acquisition', '2026-01-01', [$member],
        $this->cta, $this->roundingReserve, 'acquisition:v2', investments: [new Investment($this->book, $investment, $goodwill, $bargain, $acquisition, $kind === 'changed-cost' ? Money::of('121', $this->kes) : $cost)], retainedEarningsAccountId: $this->retained);
})->with(['unclosed', 'late-posting', 'future-date', 'future-sequence', 'changed-cost'])->throws(LedgerException::class);

it('eliminates acquisition equity even when its original account has since been cleared', function () {
    $capital = $this->ledger->catalog->account($this->book, '3000', 'Capital', AccountType::Equity);
    $retained = $this->ledger->catalog->account($this->book, '3100', 'Retained earnings', AccountType::Equity);
    $investment = $this->ledger->catalog->account($this->groupBook, '1200', 'Investment', AccountType::Asset);
    $goodwill = $this->ledger->catalog->account($this->groupBook, '1210', 'Goodwill', AccountType::Asset);
    $bargain = $this->ledger->catalog->account($this->groupBook, '4200', 'Bargain', AccountType::Income);
    $this->ledger->journals->post(new JournalRequest($this->book, 'capital', '2026-01-01', 'Capital', [new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($capital, Side::Credit, Money::of('100', $this->kes))]));
    $acquisition = $this->ledger->reports->snapshot($this->book, '2026-01-01');
    $cost = Money::of('120', $this->kes);
    $this->ledger->journals->post(new JournalRequest($this->groupBook, 'acquire', '2026-01-01', 'Acquire', [new Posting($investment, Side::Debit, $cost), new Posting($this->groupEquity, Side::Credit, $cost)]));
    $this->ledger->journals->post(new JournalRequest($this->book, 'equity-reclassification', '2026-02-01', 'Reclassify equity', [new Posting($capital, Side::Debit, Money::of('100', $this->kes)), new Posting($retained, Side::Credit, Money::of('100', $this->kes))]));
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), [$this->cash => $this->groupCash, $capital => $this->retained, $retained => $this->retained]);
    $id = $this->ledger->consolidation->run($this->ledger->reports->snapshot($this->groupBook, '2026-12-31'), 'equity-rollforward', '2026-07-01', [$member], $this->cta, $this->roundingReserve, 'acquisition:v2',
        investments: [new Investment($this->book, $investment, $goodwill, $bargain, $acquisition, $cost)], retainedEarningsAccountId: $this->retained);
    $report = $this->ledger->consolidation->get($this->groupBook, $id);
    $balances = collect($report['trial_balance'])->keyBy('account_id');
    expect($report['goodwill'][$this->book]['amount'])->toBe('20.00');
    expect($balances[$this->retained]['closing']['amount'])->toBe('0.00');
    expect($balances[$this->groupEquity]['closing']['amount'])->toBe('-120.00');
    expect($report['translation_adjustment']['amount'])->toBe('0.00');
    expect($report['balance_sheet']['difference']['amount'])->toBe('0.00');
});

it('replays committed legacy aggregation identities without rewriting their saved reports', function () {
    $target = $this->ledger->reports->snapshot($this->groupBook, '2026-12-31');
    $member = new Member($this->ledger->reports->snapshot($this->book, '2026-12-31'), []);
    $legacy = array_diff_key($member->jsonSerialize(), ['ownership_fraction' => true]);
    $id = (string) Str::uuid7();
    $this->storage->run(Permission::Consolidate, fn () => $this->storage->insert('ledger_consolidation_runs', [
        'id' => $id, 'book_id' => $this->groupBook, 'idempotency_key' => 'legacy',
        'fingerprint' => Canonical::hash([$target, '2026-01-01', [$legacy], $this->cta, $this->roundingReserve, 'legacy:v1']),
        'effective_on' => '2026-12-31', 'inputs' => Canonical::json(['members' => [$legacy], 'target' => $target]), 'result' => Canonical::json(['legacy' => true]),
    ]), $this->groupBook);
    expect($this->ledger->consolidation->run($target, 'legacy', '2026-01-01', [$member], $this->cta, $this->roundingReserve, 'legacy:v1'))->toBe($id);
    expect($this->ledger->consolidation->get($this->groupBook, $id))->toBe(['legacy' => true]);
});
