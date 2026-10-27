<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Tests\Support;

use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Row;
use RuntimeException;

final class Race
{
    public static function run(ScopedDatabase $db, string $bookId, array $commands): array
    {
        $prefix = 'ledger-race:'.Str::uuid();
        $workers = [];

        try {
            $db->run(Permission::Post, function () use ($db, $bookId, $commands, $prefix, &$workers): void {
                $db->lockBook($bookId);

                foreach ($commands as $index => $command) {
                    $pipes = [];
                    $process = proc_open([PHP_BINARY, __DIR__.'/../Fixtures/worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

                    if ($process === false) {
                        throw new RuntimeException('Cannot start concurrent worker.');
                    }

                    $workers[] = [$process, $pipes];
                    stream_set_timeout($pipes[1], 10);
                    fwrite($pipes[0], json_encode([...$command, 'application' => $prefix.':'.$index], JSON_THROW_ON_ERROR)."\n");
                    fflush($pipes[0]);

                    if (fgets($pipes[1]) !== "READY\n") {
                        throw new RuntimeException('Worker did not start: '.stream_get_contents($pipes[2]));
                    }
                }

                foreach ($workers as [$process, $pipes]) {
                    fwrite($pipes[0], "GO\n");
                    fflush($pipes[0]);
                }

                $deadline = microtime(true) + 5;

                do {
                    $row = $db->connection->selectOne("SELECT count(*)::text AS waiting FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock'", [$prefix.':%']);

                    if ($row !== null && (int) Row::text($row, 'waiting') === count($commands)) {
                        return;
                    }

                    $db->connection->select('SELECT pg_stat_clear_snapshot()');
                    usleep(10000);
                } while (microtime(true) < $deadline);

                throw new RuntimeException('Both workers must be observed waiting for the same book lock.');
            }, $bookId);

            $results = [];

            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);

                if ($status !== 0 || $output === false) {
                    throw new RuntimeException('Worker failed: '.$error);
                }

                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            $workers = [];

            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}
