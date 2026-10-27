<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Tests\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

final class Database
{
    public static function connection(): Connection
    {
        $capsule = new Manager;
        $capsule->addConnection([
            'driver' => 'pgsql', 'host' => getenv('LEDGER_TEST_HOST') ?: '/tmp', 'port' => getenv('LEDGER_TEST_PORT') ?: '5432',
            'database' => 'noria_ledger_test', 'username' => 'noria_ledger_test', 'password' => 'ledger_test_only',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'timezone' => 'UTC',
        ]);

        return $capsule->getConnection();
    }
}
