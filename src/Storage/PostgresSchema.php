<?php

declare(strict_types=1);

namespace Noria\Ledger\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use stdClass;

final class PostgresSchema
{
    public static function assertSupported(): void
    {
        $connection = DB::connection();
        Assert::that($connection->getDriverName() === 'pgsql', 'Ledger requires PostgreSQL 18 or newer.');
        Assert::that($connection->getTablePrefix() === '', 'Ledger requires an empty database table prefix.');
        if ($connection->pretending()) {
            return;
        }
        $version = $connection->selectOne('SHOW server_version_num');
        Assert::that($version instanceof stdClass && (int) Row::text($version, 'server_version_num') >= 180000, 'Ledger requires PostgreSQL 18 or newer.');
    }

    public static function identity(Blueprint $table, bool $book = false, bool $tenant = true, bool $audit = false): void
    {
        if ($audit) {
            $table->bigInteger('id')->generatedAs()->always()->primary();
        } else {
            $table->uuid('id')->default(DB::raw('uuidv7()'))->primary();
        }
        if ($tenant) {
            $table->uuid('tenant_id');
        }
        if ($book) {
            $table->uuid('book_id');
        }
    }

    public static function timestamps(Blueprint $table, bool $mutable = false, bool $catalog = false): void
    {
        $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
        if ($mutable) {
            $table->timestampTz('updated_at', 6)->default(DB::raw('clock_timestamp()'));
        }
        if ($catalog) {
            $table->softDeletesTz('deleted_at', 6);
        }
    }

    public static function evidence(Blueprint $table): void
    {
        $table->timestampTz('recorded_at', 6);
        $table->bigInteger('sequence');
        $table->string('actor_id', 128);
        $table->string('firm_id', 128)->nullable();
    }

    /** @param list<string> $tables
     * @param  list<string>  $catalog
     * @param  list<string>  $evidence
     */
    public static function install(string $area, array $tables = [], array $catalog = [], array $evidence = []): void
    {
        $pattern = <<<'REGEX'
~(\$(?:[a-zA-Z_][a-zA-Z_0-9]*)?\$).*?\1(*SKIP)(*F)|'(?:''|[^'])*'(*SKIP)(*F)|"(?:""|[^"])*"(*SKIP)(*F)|;~s
REGEX;
        $statements = preg_split($pattern, self::sql($area));
        Assert::that($statements !== false, 'Cannot parse ledger SQL area: '.$area);
        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                DB::statement($statement);
            }
        }
        foreach ($tables as $table) {
            self::lifecycle($table, catalog: in_array($table, $catalog, true), evidence: in_array($table, $evidence, true));
        }
    }

    /** @param list<string> $tables */
    public static function dropTables(array $tables): void
    {
        foreach (array_reverse($tables) as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** @param array<string, list<string>> $triggers */
    public static function dropTriggers(array $triggers): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($triggers as $table => $names) {
            foreach ($names as $name) {
                DB::statement('DROP TRIGGER IF EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable($table));
            }
        }
    }

    public static function lifecycle(string $table, bool $catalog = false, bool $evidence = false): void
    {
        Assert::that(preg_match('/\Aledger_[a-z_]+\z/', $table) === 1, 'Invalid ledger table name.');
        $qualified = DB::connection()->getQueryGrammar()->wrapTable($table);
        DB::statement('CREATE TRIGGER stage_70_timestamps BEFORE INSERT OR UPDATE ON '.$qualified.' FOR EACH ROW EXECUTE FUNCTION ledger_timestamps()');
        if ($catalog) {
            DB::statement('CREATE TRIGGER stage_60_catalog_lifecycle BEFORE INSERT OR UPDATE ON '.$qualified.' FOR EACH ROW EXECUTE FUNCTION ledger_catalog_lifecycle()');
        }
        if ($evidence) {
            DB::statement('CREATE TRIGGER stage_20_json_evidence BEFORE INSERT ON '.$qualified.' FOR EACH ROW EXECUTE FUNCTION ledger_json_evidence_stamp()');
        }
    }

    /** @param list<string> $areas */
    public static function dropFunctions(array $areas): void
    {
        $names = [];
        foreach ($areas as $area) {
            preg_match_all('/\bCREATE OR REPLACE FUNCTION (ledger_[a-z_]+)\s*\(/', self::sql($area), $definitions);
            array_push($names, ...$definitions[1]);
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            return;
        }
        $parameters = implode(',', array_fill(0, count($names), '?'));
        $functions = DB::select("SELECT oid::regprocedure::text AS signature FROM pg_proc WHERE pronamespace = current_schema()::regnamespace AND prokind = 'f' AND proname IN (".$parameters.')', $names);
        foreach ($functions as $function) {
            Assert::that($function instanceof stdClass, 'Invalid ledger function signature.');
            DB::statement('DROP FUNCTION IF EXISTS '.Row::text($function, 'signature').' CASCADE');
        }
    }

    private static function sql(string $area): string
    {
        Assert::that(preg_match('/\A[a-z]+(?:_[a-z]+)*\z/', $area) === 1, 'Invalid ledger SQL area.');
        $sql = file_get_contents(__DIR__.'/../../database/'.$area.'.pgsql.sql');
        Assert::that($sql !== false, 'Missing ledger SQL area: '.$area);

        return $sql;
    }
}
