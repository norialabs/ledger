<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    public function up(): void
    {
        PostgresSchema::install('integrity');
    }

    public function down(): void
    {
        PostgresSchema::dropTriggers([
            'ledger_journals' => ['stage_99_integrity_journal_links', 'stage_99_integrity_production_wip_links', 'stage_99_integrity_associate_links', 'stage_99_integrity_monetary_links', 'stage_99_integrity_finance_links'],
            'ledger_allocations' => ['stage_40_revaluations'],
            'ledger_assets' => ['stage_21_finance_accounts'],
            'ledger_inventory_pools' => ['stage_21_finance_accounts'],
            'ledger_associates' => ['stage_21_finance_accounts'],
            'ledger_monetary_accounts' => ['stage_21_finance_accounts'],
        ]);
        PostgresSchema::dropFunctions(['integrity']);
    }
};
