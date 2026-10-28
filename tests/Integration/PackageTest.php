<?php

declare(strict_types=1);

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
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Scheduling\Recurrence;

it('merges publishable defaults and resolves the ledger through its facade and scope resolver', function () {
    expect(config('ledger.console_actor'))->toBe('ledger:console')
        ->and(config('ledger.defaults.currency'))->toBe('KES')
        ->and(Ledger::for($this->scope)->catalog->findBook($this->book)->id)->toBe($this->book)
        ->and(fn () => Ledger::current())->toThrow(LedgerException::class, 'scope_resolver');

    $scope = $this->scope;
    $this->app->instance(ScopeResolver::class, new class($scope) implements ScopeResolver
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
    $this->artisan('ledger:install', ['--tenant' => $tenant, '--entity' => 'Acme Builders', '--year' => '2026', '--start' => '07-01'])->assertExitCode(0);
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

    expect($statement['rows']['profit']['values']['jan']->decimal())->toBe('1000.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
});

it('posts due recurring work from artisan and reports the closing checklist with a failing exit code', function () {
    $rent = $this->ledger->catalog->account($this->book, '5200', 'Rent', AccountType::Expense);
    $amount = Money::of('100', $this->kes);
    $rentSchedule = $this->ledger->schedules->recurring($this->book, 'rent', 'Rent', [new Posting($rent, Side::Debit, $amount), new Posting($this->cash, Side::Credit, $amount)],
        Recurrence::monthly('2026-01-31', occurrences: 2));
    $tenant = $this->scope->tenantId;
    $this->artisan('ledger:checklist', ['--tenant' => $tenant, '--book' => $this->book, '--from' => '2026-01-01', '--to' => '2026-12-31'])->assertExitCode(1);
    $this->artisan('ledger:post-due', ['--tenant' => $tenant, '--book' => [$this->book], '--date' => '2026-02-28'])->assertExitCode(0);

    expect($this->ledger->journals->search($this->book, sourceType: 'recurring:'.$rentSchedule))->toHaveCount(2)
        ->and($this->ledger->schedules->dueRecurring($this->book, '2026-12-31'))->toBe([]);
    $this->artisan('ledger:checklist', ['--tenant' => $tenant, '--book' => $this->book, '--from' => '2026-01-01', '--to' => '2026-12-31'])->assertExitCode(0);
});
