<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Noria\Ledger\Support\Assert;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['schema', 'revaluations', 'inventory', 'inventory-returns', 'inventory-transfers', 'assets', 'consolidation', 'monetary'] as $module) {
            $sql = file_get_contents(__DIR__.'/../'.$module.'.pgsql.sql');
            Assert::that($sql !== false, 'Ledger schema is missing.');
            preg_match_all('/CREATE OR REPLACE FUNCTION\b.*?\bAS (\$(?:[a-zA-Z_][a-zA-Z_0-9]*)?\$).*?\1;/s', $sql === false ? '' : $sql, $functions);
            foreach ($functions[0] as $function) {
                DB::connection()->getPdo()->exec($function);
            }
        }
        $sql = file_get_contents(__DIR__.'/../storage.pgsql.sql');
        Assert::that($sql !== false, 'Ledger storage schema is missing.');
        DB::connection()->getPdo()->exec($sql === false ? '' : $sql);
    }

    public function down(): void {}
};
