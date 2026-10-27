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
use Noria\Ledger\Reporting\StatementDefinition;
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
        test()->fail('Conflicting chart classifications must fail.');
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
        test()->fail('Country-specific packs must match their legal entity.');
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
        test()->fail('Published configuration evidence must be immutable.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('append-only');
    }
    $this->storage->run(Permission::Configure, function () use ($id, $otherIncome): void {
        $row = $this->storage->find('ledger_configurations', $id, $this->book);
        $values = get_object_vars($row);
        $values['id'] = (string) Str::uuid7();
        $values['book_id'] = $this->book;
        $values['name'] = 'tampered';
        $definition = json_decode($row->definition_json, true, 512, JSON_THROW_ON_ERROR);
        $definition['name'] = 'tampered';
        $values['definition_json'] = json_encode($definition, JSON_THROW_ON_ERROR);
        $values['definition_hash'] = hash('sha256', $values['definition_json']);
        $values['roles'] = json_encode(['income' => $otherIncome], JSON_THROW_ON_ERROR);
        $this->storage->insert('ledger_configurations', $values);
    }, $this->book);
})->throws(QueryException::class, 'matching posting accounts');

it('resolves host service extensions per scope and uses the replacement in document posting', function () {
    $this->app->bind(Journals::class, function ($app, array $parameters) {
        return new readonly class($parameters['db'], $parameters['policies']) extends Journals
        {
            public function post(JournalRequest $request): Journal
            {
                return parent::post(new JournalRequest($request->bookId, $request->key, $request->effectiveOn, $request->description,
                    $request->postings, [...$request->metadata, 'host_extension' => 'example:v1'], $request->reversesId, $request->closing, $request->source));
            }
        };
    });
    $first = $this->app->make(Ledger::class)->for($this->scope);
    $secondScope = new Scope((string) Str::uuid7(), 'second-actor');
    $second = $this->app->make(Ledger::class)->for($secondScope);
    $secondEntity = $second->catalog->entity('Second tenant');
    $secondBook = $second->catalog->book($secondEntity, 'Book', $this->kes);
    $amount = Money::of('10', $this->kes);
    $receivables = $first->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $party = $first->catalog->party($this->entity, 'payer', 'Payer');
    $journal = $first->documents->payment(new PaymentRequest($this->book, 'host-post', '2026-01-01', new Source('example', 'extended-payment'),
        $this->cash, $receivables, $party, $amount));
    $this->storage->run(Permission::Read, function () use ($journal): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        expect(json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR)['host_extension'])->toBe('example:v1');
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
            $amount = Money::of($request->inputs['amount'], $this->currency);

            return new JournalRequest($request->bookId, $request->key, $request->effectiveOn, 'Example receipt', [
                new Posting($accounts['bank'], Side::Debit, $amount), new Posting($accounts['income'], Side::Credit, $amount),
            ], source: $request->source);
        }
    };
    $this->app->instance('example.treatment', $treatment);
    $this->app->tag('example.treatment', PostingTreatment::class);
    $session = $this->app->make(Ledger::class)->for($this->scope);
    $request = new TreatmentRequest($this->book, 'receipt', '2026-01-01', new Source('example', '42'), 'example.receipt', 'v1', $config, ['amount' => '123.45']);
    $journal = $session->treatments->post($request);
    $this->ledger->periods->close($this->book, $this->period, 'Close the period');
    expect($this->ledger->treatments->post($request)->id)->toBe($journal->id);
    $this->storage->run(Permission::Read, function () use ($journal, $config): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        $evidence = json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR)['treatment'];
        expect($evidence['version'])->toBe('v1');
        expect($evidence['request']['configuration_id'])->toBe($config);
        expect($evidence['request']['inputs'])->toBe(['amount' => '123.45']);
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
    expect($events[0]->table_name)->toBe('ledger_journals');
    expect(json_decode($events[0]->after_value, true, 512, JSON_THROW_ON_ERROR)['id'])->toBe($journal->id);
    expect($events[0]->actor_id)->toBe($this->scope->actorId);
    $this->ledger->journals->post($request);
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    try {
        $this->ledger->journals->post(new JournalRequest($this->book, 'bad-event', '2026-01-02', 'Unbalanced', [
            new Posting($this->cash, Side::Debit, $amount), new Posting($this->income, Side::Credit, Money::of('24', $this->kes)),
        ]));
        test()->fail('Unbalanced posting must fail.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('balance');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff);
    $this->ledger->journals->reverse($this->book, $journal->id, 'event-reversal', '2026-01-02', 'Correct receipt');
    expect($this->ledger->events->page($this->book, $before, throughSequence: $cutoff))->toEqual($events);
    expect($this->ledger->events->page($this->book, $cutoff))->toHaveCount(1);
});
