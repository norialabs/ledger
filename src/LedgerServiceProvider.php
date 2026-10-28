<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\DenyAccess;
use Noria\Ledger\Access\ScopeResolver;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Console\ChecklistCommand;
use Noria\Ledger\Console\InstallCommand;
use Noria\Ledger\Console\PostDueCommand;
use Noria\Ledger\Http\Middleware\SetLedgerScope;
use Noria\Ledger\Support\Assert;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledger.php', 'ledger');
        $this->app->singleton(LedgerContext::class);
        if ($this->app->make('config')->get('ledger.scope_resolver') !== null) {
            $this->app->bindIf(ScopeResolver::class, fn (Application $app): ScopeResolver => $this->configured($app, 'ledger.scope_resolver', ScopeResolver::class)
                ?? throw new \LogicException('Missing ledger scope resolver.'));
        }
        $this->app->bindIf(Authorizer::class, fn (Application $app): Authorizer => $this->configured($app, 'ledger.authorizer', Authorizer::class) ?? new DenyAccess);
        $this->app->bind(Ledger::class, function (Application $app): Ledger {
            $policies = [];
            foreach ([...$app->tagged(PostingPolicy::class), ...$this->configuredList($app, 'ledger.posting_policies')] as $policy) {
                Assert::that($policy instanceof PostingPolicy, 'A posting policy must implement PostingPolicy.');
                if ($policy instanceof PostingPolicy) {
                    $policies[] = $policy;
                }
            }
            $treatments = [];
            foreach ([...$app->tagged(PostingTreatment::class), ...$this->configuredList($app, 'ledger.posting_treatments')] as $treatment) {
                Assert::that($treatment instanceof PostingTreatment, 'A posting treatment must implement PostingTreatment.');
                if ($treatment instanceof PostingTreatment) {
                    $treatments[] = $treatment;
                }
            }
            $resolver = $app->bound(ScopeResolver::class) ? $app->make(ScopeResolver::class) : null;

            return new Ledger($app->make(DatabaseManager::class)->connection(), $app->make(Authorizer::class), $policies, $app, $treatments,
                $resolver instanceof ScopeResolver ? $resolver : null, $app->make(LedgerContext::class));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishes([__DIR__.'/../config/ledger.php' => $this->app->configPath('ledger.php')], 'ledger-config');
        $this->app->make(Router::class)->aliasMiddleware('ledger.scope', SetLedgerScope::class);
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, PostDueCommand::class, ChecklistCommand::class]);
        }
    }

    /** @template T of object
     * @param  class-string<T>  $contract
     * @return T|null
     */
    private function configured(Application $app, string $key, string $contract): ?object
    {
        $class = $app->make('config')->get($key);
        if ($class === null) {
            return null;
        }
        Assert::that(is_string($class) && is_a($class, $contract, true), $key.' must name a class implementing '.$contract.'.');
        $instance = is_string($class) ? $app->make($class) : null;

        return $instance instanceof $contract ? $instance : null;
    }

    /** @return list<object> */
    private function configuredList(Application $app, string $key): array
    {
        $classes = $app->make('config')->get($key, []);
        Assert::that(is_array($classes), $key.' must be a list of class names.');
        $instances = [];
        foreach (is_array($classes) ? $classes : [] as $class) {
            Assert::that(is_string($class) && class_exists($class), $key.' contains an unknown class.');
            $instance = is_string($class) ? $app->make($class) : null;
            if (is_object($instance)) {
                $instances[] = $instance;
            }
        }

        return $instances;
    }
}
