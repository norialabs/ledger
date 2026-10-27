<?php

declare(strict_types=1);

use Brick\Money\IsoCurrencyProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use NoriaLabs\Ledger\Exceptions\LedgerException;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Row;

return new class extends Migration
{
    public function up(): void
    {
        Assert::that(DB::connection()->getDriverName() === 'pgsql', 'Ledger requires PostgreSQL 18 or newer.');
        Assert::that(DB::connection()->getTablePrefix() === '', 'Ledger requires an empty database table prefix.');
        $version = DB::selectOne('SHOW server_version_num');

        if (! $version instanceof stdClass || (int) Row::text($version, 'server_version_num') < 180000) {
            throw new LedgerException('Ledger requires PostgreSQL 18 or newer.');
        }

        foreach (['schema', 'revaluations', 'inventory', 'inventory-returns', 'inventory-transfers', 'assets', 'consolidation', 'monetary'] as $module) {
            $sql = file_get_contents(__DIR__.'/../'.$module.'.pgsql.sql');
            Assert::that($sql !== false, 'Ledger schema is missing.');
            DB::connection()->getPdo()->exec($sql === false ? '' : $sql);
        }

        foreach (IsoCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            DB::table('ledger_currency_definitions')->insert(['code' => $currency->getCurrencyCode(), 'scale' => $currency->getDefaultFractionDigits()]);
        }

        DB::unprepared('CREATE TRIGGER a_reference_insert BEFORE INSERT ON ledger_currency_definitions FOR EACH ROW EXECUTE FUNCTION ledger_immutable()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS ledger_monetary_valuations, ledger_monetary_accounts, ledger_consolidation_runs, ledger_asset_events, ledger_assets, ledger_inventory_transfers, ledger_inventory_layer_entries, ledger_inventory_moves, ledger_inventory_pools, ledger_revaluation_clearings, ledger_revaluations, ledger_batch_members, ledger_batches, ledger_bank_matches, ledger_bank_lines, ledger_bank_voids, ledger_bank_statements, ledger_year_closures, ledger_schedule_runs, ledger_schedule_cancellations, ledger_schedules, ledger_audit_events, ledger_allocations, ledger_open_items, ledger_postings, ledger_journals, ledger_account_dimensions, ledger_dimension_values, ledger_periods, ledger_parties, ledger_accounts, ledger_books, ledger_entities, ledger_currencies, ledger_currency_definitions CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_monetary_quote(uuid, uuid, uuid, date, jsonb, uuid), ledger_monetary_validate(), ledger_monetary_links(), ledger_consolidation_stamp(), ledger_asset_row(uuid, numeric), ledger_asset_plan_check(jsonb, date, numeric), ledger_asset_quote(uuid, uuid, uuid, date, bigint), ledger_asset_event_quote(uuid, uuid, uuid, text, date, numeric, uuid, uuid, uuid, numeric, jsonb, uuid, integer), ledger_asset_validate(), ledger_inventory_return_chronology(uuid, uuid, uuid, date), ledger_inventory_return_quote(uuid, uuid, uuid, uuid, date, numeric), ledger_inventory_transfer_validate(), ledger_inventory_links(), ledger_inventory_quote(uuid, uuid, uuid, text, numeric, numeric), ledger_inventory_layers(uuid, uuid, uuid), ledger_inventory_validate(), ledger_inventory_project(), ledger_revaluation_validate(), ledger_revaluation_clearing_validate(), ledger_allocation_revaluation_guard(), ledger_journal_links(), ledger_batch_record(), ledger_bank_validate(), ledger_bank_import(), ledger_evidence_stamp(), ledger_extension_validate(), ledger_allocation_validate(), ledger_journal_post(), ledger_event_stamp(), ledger_master_guard(), ledger_audit(), ledger_derived_insert(), ledger_immutable(), ledger_scope_guard(), ledger_fraction(numeric, numeric, text) CASCADE');
    }
};
