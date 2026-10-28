<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\PaymentRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Journals;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMapping;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Tax\TaxRule;

it('installs a versioned chart in dependency order and preserves exact tax and statement definitions', function () {
    $statement = new StatementDefinition('Profit', 'v1', ['income' => ['label' => 'Income', 'accounts' => ['4000'], 'sign' => 'credit', 'include_closing' => false]]);
    $pack = new Pack('school.example', '2026.1', [
        ['code' => '1100', 'name' => 'Student receivables', 'type' => 'asset', 'parent' => '110', 'control' => true],
        ['code' => '110', 'name' => 'Receivables group', 'type' => 'asset', 'group' => true],
    ], ['receivables' => '1100', 'bank' => '1000', 'tuition' => '4000'],
        [new TaxRule('EXAMPLE', '0.125', '2026-01-01', '2026-12-31', 'example-policy:v1')], [$statement], ['recognition' => 'immediate'], 'KE', '2026-01-01', '2026-12-31');
    $id = $this->ledger->configurations->install($this->book, $pack);
    $cutoff = $this->ledger->events->cutoff($this->book);
    expect($this->ledger->configurations->install($this->book, $pack))->toBe($id);
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    expect($this->ledger->configurations->account($this->book, $id, 'bank'))->toBe($this->cash);
    $evidence = $this->ledger->configurations->get($this->book, $id, '2026-06-30');
    expect(hash('sha256', $evidence['definition_json']))->toBe($evidence['definition_hash']);
    expect($evidence['definition']['settings'])->toBe(['recognition' => 'immediate']);
    expect($this->ledger->configurations->taxRules($this->book, $id, '2026-06-30')[0]->rate)->toBe('0.125');
    expect($this->ledger->configurations->statement($this->book, $id, 'Profit')->jsonSerialize())->toBe($statement->jsonSerialize());
    $this->ledger->configurations->get($this->book, $id, '2027-01-01');
})->throws(LedgerException::class, 'not effective');

it('retains earlier account-role mappings when a new configuration version changes policy', function () {
    $otherIncome = $this->ledger->catalog->account($this->book, '4100', 'Deferred policy income', AccountType::Income);
    $first = $this->ledger->configurations->install($this->book, new Pack('recognition', 'v1', roles: ['income' => '4000']));
    $second = $this->ledger->configurations->install($this->book, new Pack('recognition', 'v2', roles: ['income' => '4100']));
    expect($this->ledger->configurations->account($this->book, $first, 'income'))->toBe($this->income);
    expect($this->ledger->configurations->account($this->book, $second, 'income'))->toBe($otherIncome);
    $this->ledger->configurations->install($this->book, new Pack('recognition', 'v1', roles: ['income' => '4100']));
})->throws(LedgerException::class, 'different content');

it('rolls back newly created chart accounts when a later configuration account conflicts', function () {
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->configurations->install($this->book, new Pack('bad-chart', 'v1', [
            ['code' => '1200', 'name' => 'Temporary account', 'type' => 'asset'],
            ['code' => '4000', 'name' => 'Conflicting type', 'type' => 'liability'],
        ]));
        $this->fail('Conflicting chart classifications must fail.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('conflicts with configuration');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->storage->run(Permission::Read, function (): void {
        expect($this->storage->table('ledger_accounts')->where('book_id', $this->book)->where('code', '1200')->exists())->toBeFalse();
    }, $this->book);
});

it('rejects cyclic chart templates and jurisdiction mismatches atomically', function () {
    try {
        $this->ledger->configurations->install($this->book, new Pack('wrong-country', 'v1', country: 'UG'));
        $this->fail('Country-specific packs must match their legal entity.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('country differs');
    }
    $this->ledger->configurations->install($this->book, new Pack('cycle', 'v1', [
        ['code' => '1200', 'name' => 'A', 'type' => 'asset', 'parent' => '1201', 'group' => true],
        ['code' => '1201', 'name' => 'B', 'type' => 'asset', 'parent' => '1200', 'group' => true],
    ]));
})->throws(LedgerException::class, 'cycle');

it('protects configuration evidence from direct edits and cross-book role substitutions', function () {
    $id = $this->ledger->configurations->install($this->book, new Pack('evidence', 'v1', roles: ['income' => '4000']));
    $otherBook = $this->ledger->catalog->book($this->entity, 'Other treatment', $this->kes);
    $otherIncome = $this->ledger->catalog->account($otherBook, '4000', 'Other income', AccountType::Income);
    try {
        $this->storage->run(Permission::Configure, fn () => $this->storage->table('ledger_configurations')->where('id', $id)->update(['version' => 'rewritten']), $this->book);
        $this->fail('Published configuration evidence must be immutable.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('append-only');
    }
    $this->storage->run(Permission::Configure, function () use ($id, $otherIncome): void {
        $row = $this->storage->find('ledger_configurations', $id, $this->book);
        $values = array_filter(get_object_vars($row), is_string(...), ARRAY_FILTER_USE_KEY);
        $values['id'] = (string) Str::uuid7();
        $values['book_id'] = $this->book;
        $values['name'] = 'tampered';
        $definition = items(json_decode(text($row->definition_json), true, 512, JSON_THROW_ON_ERROR));
        $definition['name'] = 'tampered';
        $values['definition_json'] = json_encode($definition, JSON_THROW_ON_ERROR);
        $values['definition_hash'] = hash('sha256', $values['definition_json']);
        $values['roles'] = json_encode(['income' => $otherIncome], JSON_THROW_ON_ERROR);
        $this->storage->insert('ledger_configurations', $values);
    }, $this->book);
})->throws(QueryException::class, 'matching posting accounts');

it('rejects reporting classifications that do not fit their posting accounts', function (string $case) {
    $this->ledger->catalog->account($this->book, '3000', 'Equity', AccountType::Equity);
    $this->ledger->catalog->account($this->book, '4900', 'Income group', AccountType::Income, group: true);
    $accounts = match ($case) {
        'missing' => ['9999' => ['profit_or_loss' => 'operating']],
        'group' => ['4900' => ['profit_or_loss' => 'operating']],
        'asset_profit' => ['1000' => ['profit_or_loss' => 'operating']],
        'asset_oci' => ['1000' => ['oci' => 'recyclable']],
        'income_recyclable_oci' => ['4000' => ['oci' => 'recyclable']],
        'income_equity' => ['4000' => ['equity_component' => 'retained']],
        'unattributed_equity' => ['3000' => ['oci' => 'non_recyclable']],
        default => throw new RuntimeException('Unknown reporting fixture.'),
    };
    $mapping = new ReportingMapping('Invalid classification', '1', 'Trading', $accounts, true, ['retained' => 'Retained earnings']);
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->ledger->configurations->install($this->book, new Pack('invalid-reporting', '1', [
        ['code' => '1200', 'name' => 'Rolled back', 'type' => 'asset'],
    ], reportingMapping: $mapping)))->toThrow(LedgerException::class);
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->storage->run(Permission::Read, function (): void {
        expect($this->storage->table('ledger_accounts')->where('book_id', $this->book)->where('code', '1200')->exists())->toBeFalse();
    }, $this->book);
})->with(['missing', 'group', 'asset_profit', 'asset_oci', 'income_recyclable_oci', 'income_equity', 'unattributed_equity']);

it('enforces reporting account ownership and classifications on direct SQL configuration inserts', function (string $case) {
    $this->ledger->catalog->account($this->book, '3000', 'Equity', AccountType::Equity);
    $this->ledger->catalog->account($this->book, '4900', 'Income group', AccountType::Income, group: true);
    $otherBook = $this->ledger->catalog->book($this->entity, 'Other reporting book', $this->kes);
    $this->ledger->catalog->account($otherBook, '9999', 'Other book income', AccountType::Income);
    $classification = match ($case) {
        'other_book' => ['9999' => ['profit_or_loss' => 'operating']],
        'group' => ['4900' => ['profit_or_loss' => 'operating']],
        'asset_profit' => ['1000' => ['profit_or_loss' => 'operating']],
        'asset_oci' => ['1000' => ['oci' => 'recyclable']],
        'income_recyclable_oci' => ['4000' => ['oci' => 'recyclable']],
        'income_equity' => ['4000' => ['equity_component' => 'retained']],
        'missing_component' => ['3000' => ['oci' => 'non_recyclable']],
        'unknown_component' => ['3000' => ['equity_component' => 'unknown']],
        'dual_classification' => ['4000' => ['profit_or_loss' => 'operating', 'oci' => 'recyclable']],
        'unknown_category' => ['4000' => ['profit_or_loss' => 'other']],
        'unknown_setting' => ['4000' => ['profit_or_loss' => 'operating', 'arbitrary' => 'value']],
        'invalid_mapping' => null,
        default => throw new RuntimeException('Unknown reporting fixture.'),
    };
    $definition = (new Pack('sql-reporting', '1'))->jsonSerialize();
    $definition['reporting_mapping'] = $case === 'invalid_mapping' ? false : ['name' => 'Performance', 'version' => '1', 'main_business_activity' => 'Trading', 'accounts' => $classification, 'financing_subtotal_required' => true, 'equity_components' => ['retained' => 'Retained earnings']];
    $message = match ($case) {
        'other_book', 'group' => 'Reporting mappings require posting accounts',
        'asset_profit', 'unknown_category' => 'Invalid profit-or-loss account classification',
        'asset_oci', 'income_recyclable_oci' => 'Invalid OCI account classification',
        'income_equity', 'missing_component', 'unknown_component' => 'Invalid reporting equity component',
        'dual_classification', 'unknown_setting' => 'Invalid reporting account classification',
        default => 'Invalid reporting mapping definition',
    };
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->storage->run(Permission::Configure, fn () => $this->storage->insert('ledger_configurations', [
        'book_id' => $this->book, 'name' => 'sql-reporting', 'version' => '1', 'definition_json' => Canonical::json($definition), 'account_ids' => '{}', 'roles' => '{}',
    ]), $this->book))->toThrow(QueryException::class, $message);
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
})->with(['other_book', 'group', 'asset_profit', 'asset_oci', 'income_recyclable_oci', 'income_equity', 'missing_component', 'unknown_component', 'dual_classification', 'unknown_category', 'unknown_setting', 'invalid_mapping']);

final readonly class HostExtensionJournals extends Journals
{
    public function post(JournalRequest $request): Journal
    {
        return parent::post(new JournalRequest($request->bookId, $request->key, $request->effectiveOn, $request->description,
            $request->postings, [...$request->metadata, 'host_extension' => 'example:v1'], $request->reversesId, $request->closing, $request->source));
    }
}

it('resolves host service extensions per scope and uses the replacement in document posting', function () {
    $this->application()->bind(Journals::class, HostExtensionJournals::class);
    $first = $this->application()->make(Ledger::class)->for($this->scope);
    $secondScope = new Scope((string) Str::uuid7(), 'second-actor');
    $second = $this->application()->make(Ledger::class)->for($secondScope);
    $secondEntity = $second->catalog->entity('Second tenant');
    $secondBook = $second->catalog->book($secondEntity, 'Book', $this->kes);
    $amount = Money::of('10', $this->kes);
    $receivables = $first->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $party = $first->catalog->party($this->entity, 'payer', 'Payer');
    $journal = $first->documents->payment(new PaymentRequest($this->book, 'host-post', '2026-01-01', new Source('example', 'extended-payment'),
        $this->cash, $receivables, $party, $amount));
    $this->storage->run(Permission::Read, function () use ($journal): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        expect(data_get(json_decode(text($row->metadata), true, 512, JSON_THROW_ON_ERROR), 'host_extension'))->toBe('example:v1');
        expect($row->actor_id)->toBe($this->scope->actorId);
    }, $this->book);
    expect($second->reports->trialBalance($second->reports->snapshot($secondBook, '2026-01-31')))->toBe([]);
    expect($first->journals)->not->toBe($second->journals);
});

it('posts versioned treatments with pinned configuration and replays without rerunning removed implementations', function () {
    $config = $this->ledger->configurations->install($this->book, new Pack('receipt-policy', 'v1', roles: ['bank' => '1000', 'income' => '4000']));
    $treatment = new class($this->kes) implements PostingTreatment
    {
        public function __construct(private Currency $currency) {}

        public function name(): string
        {
            return 'example.receipt';
        }

        public function version(): string
        {
            return 'v1';
        }

        public function prepare(Scope $scope, TreatmentRequest $request, array $accounts): JournalRequest
        {
            $amount = Money::of(text($request->inputs['amount']), $this->currency);

            return new JournalRequest($request->bookId, $request->key, $request->effectiveOn, 'Example receipt', [
                new Posting($accounts['bank'], Side::Debit, $amount), new Posting($accounts['income'], Side::Credit, $amount),
            ], source: $request->source);
        }
    };
    $this->application()->instance('example.treatment', $treatment);
    $this->application()->tag('example.treatment', PostingTreatment::class);
    $session = $this->application()->make(Ledger::class)->for($this->scope);
    $request = new TreatmentRequest($this->book, 'receipt', '2026-01-01', new Source('example', '42'), 'example.receipt', 'v1', $config, ['amount' => '123.45']);
    $journal = $session->treatments->post($request);
    $this->ledger->periods->close($this->book, $this->period, 'Close the period');
    expect($this->ledger->treatments->post($request)->id)->toBe($journal->id);
    $this->storage->run(Permission::Read, function () use ($journal, $config): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        $evidence = data_get(json_decode(text($row->metadata), true, 512, JSON_THROW_ON_ERROR), 'treatment');
        expect(data_get($evidence, 'version'))->toBe('v1');
        expect(data_get($evidence, 'request.configuration_id'))->toBe($config);
        expect(data_get($evidence, 'request.inputs'))->toBe(['amount' => '123.45']);
    }, $this->book);
    $this->ledger->treatments->post(new TreatmentRequest($this->book, 'receipt', '2026-01-01', new Source('example', '42'), 'example.receipt', 'v1', $config, ['amount' => '999']));
})->throws(LedgerException::class, 'different inputs');

it('publishes a durable book event stream atomically and preserves a stable export cutoff', function () {
    $before = $this->ledger->events->cutoff($this->book);
    $amount = Money::of('25', $this->kes);
    $request = new JournalRequest($this->book, 'event-post', '2026-01-01', 'Receipt', [new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, $amount)]);
    $journal = $this->ledger->journals->post($request);
    $cutoff = $this->ledger->events->cutoff($this->book);
    $events = $this->ledger->events->page($this->book, $before, throughSequence: $cutoff);
    expect($events)->toHaveCount(1);
    expect($events[0]->tableName)->toBe('ledger_journals');
    expect($events[0]->after['id'] ?? null)->toBe($journal->id);
    expect($events[0]->actorId)->toBe($this->scope->actorId);
    $this->ledger->journals->post($request);
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'bad-event', '2026-01-02', 'Unbalanced', [
            new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, Money::of('24', $this->kes)),
        ]));
        $this->fail('Unbalanced posting must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('balance');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    $this->ledger->journals->reverse($this->book, $journal->id, 'event-reversal', '2026-01-02', 'Correct receipt');
    expect($this->ledger->events->page($this->book, $before, throughSequence: $cutoff))->toEqual($events);
    expect($this->ledger->events->page($this->book, $cutoff))->toHaveCount(1);
});

it('reuses accounts that later versions redeclare, adds their required dimensions once and rejects unknown statements', function () {
    $receivables = ['code' => '1100', 'name' => 'Student receivables', 'type' => 'asset', 'control' => true];
    $first = $this->ledger->configurations->install($this->book, new Pack('chart', 'v1', [$receivables], ['receivables' => '1100']));
    $account = $this->ledger->configurations->account($this->book, $first, 'receivables');
    foreach (['v2', 'v3'] as $version) {
        $id = $this->ledger->configurations->install($this->book, new Pack('chart', $version, [[...$receivables, 'dimensions' => ['campus']]], ['receivables' => '1100']));
        expect($this->ledger->configurations->account($this->book, $id, 'receivables'))->toBe($account);
    }
    expect($this->ledger->catalog->findAccount($this->book, $account)->requiredDimensions)->toBe(['campus'])
        ->and(fn () => $this->ledger->configurations->statement($this->book, $first, 'Balance sheet'))->toThrow(LedgerException::class, 'Unknown configuration statement: Balance sheet');
});
