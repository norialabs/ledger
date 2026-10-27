<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use NoriaLabs\Ledger\Accounting\PostingPolicy;
use NoriaLabs\Ledger\Storage\ScopedDatabase;

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

    public Consolidation $consolidation;

    public MonetaryAccounts $monetaryAccounts;

    /** @param list<PostingPolicy> $postingPolicies */
    public function __construct(ScopedDatabase $db, array $postingPolicies = [])
    {
        $this->catalog = new Catalog($db);
        $this->journals = new Journals($db, $postingPolicies);
        $this->periods = new Periods($db);
        $this->reports = new Reports($db);
        $this->revaluations = new Revaluations($db, $this->journals, $this->reports);
        $this->settlements = new Settlements($db, $this->journals, $this->revaluations);
        $this->documents = new Documents($db, $this->journals);
        $this->schedules = new Schedules($db, $this->journals);
        $this->yearEnd = new YearEnd($db, $this->journals, $this->periods);
        $this->banking = new Banking($db);
        $this->batches = new JournalBatches($db, $this->journals);
        $this->inventory = new Inventory($db, $this->journals);
        $this->assets = new Assets($db, $this->journals);
        $this->consolidation = new Consolidation($db, $this->reports);
        $this->monetaryAccounts = new MonetaryAccounts($db, $this->journals);
    }
}
