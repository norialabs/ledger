<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\DenyAccess;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Support\Assert;

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

            $treatments = [];
            foreach ($app->tagged(PostingTreatment::class) as $treatment) {
                Assert::that($treatment instanceof PostingTreatment, 'A tagged posting treatment must implement PostingTreatment.');
                if ($treatment instanceof PostingTreatment) {
                    $treatments[] = $treatment;
                }
            }

            return new Ledger($app->make(DatabaseManager::class)->connection(), $app->make(Authorizer::class), $policies, $app, $treatments);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
