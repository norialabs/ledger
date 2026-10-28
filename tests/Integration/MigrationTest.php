<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Tests\Support\PermitAll;

it('ships one fresh-install migration and rolls the complete schema down and up', function () {
    expect(array_map('basename', glob(__DIR__.'/../../database/migrations/*.php')))->toBe(['2026_10_27_000001_create_ledger.php']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_27_000001_create_ledger.php';
    $migration->down();
    $db = $this->app['db']->connection();
    expect($db->selectOne("SELECT count(*)::text AS remaining FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'ledger_%'")->remaining)->toBe('0');
    $migration->up();
    expect($db->selectOne("SELECT count(*)::text AS tables FROM pg_tables WHERE schemaname = 'public' AND left(tablename, 7) = 'ledger_'")->tables)->toBe('48');
    expect($db->selectOne("SELECT count(*)::text AS installers FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname IN ('ledger_initialize_storage', 'ledger_install_storage')")->installers)->toBe('0');
});

it('uses id as the first column and only primary-key column on every package table', function () {
    $rows = $this->app['db']->connection()->select(<<<'SQL'
SELECT t.relname AS table_name, a.attname AS first_column, array_to_string(ARRAY(
    SELECT p.attname FROM unnest(k.conkey) WITH ORDINALITY c(attnum, position)
    JOIN pg_attribute p ON p.attrelid = t.oid AND p.attnum = c.attnum ORDER BY c.position
), ',') AS primary_key
FROM pg_class t JOIN pg_namespace n ON n.oid = t.relnamespace
LEFT JOIN pg_constraint k ON k.conrelid = t.oid AND k.contype = 'p'
JOIN LATERAL (SELECT attname FROM pg_attribute WHERE attrelid = t.oid AND attnum > 0 AND NOT attisdropped ORDER BY attnum LIMIT 1) a ON true
WHERE n.nspname = 'public' AND t.relkind = 'r' AND left(t.relname, 7) = 'ledger_' ORDER BY t.relname
SQL);
    expect($rows)->toHaveCount(48);
    foreach ($rows as $row) {
        expect($row->first_column)->toBe('id', $row->table_name)
            ->and($row->primary_key)->toBe('id', $row->table_name);
    }
});

it('installs v7 defaults lifecycle metadata JSONB and consistent string bounds directly on a fresh schema', function () {
    $db = $this->app['db']->connection();
    $columns = $db->select("SELECT table_name, column_name, data_type, column_default, character_maximum_length, is_identity FROM information_schema.columns WHERE table_schema = 'public' AND left(table_name, 7) = 'ledger_'");
    $byTable = collect($columns)->groupBy('table_name');
    expect($byTable)->toHaveCount(48);
    foreach ($byTable as $table => $tableColumns) {
        expect($tableColumns->where('column_name', 'created_at')->first()->data_type)->toBe('timestamp with time zone');
        $mutable = in_array($table, ['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values', 'ledger_periods'], true);
        expect($tableColumns->contains('column_name', 'updated_at'))->toBe($mutable);
        expect($tableColumns->contains('column_name', 'deleted_at'))->toBe(in_array($table, ['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values'], true));
        $id = $tableColumns->where('column_name', 'id')->first();
        if ($table === 'ledger_audit_events') {
            expect($id->data_type)->toBe('bigint')->and($id->is_identity)->toBe('YES');
        } else {
            expect($id->data_type)->toBe('uuid')->and($id->column_default)->toBe('uuidv7()');
        }
        foreach ($tableColumns as $column) {
            if ($column->data_type === 'character varying') {
                $length = (int) $column->character_maximum_length;
                expect($length > 0 && ($length & ($length - 1)) === 0)->toBeTrue();
            }
            expect($column->data_type)->not->toBe('json');
        }
    }
    expect($db->selectOne("SELECT count(*)::text AS unsafe FROM pg_class WHERE relnamespace = 'public'::regnamespace AND left(relname, 7) = 'ledger_' AND relkind = 'r' AND relname <> 'ledger_currency_definitions' AND NOT (relrowsecurity AND relforcerowsecurity)")->unsafe)->toBe('0');
    expect($db->selectOne("SELECT count(*)::text AS disabled FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relnamespace = 'public'::regnamespace AND left(c.relname, 7) = 'ledger_' AND NOT t.tgisinternal AND t.tgenabled = 'D'")->disabled)->toBe('0');
});

it('retains business and relationship tuples as unique keys instead of primary keys', function () {
    $rows = $this->app['db']->connection()->select(<<<'SQL'
SELECT t.relname AS table_name, array_to_string(ARRAY(
    SELECT a.attname FROM unnest(c.conkey) WITH ORDINALITY k(attnum, position)
    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum ORDER BY k.position
), ',') AS columns
FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
WHERE t.relnamespace = 'public'::regnamespace AND left(t.relname, 7) = 'ledger_' AND c.contype = 'u'
SQL);
    $keys = array_map(fn ($row): string => $row->table_name.':'.$row->columns, $rows);
    foreach ([
        'ledger_currency_definitions:code', 'ledger_currencies:tenant_id,code',
        'ledger_dimension_values:tenant_id,entity_id,dimension,value', 'ledger_account_dimensions:tenant_id,book_id,account_id,dimension',
        'ledger_postings:tenant_id,book_id,journal_id,line_no', 'ledger_batch_members:tenant_id,book_id,journal_id',
        'ledger_inventory_layer_entries:tenant_id,book_id,movement_id,layer_id', 'ledger_events:tenant_id,book_id,sequence', 'ledger_audit_events:tenant_id,id',
    ] as $key) {
        expect($keys)->toContain($key);
    }
});

it('retains validated tenant and book ownership in composite foreign keys', function () {
    $rows = $this->app['db']->connection()->select(<<<'SQL'
SELECT source.relname AS table_name, target.relname AS referenced_table, c.convalidated,
    ARRAY(SELECT a.attname::text FROM unnest(c.conkey) WITH ORDINALITY k(attnum, position)
        JOIN pg_attribute a ON a.attrelid = source.oid AND a.attnum = k.attnum ORDER BY k.position) AS source_columns,
    EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = target.oid AND attname = 'tenant_id' AND NOT attisdropped) AS tenant_owned,
    EXISTS (SELECT 1 FROM pg_attribute WHERE attrelid = target.oid AND attname = 'book_id' AND NOT attisdropped) AS book_owned
FROM pg_constraint c JOIN pg_class source ON source.oid = c.conrelid JOIN pg_class target ON target.oid = c.confrelid
WHERE source.relnamespace = 'public'::regnamespace AND left(source.relname, 7) = 'ledger_' AND c.contype = 'f'
SQL);
    expect(count($rows))->toBeGreaterThan(100);
    foreach ($rows as $row) {
        expect($row->convalidated)->toBeTrue();
        if ($row->tenant_owned) {
            expect($row->source_columns)->toContain('tenant_id');
        }
        if ($row->book_owned) {
            expect($row->source_columns)->toContain('book_id');
        }
    }
});

it('generates v7 identities for catalog and derived rows while accepting imported v4 identities', function () {
    $this->storage->run(Permission::Configure, function (): void {
        $this->storage->insert('ledger_entities', ['name' => 'Database-generated ID', 'country' => 'KE']);
        $generated = $this->storage->table('ledger_entities')->where('name', 'Database-generated ID')->first();
        expect($generated->id[14])->toBe('7');
        $id = '00000000-0000-4000-8000-000000000001';
        $this->storage->insert('ledger_entities', ['id' => $id, 'name' => 'Imported v4 ID', 'country' => 'KE']);
        expect($this->storage->find('ledger_entities', $id)->id)->toBe($id);
    });
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->ledger->catalog->requireDimension($this->book, $this->income, 'campus');
    $party = $this->ledger->catalog->party($this->entity, 'version-test', 'Counterparty');
    $account = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $money = Money::of('100', $this->kes);
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'uuid-test', '2026-01-01', 'Versioned IDs', [
        new Posting($account, Side::Debit, $money, partyId: $party, dimensions: ['campus' => 'west']), new Posting($this->income, Side::Credit, $money, dimensions: ['campus' => 'west']),
    ]));
    expect($journal->id[14])->toBe('7');
    $this->ledger->journals->reverse($this->book, $journal->id, 'uuid-reversal', '2026-01-02', 'Reverse versioned IDs');
    $this->storage->run(Permission::Read, function (): void {
        foreach (['ledger_currencies', 'ledger_dimension_values', 'ledger_account_dimensions', 'ledger_postings', 'ledger_open_items', 'ledger_allocations', 'ledger_events'] as $table) {
            expect($this->storage->table($table)->count())->toBeGreaterThan(0);
            expect($this->storage->table($table)->whereRaw('uuid_extract_version(id) <> 7')->count())->toBe(0);
        }
    }, $this->book);
});

it('enforces globally unique ids even across separate tenant ownership scopes', function () {
    $other = new ScopedDatabase($this->storage->connection, new Scope((string) Str::uuid7(), 'other-tenant'), new PermitAll);
    $other->run(Permission::Configure, fn () => $other->insert('ledger_entities', ['id' => $this->entity, 'name' => 'Duplicate identity', 'country' => 'KE']));
})->throws(QueryException::class, 'ledger_entities_pkey');

it('keeps added currency and dimension identities immutable', function (string $table) {
    $this->ledger->catalog->dimensionValue($this->entity, 'campus', 'west');
    $this->storage->run(Permission::Configure, function () use ($table): void {
        $row = $this->storage->table($table)->first();
        $this->storage->table($table)->where('id', $row->id)->update(['id' => (string) Str::uuid7()]);
    });
})->with(['ledger_currencies', 'ledger_dimension_values'])->throws(QueryException::class, 'immutable');

it('initializes and rolls back only declared package objects', function () {
    $db = $this->app['db']->connection();
    $db->unprepared('CREATE TABLE ledger_host_notes (id uuid PRIMARY KEY DEFAULT uuidv7(), note text NOT NULL)');
    $db->unprepared('CREATE FUNCTION ledger_host_echo(text) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT $1 $$');
    $db->table('ledger_host_notes')->insert(['note' => 'Host-owned evidence']);
    $migration = require __DIR__.'/../../database/migrations/2026_10_27_000001_create_ledger.php';
    $migration->down();
    expect($db->table('ledger_host_notes')->count())->toBe(1)
        ->and($db->selectOne("SELECT ledger_host_echo('retained') AS value")->value)->toBe('retained');
    $migration->up();
    expect($db->getSchemaBuilder()->hasColumn('ledger_host_notes', 'created_at'))->toBeFalse()
        ->and($db->table('ledger_host_notes')->first()->note)->toBe('Host-owned evidence');
});

it('accepts the declared string boundaries consistently in PHP and PostgreSQL', function () {
    expect($this->ledger->catalog->entity(str_repeat('N', 256))[14])->toBe('7');
    $custom = new Currency(str_repeat('X', 16), 2);
    $this->ledger->catalog->registerCurrency($custom);
    $money = Money::of('1', $this->kes);
    $postings = [
        new Posting($this->cash, Side::Debit, $money), new Posting($this->income, Side::Credit, $money),
    ];
    $journal = $this->ledger->journals->post(new JournalRequest($this->book, 'boundary-post', '2026-01-01', str_repeat('D', 1024), $postings));
    expect($journal->id[14])->toBe('7');
    new JournalRequest($this->book, 'too-long', '2026-01-01', str_repeat('D', 1025), $postings);
})->throws(LedgerException::class, 'too long');
