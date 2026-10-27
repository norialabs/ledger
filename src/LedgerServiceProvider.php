<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use NoriaLabs\Ledger\Access\Authorizer;
use NoriaLabs\Ledger\Access\DenyAccess;
use NoriaLabs\Ledger\Accounting\PostingPolicy;
use NoriaLabs\Ledger\Support\Assert;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(Authorizer::class, DenyAccess::class);
        $this->app->bind(Ledger::class, function (Application $app): Ledger {
            $policies = [];

            foreach ($app->tagged(PostingPolicy::class) as $policy) {
                Assert::that($policy instanceof PostingPolicy, 'A tagged posting policy must implement PostingPolicy.');
                if ($policy instanceof PostingPolicy) {
                    $policies[] = $policy;
                }
            }

            return new Ledger($app->make(DatabaseManager::class)->connection(), $app->make(Authorizer::class), $policies);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
