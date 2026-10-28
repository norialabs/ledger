<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Access\ScopeResolver;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Configuration\StarterChart;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Facades\Ledger;
use Noria\Ledger\LedgerContext;
use Noria\Ledger\Models;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Scheduling\Recurrence;
use Noria\Ledger\Session;

it('merges publishable defaults and resolves the ledger through its facade and scope resolver', function () {
    expect(config('ledger.console_actor'))->toBe('ledger:console')
        ->and(config('ledger.defaults.currency'))->toBe('KES')
        ->and(Ledger::for($this->scope)->catalog->findBook($this->book)->id)->toBe($this->book)
        ->and(fn () => Ledger::current())->toThrow(LedgerException::class, 'scope_resolver');

    $scope = $this->scope;
    $this->application()->instance(ScopeResolver::class, new class($scope) implements ScopeResolver
    {
        public function __construct(private Scope $scope) {}

        public function resolve(): Scope
        {
            return $this->scope;
        }
    });
    Ledger::clearResolvedInstances();
    expect(Ledger::current()->catalog->findBook($this->book)->name)->toBe('Statutory');
});

it('installs an entity, a book, monthly periods and the starter chart from artisan', function () {
    $tenant = (string) Str::uuid7();
    $this->command('ledger:install', ['--tenant' => $tenant, '--entity' => 'Acme Builders', '--year' => '2026', '--start' => '07-01'])->assertExitCode(0);
    $ledger = Ledger::for(new Scope($tenant, 'ledger:console'));
    $book = $ledger->catalog->books()[0];
    $periods = $ledger->periods->list($book->id);
    $configuration = $ledger->catalog->accountByCode($book->id, '1000');

    expect($ledger->catalog->entities()[0]->name)->toBe('Acme Builders')
        ->and($book->currencyCode)->toBe('KES')
        ->and($periods)->toHaveCount(12)
        ->and($periods[0]->startsOn)->toBe('2026-07-01')
        ->and($periods[11]->endsOn)->toBe('2027-06-30')
        ->and($configuration->cashFlow)->toBe('cash')
        ->and(count($ledger->catalog->accounts($book->id)))->toBe(count(StarterChart::pack()->accounts));
});

it('posts a sale and receipt with starter-chart roles and renders its configured statements', function () {
    $book = $this->ledger->catalog->book($this->entity, 'Starter', $this->kes);
    $this->ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $configuration = $this->ledger->configurations->install($book, StarterChart::pack());
    $role = fn (string $name) => $this->ledger->configurations->account($book, $configuration, $name);
    $party = $this->ledger->catalog->party($this->entity, 'C-1', 'Customer');
    $amount = Money::of('1160', $this->kes);
    $this->ledger->journals->post(new JournalRequest($book, 'sale', '2026-01-10', 'Sale', [
        new Posting($role('receivable'), Side::Debit, $amount, partyId: $party),
        new Posting($role('revenue'), Side::Credit, Money::of('1000', $this->kes)),
        new Posting($role('tax.output'), Side::Credit, Money::of('160', $this->kes)),
    ]));
    $this->ledger->journals->post(new JournalRequest($book, 'receipt', '2026-01-20', 'Receipt', [
        new Posting($role('cash'), Side::Debit, $amount), new Posting($role('receivable'), Side::Credit, $amount, partyId: $party),
    ]));
    $snapshot = $this->ledger->reports->snapshot($book, '2026-01-31');
    $statement = $this->ledger->reports->statement($this->ledger->configurations->statement($book, $configuration, 'Income statement'),
        [new StatementColumn('jan', 'January 2026', $snapshot, '2026-01-01')]);

    $profit = data_get($statement, 'rows.profit.values.jan');
    expect($profit instanceof Money ? $profit->decimal() : $profit)->toBe('1000.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
});

it('posts due recurring work from artisan and reports the closing checklist with a failing exit code', function () {
    $rent = $this->ledger->catalog->account($this->book, '5200', 'Rent', AccountType::Expense);
    $amount = Money::of('100', $this->kes);
    $rentSchedule = $this->ledger->schedules->recurring($this->book, 'rent', 'Rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-31', occurrences: 2));
    $tenant = $this->scope->tenantId;
    $this->command('ledger:checklist', ['--tenant' => $tenant, '--book' => $this->book, '--from' => '2026-01-01', '--to' => '2026-12-31'])->assertExitCode(1);
    $this->command('ledger:post-due', ['--tenant' => $tenant, '--book' => [$this->book], '--date' => '2026-02-28'])->assertExitCode(0);

    expect($this->ledger->journals->search($this->book, sourceType: 'recurring:'.$rentSchedule))->toHaveCount(2)
        ->and($this->ledger->schedules->dueRecurring($this->book, '2026-12-31'))->toBe([]);
    $this->command('ledger:checklist', ['--tenant' => $tenant, '--book' => $this->book, '--from' => '2026-01-01', '--to' => '2026-12-31'])->assertExitCode(0);
});

function ledgerModelsJournal(Session $ledger, string $book, string $cash, string $income, Currency $currency): string
{
    $amount = Money::of('125.25', $currency);

    return $ledger->journals->post(new JournalRequest($book, 'receipt', '2026-01-05', 'Receipt', [
        new Posting($cash, Side::Debit, $amount), new Posting($income, Side::Credit, $amount),
    ]))->id;
}

it('reads ledger tables through Eloquent models only inside a ledger context, isolated by tenant', function () {
    $journal = ledgerModelsJournal($this->ledger, $this->book, $this->cash, $this->income, $this->kes);
    $other = Ledger::for(new Scope((string) Str::uuid7(), 'other'));
    $other->catalog->entity('Other tenant');
    $context = app(LedgerContext::class);

    expect(Models\Account::query()->count())->toBe(0);
    $context->run($this->scope, function () use ($journal): void {
        $loaded = Models\Journal::query()->with('lines.account', 'book.entity')->findOrFail($journal);
        expect(Models\Entity::query()->pluck('name')->all())->toBe(['Reference School'])
            ->and($loaded->lines->map(fn ($posting) => [$posting->line_no, $posting->account?->code, $posting->money('amount_minor')->decimal()])->all())
            ->toBe([[1, '1000', '125.25'], [2, '4000', '125.25']])
            ->and($loaded->book?->entity?->name)->toBe('Reference School')
            ->and($loaded->effective_on->toDateString())->toBe('2026-01-05')
            ->and(Models\Book::query()->firstOrFail()->accounts->pluck('code')->all())->toBe(['1000', '4000']);
    });
    expect($context->scope())->toBeNull()->and(Models\Journal::query()->count())->toBe(0);
});

it('keeps models read-only unless an application subclass opts in, while the database still guards history', function () {
    $journal = ledgerModelsJournal($this->ledger, $this->book, $this->cash, $this->income, $this->kes);
    app(LedgerContext::class)->run($this->scope, function () use ($journal): void {
        $account = Models\Account::query()->where('code', '1000')->firstOrFail();
        expect(fn () => $account->update(['name' => 'Renamed']))->toThrow(LedgerException::class, 'read-only')
            ->and(fn () => Models\Journal::query()->findOrFail($journal)->delete())->toThrow(LedgerException::class, 'read-only')
            ->and(fn () => Models\Party::query()->create(['entity_id' => $this->entity, 'reference' => 'X', 'name' => 'X']))->toThrow(LedgerException::class, 'read-only');

        $writable = new class extends Models\Account
        {
            protected static bool $writable = true;
        };
        $editable = $writable::query()->where('code', '1000')->firstOrFail();
        $editable->update(['name' => 'Main bank']);
        expect(Models\Account::query()->where('code', '1000')->value('name'))->toBe('Main bank')
            ->and(fn () => $editable->update(['type' => 'expense']))->toThrow(QueryException::class);
    });
});

it('resolves relationships through application model overrides configured in ledger.models', function () {
    $journal = ledgerModelsJournal($this->ledger, $this->book, $this->cash, $this->income, $this->kes);
    $custom = new class extends Models\Account {};
    config(['ledger.models.account' => $custom::class]);

    app(LedgerContext::class)->run($this->scope, function () use ($journal, $custom): void {
        $posting = Models\Posting::query()->where('journal_id', $journal)->orderBy('line_no')->firstOrFail();
        expect($posting->account)->toBeInstanceOf($custom::class);
    });
    config(['ledger.models.account' => Models\Party::class]);
    expect(fn () => app(LedgerContext::class)->run($this->scope, fn () => Models\Posting::query()->firstOrFail()->account))->toThrow(LedgerException::class, 'must extend');
});

it('maps every model and relationship onto existing ledger tables and key columns', function () {
    $columns = [];
    foreach ($this->connection()->select("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE 'ledger\\_%'") as $column) {
        $columns[text(row($column)->table_name)][] = text(row($column)->column_name);
    }
    $relations = 0;
    foreach (glob(dirname(__DIR__, 2).'/src/Models/*.php') ?: [] as $file) {
        $class = 'Noria\\Ledger\\Models\\'.basename($file, '.php');
        if ($class === Models\Model::class || ! is_a($class, Models\Model::class, true)) {
            continue;
        }
        $model = new $class;
        expect($columns)->toHaveKey($model->getTable());
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($method->class !== $class || ! $type instanceof ReflectionNamedType || ! is_a($type->getName(), Relation::class, true)) {
                continue;
            }
            $relation = $method->invoke($model);
            $related = $relation instanceof Relation ? $relation->getRelated()->getTable() : '';
            [$childTable, $childKey, $parentTable, $parentKey] = match (true) {
                $relation instanceof BelongsTo => [$model->getTable(), $relation->getForeignKeyName(), $related, $relation->getOwnerKeyName()],
                $relation instanceof HasOneOrMany => [$related, $relation->getForeignKeyName(), $model->getTable(), $relation->getLocalKeyName()],
                default => throw new UnexpectedValueException($class.'::'.$method->name.' is not a supported relationship.'),
            };
            expect($columns[$childTable] ?? [])->toContain($childKey)->and($columns[$parentTable] ?? [])->toContain($parentKey);
            $relations++;
        }
    }
    expect($relations)->toBe(213);
});

it('sets the ledger scope for a request through the ledger.scope middleware and clears it afterwards', function () {
    ledgerModelsJournal($this->ledger, $this->book, $this->cash, $this->income, $this->kes);
    $scope = $this->scope;
    $this->application()->instance(ScopeResolver::class, new class($scope) implements ScopeResolver
    {
        public function __construct(private Scope $scope) {}

        public function resolve(): Scope
        {
            return $this->scope;
        }
    });
    Route::middleware('ledger.scope')->get('/ledger-test', fn () => ['accounts' => Models\Account::query()->count(), 'current' => Ledger::current()->catalog->findBook($this->book)->name]);

    $this->getJson('/ledger-test')->assertOk()->assertExactJson(['accounts' => 2, 'current' => 'Statutory']);
    expect(app(LedgerContext::class)->scope())->toBeNull();
});
