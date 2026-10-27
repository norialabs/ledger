<?php

declare(strict_types=1);

it('rolls the complete schema down and recreates all modules', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_10_27_000001_create_ledger.php';
    $migration->down();
    $db = $this->app['db']->connection();
    expect($db->selectOne("SELECT count(*)::text AS remaining FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'ledger_%'")->remaining)->toBe('0');
    $migration->up();
    foreach (['ledger_journals', 'ledger_revaluations', 'ledger_inventory_moves', 'ledger_inventory_transfers', 'ledger_assets', 'ledger_asset_events',
        'ledger_consolidation_runs', 'ledger_monetary_accounts', 'ledger_monetary_valuations'] as $table) {
        expect($db->getSchemaBuilder()->hasTable($table))->toBeTrue();
    }
});
