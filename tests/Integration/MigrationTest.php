<?php

declare(strict_types=1);

use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;
use Noria\Ledger\Support\Canonical;

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

it('installs UUIDv7 defaults timestamps JSONB and power-of-two varchar bounds across every module', function () {
    $db = $this->app['db']->connection();
    $columns = $db->select("SELECT table_name, column_name, data_type, column_default, character_maximum_length FROM information_schema.columns WHERE table_schema = 'public' AND table_name LIKE 'ledger\_%' ESCAPE '\\'");
    $byTable = collect($columns)->groupBy('table_name');
    expect($byTable)->toHaveCount(38);
    foreach ($byTable as $table => $tableColumns) {
        expect($tableColumns->where('column_name', 'created_at')->first()->data_type)->toBe('timestamp with time zone');
        $mutable = in_array($table, ['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values', 'ledger_periods'], true);
        expect($tableColumns->contains('column_name', 'updated_at'))->toBe($mutable);
        expect($tableColumns->contains('column_name', 'deleted_at'))->toBe(in_array($table, ['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values'], true));
        foreach ($tableColumns as $column) {
            if ($column->data_type === 'character varying') {
                $length = (int) $column->character_maximum_length;
                expect($length > 0 && ($length & ($length - 1)) === 0)->toBeTrue();
            }
            if ($column->data_type === 'uuid' && $column->column_name === 'id') {
                expect($column->column_default)->toBe('uuidv7()');
            }
            expect($column->data_type)->not->toBe('json');
            if (in_array($column->column_name, ['definition_json', 'result_json'], true)) {
                expect($column->data_type)->toBe('jsonb');
            }
        }
    }
    expect($db->selectOne("SELECT count(*)::text AS unsafe FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relname LIKE 'ledger\_%' ESCAPE '\\' AND relkind = 'r' AND relname <> 'ledger_currency_definitions' AND NOT (relrowsecurity AND relforcerowsecurity)")->unsafe)->toBe('0');
    expect($db->selectOne("SELECT count(*)::text AS disabled FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relnamespace = 'public'::regnamespace AND c.relname LIKE 'ledger\_%' ESCAPE '\\' AND NOT t.tgisinternal AND t.tgenabled = 'D'")->disabled)->toBe('0');
});

it('generates v7 IDs in PHP PostgreSQL defaults and journal projections while accepting explicit v4 IDs', function () {
    $this->storage->run(Permission::Configure, function (): void {
        $this->storage->insert('ledger_entities', ['name' => 'Database-generated ID', 'country' => 'KE']);
        $generated = $this->storage->table('ledger_entities')->where('name', 'Database-generated ID')->first();
        expect($generated->id[14])->toBe('7');
        $legacy = '00000000-0000-4000-8000-000000000001';
        $this->storage->insert('ledger_entities', ['id' => $legacy, 'name' => 'Imported v4 ID', 'country' => 'KE']);
        expect($this->storage->find('ledger_entities', $legacy)->id)->toBe($legacy);
    });
    expect($this->entity[14])->toBe('7');
    expect($this->book[14])->toBe('7');
    $party = $this->ledger->catalog->party($this->entity, 'version-test', 'Counterparty');
    $account = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'uuid-test', '2026-01-01', 'Versioned IDs', [
        new Posting($account, Side::Debit, $money, partyId: $party), new Posting($this->income, Side::Credit, $money),
    ]));
    expect($journal->id[14])->toBe('7');
    $items = $this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'));
    expect($items[0]->id[14])->toBe('7');
    $this->ledger->journals->reverse($this->book, $journal->id, 'uuid-reversal', '2026-01-02', 'Reverse versioned IDs');
    $this->storage->run(Permission::Read, function (): void {
        expect($this->storage->table('ledger_allocations')->whereRaw('uuid_extract_version(id) <> 7')->count())->toBe(0);
    }, $this->book);
});

it('accepts the widened string boundaries consistently in PHP and PostgreSQL', function () {
    expect($this->ledger->catalog->entity(str_repeat('N', 256))[14])->toBe('7');
    $custom = new Currency(str_repeat('X', 16), 2);
    $this->ledger->catalog->registerCurrency($custom);
    $money = Money::of('1', $this->kes);
    $postings = [new Posting($this->cash, Side::Debit, $money, description: str_repeat('D', 1024)), new Posting($this->income, Side::Credit, $money)];
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'boundary-post', '2026-01-01', str_repeat('D', 1024), $postings));
    expect($journal->id[14])->toBe('7');
    new JournalRequest($this->book, 'too-long', '2026-01-01', str_repeat('D', 1025), $postings);
})->throws(LedgerException::class, 'too long');

it('upgrades populated legacy storage without changing financial history or losing configuration identity', function () {
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'legacy-receipt', '2026-01-01', 'Legacy receipt', [
        new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money),
    ]));
    $pack = new Pack('legacy-policy', 'v1', roles: ['bank' => '1000']);
    $configuration = $this->ledger->configurations->install($this->book, $pack);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $report = $this->ledger->reports->saveStatement($this->book, 'legacy-report', new StatementDefinition('Legacy cash', 'v1', ['cash' => ['label' => 'Cash', 'accounts' => ['1000'], 'basis' => 'closing']]),
        [new StatementColumn('current', 'Current', $snapshot, '2026-01-01')]);
    $auditCount = $this->storage->run(Permission::Read, fn () => $this->storage->table('ledger_audit_events')->count(), $this->book);
    $this->storage->run(Permission::Read, fn () => $this->storage->connection->statement('SET CONSTRAINTS ALL IMMEDIATE'), $this->book);
    $db = $this->app['db']->connection();
    $db->unprepared(<<<'SQL'
DO $body$
DECLARE relation record; constraint_info record;
BEGIN
    FOR relation IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ledger\_%' ESCAPE '\' LOOP
        EXECUTE format('DROP TRIGGER IF EXISTS x_timestamps ON %I', relation.tablename);
        EXECUTE format('DROP TRIGGER IF EXISTS w_catalog_lifecycle ON %I', relation.tablename);
        EXECUTE format('ALTER TABLE %I DROP COLUMN IF EXISTS created_at, DROP COLUMN IF EXISTS updated_at, DROP COLUMN IF EXISTS deleted_at', relation.tablename);
    END LOOP;
    FOR constraint_info IN SELECT c.conname, t.relname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
        WHERE t.relname IN ('ledger_configurations', 'ledger_statement_runs') AND c.contype = 'c'
            AND pg_get_constraintdef(c.oid) ~ '(definition_json|definition_hash|result_json|result_hash)' LOOP
        EXECUTE format('ALTER TABLE %I DROP CONSTRAINT %I', constraint_info.relname, constraint_info.conname);
    END LOOP;
    ALTER TABLE ledger_configurations ALTER COLUMN definition_json TYPE text USING definition_json::text;
    ALTER TABLE ledger_configurations ADD CONSTRAINT legacy_configuration_hash CHECK (definition_hash = encode(sha256(convert_to(definition_json, 'UTF8')), 'hex'));
    ALTER TABLE ledger_statement_runs ALTER COLUMN result_json TYPE text USING result_json::text;
    ALTER TABLE ledger_statement_runs ADD CONSTRAINT legacy_statement_hash CHECK (result_hash = encode(sha256(convert_to(result_json, 'UTF8')), 'hex'));
    ALTER TABLE ledger_accounts ALTER COLUMN name TYPE varchar(255);
    ALTER TABLE ledger_journals ALTER COLUMN description TYPE varchar(1000);
    ALTER TABLE ledger_entities ALTER COLUMN id SET DEFAULT gen_random_uuid();
END;
$body$;
SQL);
    $upgrade = require __DIR__.'/../../database/migrations/2026_10_27_000002_update_ledger_storage.php';
    $upgrade->up();
    expect($this->ledger->journals->get($this->book, $journal->id)->sequence)->toBe($journal->sequence);
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('100.00');
    expect($this->ledger->configurations->install($this->book, $pack))->toBe($configuration);
    $evidence = $this->ledger->configurations->get($this->book, $configuration);
    expect($evidence['content_hash'])->toBe(Canonical::hash($pack));
    expect(hash('sha256', $evidence['definition_json']))->toBe($evidence['definition_hash']);
    $saved = $this->ledger->reports->statementEvidence($this->book, $report);
    expect($saved['result']['rows']['cash']['values']['current']['amount'])->toBe('100.00');
    $this->storage->run(Permission::Read, function () use ($journal, $auditCount): void {
        $row = $this->storage->find('ledger_journals', $journal->id, $this->book);
        expect($row->created_at)->toBe($row->recorded_at);
        expect($this->storage->table('ledger_audit_events')->count())->toBe($auditCount);
    }, $this->book);
    expect($db->selectOne("SELECT character_maximum_length::text AS length FROM information_schema.columns WHERE table_name = 'ledger_accounts' AND column_name = 'name'")->length)->toBe('256');
    expect($db->selectOne("SELECT count(*)::text AS unsafe FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relname LIKE 'ledger\_%' ESCAPE '\\' AND relkind = 'r' AND relname <> 'ledger_currency_definitions' AND NOT (relrowsecurity AND relforcerowsecurity)")->unsafe)->toBe('0');
    expect($db->table('ledger_journals')->count())->toBe(0);
});
