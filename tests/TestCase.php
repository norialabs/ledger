<?php

declare(strict_types=1);

namespace Noria\Ledger\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Associates\AssociateRequest;
use Noria\Ledger\Ledger;
use Noria\Ledger\LedgerServiceProvider;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Session;
use Noria\Ledger\Storage\ScopedDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected Session $ledger;

    protected Scope $scope;

    protected Currency $kes;

    protected string $entity;

    protected string $book;

    protected string $period;

    protected string $cash;

    protected string $income;

    protected ScopedDatabase $storage;

    protected string $ar;

    protected string $assetCost;

    /** @var non-empty-list<string> */
    protected array $assetDates;

    protected string $assetDep;

    protected string $assetId;

    protected string $assetImp;

    protected AssetRequest $assetRequest;

    protected string $associate;

    protected AssociateRequest $associateRequest;

    protected string $capital;

    protected string $clearing;

    protected ExchangeRate $closingRate;

    protected string $cogs;

    protected Connection $committedConnection;

    protected Scope $committedScope;

    protected string $cta;

    protected Connection $deliveryConnection;

    protected ScopedDatabase $deliveryStorage;

    protected string $depExpense;

    protected string $disposalGain;

    protected string $disposalLoss;

    protected string $dueFrom;

    protected string $dueTo;

    protected string $foreignBank;

    protected string $funding;

    protected string $gain;

    protected string $groupBook;

    protected string $groupCash;

    protected string $groupEquity;

    protected string $groupIncome;

    protected string $impExpense;

    protected ExchangeRate $initialRate;

    protected string $investee;

    protected string $investment;

    protected string $investmentLiability;

    protected string $investmentLoss;

    protected Journal $invoice;

    protected string $loss;

    protected string $mpesa;

    protected string $nci;

    protected string $otherBook;

    protected string $otherCash;

    protected string $otherStock;

    protected string $party;

    protected string $raceBook;

    protected string $raceCash;

    protected ScopedDatabase $raceDb;

    protected string $raceEntity;

    protected string $raceIncome;

    protected Session $raceLedger;

    protected Scope $raceScope;

    protected string $receivable;

    protected string $retained;

    protected string $roundingReserve;

    protected string $shareOci;

    protected string $shareProfit;

    protected string $stock;

    protected Currency $usd;

    protected string $usdBank;

    protected function getPackageProviders($app): array
    {
        return [LedgerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'ledger_test');
        $app['config']->set('database.connections.ledger_test', [
            'driver' => 'pgsql',
            'host' => getenv('LEDGER_TEST_HOST') ?: '/tmp',
            'port' => getenv('LEDGER_TEST_PORT') ?: '5432',
            'database' => 'noria_ledger_test',
            'username' => 'noria_ledger_test',
            'password' => 'ledger_test_only',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'timezone' => 'UTC',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->bind(Authorizer::class, fn () => new class implements Authorizer
        {
            public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
            {
                return true;
            }
        });
        $this->scope = new Scope((string) Str::uuid7(), 'accountant-1', 'practice-1');
        $this->ledger = $this->application()->make(Ledger::class)->for($this->scope);
        $this->storage = new ScopedDatabase($this->connection(), $this->scope, $this->application()->make(Authorizer::class));
        $this->kes = new Currency('KES', 2);
        $this->entity = $this->ledger->catalog->entity('Reference School');
        $this->book = $this->ledger->catalog->book($this->entity, 'Statutory', $this->kes);
        $this->period = $this->ledger->periods->open($this->book, '2026', '2026-01-01', '2026-12-31');
        $this->cash = $this->ledger->catalog->account($this->book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
        $this->income = $this->ledger->catalog->account($this->book, '4000', 'Income', AccountType::Income, cashFlow: 'operating');
    }

    protected function application(): Application
    {
        if ($this->app === null) {
            throw new RuntimeException('The test application is not booted.');
        }

        return $this->app;
    }

    protected function connection(): Connection
    {
        $connection = $this->application()->make(DatabaseManager::class)->connection();
        if (! $connection instanceof Connection) {
            throw new RuntimeException('The test connection is not a database connection.');
        }

        return $connection;
    }

    /** @param array<string, mixed> $parameters */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException('Console output mocking is disabled.');
        }

        return $pending;
    }
}
