<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Authorizer;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Access\Scope;
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Ledger;
use NoriaLabs\Ledger\LedgerServiceProvider;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Session;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

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
        $this->app->bind(Authorizer::class, fn () => new class implements Authorizer
        {
            public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
            {
                return true;
            }
        });
        $this->scope = new Scope((string) Str::uuid(), 'accountant-1', 'practice-1');
        $this->ledger = $this->app->make(Ledger::class)->for($this->scope);
        $this->storage = new ScopedDatabase($this->app['db']->connection(), $this->scope, $this->app->make(Authorizer::class));
        $this->kes = new Currency('KES', 2);
        $this->entity = $this->ledger->catalog->entity('Reference School');
        $this->book = $this->ledger->catalog->book($this->entity, 'Statutory', $this->kes);
        $this->period = $this->ledger->periods->open($this->book, '2026', '2026-01-01', '2026-12-31');
        $this->cash = $this->ledger->catalog->account($this->book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
        $this->income = $this->ledger->catalog->account($this->book, '4000', 'Income', AccountType::Income, cashFlow: 'operating');
    }
}
