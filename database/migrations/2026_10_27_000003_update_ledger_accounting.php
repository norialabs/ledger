<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Noria\Ledger\Support\Assert;

return new class extends Migration
{
    public function up(): void
    {
        $legacyProduction = DB::connection()->getSchemaBuilder()->hasTable('ledger_productions')
            && ! DB::connection()->getSchemaBuilder()->hasColumn('ledger_productions', 'conversion_minor');
        if (! DB::connection()->getSchemaBuilder()->hasTable('ledger_productions')) {
            $sql = file_get_contents(__DIR__.'/../manufacturing.pgsql.sql');
            Assert::that($sql !== false, 'Ledger manufacturing schema is missing.');
            DB::connection()->getPdo()->exec($sql === false ? '' : $sql);
        }

        if ($legacyProduction) {
            DB::unprepared(<<<'SQL'
ALTER TABLE ledger_productions ADD COLUMN conversion_minor numeric NOT NULL DEFAULT 0;
DO $body$
DECLARE definition record;
BEGIN
    FOR definition IN SELECT conname FROM pg_constraint WHERE conrelid = 'ledger_productions'::regclass AND contype = 'c'
        AND pg_get_constraintdef(oid) ~ '(output_quantity|output_value_minor)' LOOP
        EXECUTE format('ALTER TABLE ledger_productions DROP CONSTRAINT %I', definition.conname);
    END LOOP;
END;
$body$;
ALTER TABLE ledger_productions
    ADD CHECK ((reverses_id IS NULL AND output_quantity > 0 AND output_value_minor >= 0 AND conversion_minor >= 0)
        OR (reverses_id IS NOT NULL AND output_quantity < 0 AND output_value_minor <= 0 AND conversion_minor <= 0)),
    ADD CHECK (output_quantity = trunc(output_quantity, 12) AND abs(output_quantity) < power(10::numeric, 26)),
    ADD CHECK (output_value_minor = trunc(output_value_minor) AND abs(output_value_minor) < power(10::numeric, 38)),
    ADD CHECK (conversion_minor = trunc(conversion_minor) AND abs(conversion_minor) < power(10::numeric, 38));
ALTER TABLE ledger_inventory_moves ADD CONSTRAINT ledger_inventory_production_exclusive CHECK (production_id IS NULL OR (transfer_id IS NULL AND returns_id IS NULL));
SQL);
        }

        foreach (['schema', 'revaluations', 'inventory', 'inventory-returns', 'inventory-transfers', 'manufacturing', 'assets', 'consolidation', 'monetary'] as $module) {
            $sql = file_get_contents(__DIR__.'/../'.$module.'.pgsql.sql');
            Assert::that($sql !== false, 'Ledger schema is missing.');
            preg_match_all('/CREATE OR REPLACE FUNCTION\b.*?\bAS (\$(?:[a-zA-Z_][a-zA-Z_0-9]*)?\$).*?\1;/s', $sql === false ? '' : $sql, $functions);
            foreach ($functions[0] as $function) {
                DB::connection()->getPdo()->exec($function);
            }
        }

        if ($legacyProduction) {
            DB::unprepared('CREATE CONSTRAINT TRIGGER zz_ledger_production_wip_links AFTER INSERT ON ledger_journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION ledger_production_wip_links()');
        }

        $sql = file_get_contents(__DIR__.'/../storage.pgsql.sql');
        Assert::that($sql !== false, 'Ledger storage schema is missing.');
        DB::connection()->getPdo()->exec($sql === false ? '' : $sql);
    }

    public function down(): void {}
};
