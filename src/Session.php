<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Contracts\Container\Container;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;

final readonly class Session
{
    public Catalog $catalog;

    public Journals $journals;

    public Periods $periods;

    public Reports $reports;

    public Settlements $settlements;

    public Documents $documents;

    public Schedules $schedules;

    public YearEnd $yearEnd;

    public Banking $banking;

    public JournalBatches $batches;

    public Revaluations $revaluations;

    public Inventory $inventory;

    public Assets $assets;

    public Associates $associates;

    public Finance $finance;

    public Taxes $taxes;

    public Statutory $statutory;

    public Consolidation $consolidation;

    public MonetaryAccounts $monetaryAccounts;

    public Configurations $configurations;

    public Treatments $treatments;

    public Events $events;

    public Rates $rates;

    public Budgets $budgets;

    public Drafts $drafts;

    /** @param list<PostingPolicy> $postingPolicies
     * @param  list<PostingTreatment>  $postingTreatments
     */
    public function __construct(ScopedDatabase $db, array $postingPolicies = [], ?Container $container = null, array $postingTreatments = [])
    {
        $this->catalog = self::resolve(Catalog::class, ['db' => $db], $container);
        $this->rates = self::resolve(Rates::class, ['db' => $db, 'catalog' => $this->catalog], $container);
        $this->configurations = self::resolve(Configurations::class, ['db' => $db, 'catalog' => $this->catalog], $container);
        $this->journals = self::resolve(Journals::class, ['db' => $db, 'policies' => $postingPolicies], $container);
        $this->periods = self::resolve(Periods::class, ['db' => $db], $container);
        $this->reports = self::resolve(Reports::class, ['db' => $db, 'configurations' => $this->configurations], $container);
        $journalDependencies = ['db' => $db, 'journals' => $this->journals];
        $this->revaluations = self::resolve(Revaluations::class, [...$journalDependencies, 'reports' => $this->reports], $container);
        $this->settlements = self::resolve(Settlements::class, [...$journalDependencies, 'revaluations' => $this->revaluations, 'reports' => $this->reports], $container);
        $this->documents = self::resolve(Documents::class, [...$journalDependencies, 'configurations' => $this->configurations], $container);
        $this->schedules = self::resolve(Schedules::class, [...$journalDependencies, 'rates' => $this->rates], $container);
        $this->yearEnd = self::resolve(YearEnd::class, [...$journalDependencies, 'periods' => $this->periods], $container);
        $this->banking = self::resolve(Banking::class, ['db' => $db], $container);
        $this->batches = self::resolve(JournalBatches::class, $journalDependencies, $container);
        $this->inventory = self::resolve(Inventory::class, $journalDependencies, $container);
        $this->assets = self::resolve(Assets::class, $journalDependencies, $container);
        $this->associates = self::resolve(Associates::class, $journalDependencies, $container);
        $this->finance = self::resolve(Finance::class, [...$journalDependencies, 'configurations' => $this->configurations], $container);
        $this->taxes = self::resolve(Taxes::class, ['db' => $db, 'configurations' => $this->configurations], $container);
        $this->statutory = self::resolve(Statutory::class, ['db' => $db], $container);
        $this->consolidation = self::resolve(Consolidation::class, ['db' => $db, 'reports' => $this->reports], $container);
        $this->monetaryAccounts = self::resolve(MonetaryAccounts::class, $journalDependencies, $container);
        $this->treatments = self::resolve(Treatments::class, [...$journalDependencies, 'configurations' => $this->configurations, 'implementations' => $postingTreatments], $container);
        $this->events = self::resolve(Events::class, ['db' => $db], $container);
        $this->budgets = self::resolve(Budgets::class, ['db' => $db], $container);
        $this->drafts = self::resolve(Drafts::class, $journalDependencies, $container);
    }

    /** @template T of object
     * @param  class-string<T>  $type
     * @param  array<string, mixed>  $parameters
     * @return T
     */
    private static function resolve(string $type, array $parameters, ?Container $container): object
    {
        $service = $container === null ? new $type(...$parameters) : $container->make($type, $parameters);
        Assert::that($service instanceof $type, 'A ledger service replacement must extend '.$type.'.');

        if (! $service instanceof $type) {
            throw new \LogicException('Invalid ledger service replacement.');
        }

        return $service;
    }
}
