<?php

declare(strict_types=1);

use Brick\Money\IsoCurrencyProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;

return new class extends Migration
{
    private const MODULES = ['schema', 'inventory', 'assets', 'groups', 'currency', 'finance', 'tax', 'statutory', 'storage'];

    public function up(): void
    {
        Assert::that(DB::connection()->getDriverName() === 'pgsql', 'Ledger requires PostgreSQL 18 or newer.');
        Assert::that(DB::connection()->getTablePrefix() === '', 'Ledger requires an empty database table prefix.');
        $version = DB::selectOne('SHOW server_version_num');
        if (! $version instanceof stdClass || (int) Row::text($version, 'server_version_num') < 180000) {
            throw new LedgerException('Ledger requires PostgreSQL 18 or newer.');
        }
        $schema = $this->schema();
        foreach ($schema['sql'] as $sql) {
            DB::connection()->getPdo()->exec($sql);
        }
        $parameters = implode(',', array_fill(0, count($schema['tables']), '?'));
        DB::select('SELECT ledger_initialize_storage(ARRAY['.$parameters.']::text[])', $schema['tables']);
        DB::unprepared('DROP FUNCTION ledger_initialize_storage(text[])');

        foreach (IsoCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            DB::table('ledger_currency_definitions')->insert(['code' => $currency->getCurrencyCode(), 'scale' => $currency->getDefaultFractionDigits()]);
        }
        DB::unprepared('CREATE TRIGGER a_reference_insert BEFORE INSERT ON ledger_currency_definitions FOR EACH ROW EXECUTE FUNCTION ledger_immutable()');
    }

    public function down(): void
    {
        $schema = $this->schema();
        DB::connection()->getPdo()->exec('DROP TABLE IF EXISTS '.implode(', ', array_reverse($schema['tables'])).' CASCADE');
        $parameters = implode(',', array_fill(0, count($schema['functions']), '?'));
        $functions = DB::select("SELECT oid::regprocedure::text AS signature FROM pg_proc WHERE pronamespace = current_schema()::regnamespace AND prokind = 'f' AND proname IN (".$parameters.')', $schema['functions']);
        foreach ($functions as $function) {
            if (! $function instanceof stdClass) {
                throw new LogicException('Invalid ledger function signature.');
            }
            DB::connection()->getPdo()->exec('DROP FUNCTION IF EXISTS '.Row::text($function, 'signature').' CASCADE');
        }
    }

    /** @return array{sql: list<string>, tables: list<string>, functions: list<string>} */
    private function schema(): array
    {
        $statements = [];
        $tables = [];
        $functions = [];
        foreach (self::MODULES as $module) {
            $sql = file_get_contents(__DIR__.'/../'.$module.'.pgsql.sql');
            Assert::that($sql !== false, 'Ledger schema is missing: '.$module);
            if ($sql === false) {
                throw new LogicException('Missing ledger schema.');
            }
            $statements[] = $sql;
            preg_match_all('/\bCREATE TABLE (ledger_[a-z_]+)\s*\(/', $sql, $definitions);
            array_push($tables, ...$definitions[1]);
            preg_match_all('/\bCREATE OR REPLACE FUNCTION (ledger_[a-z_]+)\s*\(/', $sql, $definitions);
            array_push($functions, ...$definitions[1]);
        }
        Assert::that($tables !== [] && count($tables) === count(array_unique($tables)), 'Ledger table definitions must be unique.');

        return ['sql' => $statements, 'tables' => $tables, 'functions' => array_values(array_unique($functions))];
    }
};
