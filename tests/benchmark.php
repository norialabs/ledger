<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\AccountBalance;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';

/** @param list<AccountBalance> $balances */
function byAccountBalance(array $balances, string $accountId): string
{
    foreach ($balances as $balance) {
        if ($balance->accountId === $accountId) {
            return $balance->closing->decimal();
        }
    }

    return '0.00';
}

final class LedgerBenchmark
{
    public static function main(): void
    {
        $options = getopt('', ['worker', 'seed:', 'iterations:', 'workers:', 'books:', 'workload:', 'plans', 'scaling', 'parties:', 'cycles:', 'factor:', 'help']);
        if (isset($options['help'])) {
            fwrite(STDOUT, "php tests/benchmark.php --seed=1000 --iterations=100 --workers=4 --books=1 --workload=journal\nWorkloads: journal, allocation, oldest, fifo, average, finance. Oldest seeds settled invoices across 100 other parties and allocates each receipt through allocateOldest(). Books must be 1 or the worker count. Seed is journals per book. A concurrent reader uses fixed saved cutoffs. Uses only the disposable noria_ledger_test database; run a Pest integration test first to install migrations.\nphp tests/benchmark.php --scaling --parties=1000 --cycles=5 --factor=10\nBuilds a utility book at two sizes through billing runs and compares read and run costs between them.\n");

            return;
        }
        if (isset($options['worker'])) {
            self::worker();

            return;
        }
        $integer = static function (string $key, int $default, int $maximum) use ($options): int {
            $value = $options[$key] ?? (string) $default;
            if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1 || (int) $value > $maximum) {
                throw new InvalidArgumentException($key.' must be between 1 and '.$maximum.'.');
            }

            return (int) $value;
        };
        $seed = $integer('seed', 1000, 10000000);
        $iterations = $integer('iterations', 100, 600);
        $workers = $integer('workers', 4, 32);
        $books = $integer('books', 1, 32);
        $workload = $options['workload'] ?? 'journal';
        if (! is_string($workload) || ! in_array($workload, ['journal', 'allocation', 'oldest', 'fifo', 'average', 'finance'], true) || ! in_array($books, [1, $workers], true)) {
            throw new InvalidArgumentException('Choose a supported workload and either one book or one book per worker.');
        }

        $connection = Database::connection();
        $connection->statement('SET statement_timeout = 60000');
        $environment = $connection->selectOne("SELECT version() AS postgres, current_user AS role, current_setting('synchronous_commit') AS synchronous_commit, current_setting('fsync') AS fsync, current_setting('shared_buffers') AS shared_buffers, current_setting('max_connections') AS max_connections, r.rolsuper, r.rolbypassrls FROM pg_roles r WHERE r.rolname = current_user");
        if (! $environment instanceof stdClass || $environment->rolsuper || $environment->rolbypassrls || $environment->synchronous_commit !== 'on' || $environment->fsync !== 'on') {
            throw new RuntimeException('Benchmark requires a non-superuser/non-bypass-RLS role and durable commits.');
        }
        $disabled = $connection->selectOne("SELECT count(*)::text AS total FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname LIKE 'ledger_%' AND t.tgenabled NOT IN ('O', 'A')");
        $integrity = $connection->selectOne("SELECT count(*)::text AS total FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = 'ledger_journals' AND t.tgname = 'stage_99_integrity_journal_links' AND t.tgdeferrable AND t.tginitdeferred");
        $unprotected = $connection->selectOne("SELECT count(*)::text AS total FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relkind = 'r' AND c.relname LIKE 'ledger_%' AND c.relname <> 'ledger_currency_definitions' AND (NOT c.relrowsecurity OR NOT c.relforcerowsecurity)");
        if (! $disabled instanceof stdClass || Row::text($disabled, 'total') !== '0' || ! $integrity instanceof stdClass || Row::text($integrity, 'total') !== '1'
            || ! $unprotected instanceof stdClass || Row::text($unprotected, 'total') !== '0' || ! $connection->getSchemaBuilder()->hasTable('ledger_statutory_submissions')) {
            throw new RuntimeException('Install the complete migrations with enabled triggers before benchmarking.');
        }

        if (isset($options['scaling'])) {
            $parties = $integer('parties', 1000, 1000000);
            $cycles = $integer('cycles', 5, 12);
            $factor = $integer('factor', 10, 100);
            $small = self::scale($connection, $parties, $cycles);
            $large = self::scale($connection, $parties * $factor, $cycles);
            $ratios = ['bill_run_seconds_per_document' => $large['bill_run_seconds_per_document'] / $small['bill_run_seconds_per_document']];
            foreach ($small['reads'] as $name => $read) {
                $ratios[$name] = ['time' => $large['reads'][$name]['median_ms'] / max($read['median_ms'], 0.001), 'php_memory' => $large['reads'][$name]['php_peak_kib'] / max($read['php_peak_kib'], 1)];
            }
            fwrite(STDOUT, json_encode(['environment' => $environment, 'factor' => $factor, 'small' => $small, 'large' => $large, 'large_over_small' => $ratios], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");

            return;
        }

        $fixtures = [];
        $setup = hrtime(true);
        for ($index = 0; $index < $books; $index++) {
            $fixtures[] = self::fixture($connection, $seed, $iterations * intdiv($workers, $books), $workload);
        }
        $commands = [];
        for ($index = 0; $index < $workers; $index++) {
            $fixture = $fixtures[$index % $books];
            $contract = $workload === 'finance' ? self::contract($connection, $fixture, $iterations, $index) : '';
            $commands[] = [...$fixture, 'iterations' => $iterations, 'worker' => $index, 'workload' => $workload, 'contract' => $contract];
        }
        $commands[] = [...$fixtures[0], 'iterations' => $iterations, 'worker' => $workers, 'workload' => 'reader', 'contract' => ''];
        $connection->statement('ANALYZE ledger_journals, ledger_postings, ledger_open_items, ledger_allocations, ledger_revaluations, ledger_finance_contracts, ledger_finance_events, ledger_inventory_moves');
        $setupSeconds = (hrtime(true) - $setup) / 1e9;
        [$results, $seconds, $concurrentSeconds] = self::concurrent($commands);
        $latencies = [];
        $readLatencies = [];
        $commandCount = 0;
        foreach ($results as $result) {
            foreach ($result['latencies'] as $label => $values) {
                if ($label === 'report') {
                    array_push($readLatencies, ...$values);
                } else {
                    $latencies[$label] ??= [];
                    array_push($latencies[$label], ...$values);
                    $commandCount += count($values);
                }
            }
        }
        $verification = [];
        $plans = [];
        foreach ($fixtures as $fixture) {
            $scope = new Scope($fixture['tenant'], 'benchmark-verifier');
            $ledger = (new Ledger($connection, new PermitAll))->for($scope);
            $db = new ScopedDatabase($connection, $scope, new PermitAll);
            $old = new Snapshot($fixture['book'], '2030-12-31', $fixture['cutoff']);
            $snapshot = $ledger->reports->snapshot($fixture['book'], '2030-12-31');
            $expected = $seed + ($workload === 'journal' ? $iterations * intdiv($workers, $books) : 0);
            if ($ledger->reports->incomeStatement($old, '2026-01-01')['profit']->decimal() !== $seed.'.00'
                || $ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal() !== $expected.'.00'
                || ! $ledger->reports->balanceSheet($snapshot)['difference']->minor->isZero()) {
                throw new RuntimeException('Financial totals or saved numerical cutoff failed verification.');
            }
            if (in_array($workload, ['fifo', 'average'], true)) {
                $balance = $ledger->inventory->balance($snapshot, $fixture['pool']);
                if ($balance->quantity->decimal() !== '1.000000000000' || $balance->value->decimal() !== '1.00') {
                    throw new RuntimeException('Inventory conservation failed.');
                }
            }
            $reportStart = hrtime(true);
            $openItems = $ledger->reports->openItems($snapshot);
            $openItemsMs = (hrtime(true) - $reportStart) / 1e6;
            if (in_array($workload, ['allocation', 'oldest'], true) && $openItems !== []) {
                throw new RuntimeException('Allocated items did not clear.');
            }
            $verification[] = $db->run(Permission::Read, fn (): array => [
                'book' => $fixture['book'], 'seed_cutoff' => $fixture['cutoff'], 'final_cutoff' => $snapshot->throughSequence, 'open_items_ms' => $openItemsMs,
                'journals' => $db->table('ledger_journals')->where('book_id', $fixture['book'])->count(),
                'postings' => $db->table('ledger_postings')->where('book_id', $fixture['book'])->count(),
                'audit_rows_for_tenant' => $db->table('ledger_audit_events')->count(),
            ], $fixture['book']);
            if (! isset($options['plans'])) {
                continue;
            }
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $ledger->reports->trialBalance($snapshot, '2026-01-01');
            $ledger->reports->generalLedger($snapshot, limit: 500);
            $ledger->reports->openItems($snapshot);
            $ledger->reports->openItems($snapshot, $fixture['party']);
            $queries = $connection->getQueryLog();
            $connection->disableQueryLog();
            $plans[] = $db->run(Permission::Read, function () use ($connection, $queries): array {
                $result = [];
                foreach ($queries as $query) {
                    if (str_starts_with(strtolower($query['query']), 'select') && preg_match('/ledger_(postings|allocations|revaluations)/', $query['query']) === 1) {
                        $result[] = ['query' => $query['query'], 'bindings' => $query['bindings'], 'plan' => $connection->select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['query'], $query['bindings'])];
                    }
                }

                return $result;
            }, $fixture['book']);
        }
        foreach ($commands as $command) {
            if ($command['workload'] === 'finance') {
                $ledger = (new Ledger($connection, new PermitAll))->for(new Scope($command['tenant'], 'benchmark-verifier'));
                $position = $ledger->finance->position($ledger->reports->snapshot($command['book'], '2030-12-31'), $command['contract']);
                if (! $position['carrying']->minor->isZero()) {
                    throw new RuntimeException('Finance schedule did not conserve carrying value.');
                }
            }
        }
        $summary = [];
        foreach ($latencies as $label => $values) {
            $summary[$label] = self::distribution($values);
        }
        $revision = new Process(['git', 'rev-parse', 'HEAD'], dirname(__DIR__));
        $revision->mustRun();
        $status = new Process(['git', 'status', '--porcelain'], dirname(__DIR__));
        $status->mustRun();
        fwrite(STDOUT, json_encode([
            'environment' => ['revision' => trim($revision->getOutput()), 'dirty' => trim($status->getOutput()) !== '', 'php' => PHP_VERSION, 'os' => php_uname(), 'xdebug' => extension_loaded('xdebug') ? ini_get('xdebug.mode') : false, 'database' => $environment],
            'inputs' => [...compact('seed', 'iterations', 'workers', 'books', 'workload'), 'analyze_after_seed' => true], 'setup_seconds' => $setupSeconds,
            'writer_elapsed_seconds' => $seconds, 'concurrent_elapsed_seconds' => $concurrentSeconds, 'committed_commands' => $commandCount, 'commands_per_second' => $commandCount / $seconds,
            'latency_ms' => $summary, 'concurrent_report_ms' => self::distribution($readLatencies), 'verified' => $verification,
            'database_bytes' => $connection->selectOne('SELECT pg_database_size(current_database())::text AS bytes'),
            'report_plans' => $plans,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    }

    /** @return array{tenant: string, entity: string, book: string, cash: string, income: string, clearing: string, receivable: string, party: string, pool: string, configuration: string, cutoff: string, seed: int} */
    private static function fixture(Connection $connection, int $seed, int $quantity, string $workload): array
    {
        $tenant = (string) Str::uuid7();
        $ledger = (new Ledger($connection, new PermitAll))->for(new Scope($tenant, 'benchmark-setup'));
        $currency = new Currency('KES', 2);
        $entity = $ledger->catalog->entity('Benchmark');
        $book = $ledger->catalog->book($entity, 'Benchmark', $currency);
        $ledger->periods->open($book, '2026–2030', '2026-01-01', '2030-12-31');
        $cash = $ledger->catalog->account($book, '1000', 'Cash', AccountType::Asset, cashFlow: 'cash');
        $income = $ledger->catalog->account($book, '4000', 'Income', AccountType::Income);
        $clearing = $ledger->catalog->account($book, '2000', 'Clearing', AccountType::Liability);
        $receivable = $ledger->catalog->account($book, '1100', 'Receivable', AccountType::Asset, control: true);
        $party = $ledger->catalog->party($entity, 'benchmark', 'Benchmark');
        $stock = $ledger->catalog->account($book, '1200', 'Stock', AccountType::Asset);
        $pool = $ledger->inventory->pool($book, 'benchmark', 'SKU', $stock, $workload === 'fifo' ? ValuationMethod::Fifo : ValuationMethod::MovingAverage);
        $configuration = $ledger->configurations->install($book, new Pack('benchmark', '1', accounts: [
            ['code' => '1300', 'name' => 'Loan', 'type' => 'asset'], ['code' => '1310', 'name' => 'Allowance', 'type' => 'asset'], ['code' => '5000', 'name' => 'Credit losses', 'type' => 'expense'],
        ], roles: ['cash' => '1000', 'loan' => '1300', 'interest' => '4000', 'ecl.allowance' => '1310', 'ecl.expense' => '5000']));
        $money = Money::of('1', $currency);
        $others = [];
        for ($index = 0; $workload === 'oldest' && $index < 100; $index++) {
            $others[] = $ledger->catalog->party($entity, 'other-'.$index, 'Other '.$index);
        }
        for ($index = 0; $index < $seed; $index++) {
            if ($workload !== 'oldest') {
                $ledger->journals->post(new JournalRequest($book, 'seed-'.$index, '2026-01-01', 'Benchmark seed', [new Posting($cash, Side::Debit, $money), new Posting($income, Side::Credit, $money)]));

                continue;
            }
            $other = $others[$index % 100];
            $invoice = $ledger->journals->post(new JournalRequest($book, 'seed-'.$index, '2026-01-01', 'Benchmark seed invoice', [
                new Posting($receivable, Side::Debit, $money, partyId: $other), new Posting($income, Side::Credit, $money),
            ]))->id;
            $receipt = $ledger->journals->post(new JournalRequest($book, 'seed-receipt-'.$index, '2026-01-01', 'Benchmark seed receipt', [
                new Posting($cash, Side::Debit, $money), new Posting($receivable, Side::Credit, $money, partyId: $other),
            ]))->id;
            [$debit, $credit] = self::openItemPair($connection, $tenant, $book, $invoice, $receipt);
            $ledger->settlements->allocate(new AllocationRequest($book, 'seed-allocate-'.$index, $debit, $credit, $money, '2026-01-01', 'Benchmark seed allocation'));
        }
        if (in_array($workload, ['fifo', 'average'], true)) {
            $ledger->inventory->receive($book, $pool, 'stock', '2026-01-01', new Quantity((string) ($quantity + 1)), Money::of((string) ($quantity + 1), $currency), $clearing, 'Benchmark stock');
        }
        $cutoff = $ledger->reports->snapshot($book, '2030-12-31')->throughSequence;

        return compact('tenant', 'entity', 'book', 'cash', 'income', 'clearing', 'receivable', 'party', 'pool', 'configuration', 'cutoff', 'seed');
    }

    /** @return array{parties: int, cycles: int, journals: int, postings: int, allocations: int, seed_seconds: float, bill_run_seconds_per_document: float, reads: array<string, array{median_ms: float, php_peak_kib: float}>} */
    private static function scale(Connection $connection, int $parties, int $cycles): array
    {
        $tenant = (string) Str::uuid7();
        $scope = new Scope($tenant, 'benchmark-scaling');
        $ledger = (new Ledger($connection, new PermitAll))->for($scope);
        $db = new ScopedDatabase($connection, $scope, new PermitAll);
        $currency = new Currency('KES', 2);
        $entity = $ledger->catalog->entity('Utility');
        $book = $ledger->catalog->book($entity, 'Utility', $currency);
        $ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
        $cash = $ledger->catalog->account($book, '1000', 'Cash', AccountType::Asset, cashFlow: 'cash');
        $revenue = $ledger->catalog->account($book, '4000', 'Water sales', AccountType::Income);
        $receivable = $ledger->catalog->account($book, '1100', 'Customer accounts', AccountType::Asset, control: true);
        $accounts = [];
        for ($index = 0; $index < $parties; $index++) {
            $accounts[] = $ledger->catalog->party($entity, 'ACC-'.$index, 'Account '.$index);
        }
        $amount = static fn (int $index): Money => Money::minor((string) (1000 + $index % 500), $currency);
        $seed = hrtime(true);
        $billSeconds = 0.0;
        $allocations = 0;
        for ($cycle = 1; $cycle <= $cycles; $cycle++) {
            $month = sprintf('2026-%02d', $cycle);
            $bills = static function () use ($book, $accounts, $amount, $receivable, $revenue, $month, $cycle): Generator {
                foreach ($accounts as $index => $party) {
                    yield new JournalRequest($book, 'bill-'.$cycle.'-'.$index, $month.'-01', 'Water bill', [
                        new Posting($receivable, Side::Debit, $amount($index), partyId: $party, dueOn: $month.'-15'), new Posting($revenue, Side::Credit, $amount($index)),
                    ], source: new Source('bill', $cycle.'-'.$index));
                }
            };
            $receipts = static function () use ($book, $accounts, $amount, $receivable, $cash, $month, $cycle): Generator {
                foreach ($accounts as $index => $party) {
                    if ($index % 5 !== 0) {
                        yield new JournalRequest($book, 'receipt-'.$cycle.'-'.$index, $month.'-20', 'Receipt', [
                            new Posting($cash, Side::Debit, $amount($index)), new Posting($receivable, Side::Credit, $amount($index), partyId: $party),
                        ]);
                    }
                }
            };
            $start = hrtime(true);
            $ledger->journals->postRun($book, $bills());
            $billSeconds = (hrtime(true) - $start) / 1e9;
            $received = $ledger->journals->postRun($book, $receipts())->journalIds;
            foreach ($received as $key => $journal) {
                if ((int) substr((string) $key, strrpos((string) $key, '-') + 1) % 10 === 1) {
                    $item = $db->run(Permission::Read, fn (): mixed => $db->table('ledger_open_items')->where('book_id', $book)->where('journal_id', $journal)->value('id'), $book);
                    $allocations += count($ledger->settlements->allocateOldest($book, is_string($item) ? $item : throw new RuntimeException('Missing receipt item.'), $month.'-20', 'Apply receipt'));
                }
            }
            $connection->statement('ANALYZE ledger_journals, ledger_postings, ledger_open_items, ledger_allocations, ledger_sequence_reservations');
        }
        $seedSeconds = (hrtime(true) - $seed) / 1e9;
        $snapshot = $ledger->reports->snapshot($book, '2026-12-31');
        $party = $accounts[intdiv($parties, 2)];
        $sortedParties = $accounts;
        sort($sortedParties, SORT_STRING);
        $middleParty = $sortedParties[intdiv($parties, 2)];
        $middle = null;
        foreach ($ledger->reports->eachOpenItem($snapshot) as $position => $item) {
            if ($position === intdiv($parties * $cycles, 2)) {
                $middle = $item->cursor();
                break;
            }
        }
        $receivableBalance = byAccountBalance($ledger->reports->trialBalance($snapshot), $receivable);
        $aged = Money::minor('0', $currency);
        foreach ($ledger->reports->aging($snapshot)['KES'] ?? [] as $bucket) {
            $aged = $aged->plus($bucket);
        }
        $listed = Money::minor('0', $currency);
        $after = null;
        do {
            $page = $ledger->reports->partyBalances($snapshot, $receivable, limit: 1000, afterPartyId: $after);
            foreach ($page as $balance) {
                $listed = $listed->plus($balance->balance);
                $after = $balance->partyId;
            }
        } while (count($page) === 1000);
        if ($aged->decimal() !== $receivableBalance || $listed->decimal() !== $receivableBalance || ! $ledger->reports->controlReconciliation($snapshot, $receivable)->minor->isZero()) {
            throw new RuntimeException('Aging, party balances and the control account do not reconcile.');
        }
        $reads = [
            'trial_balance' => fn (): mixed => $ledger->reports->trialBalance($snapshot),
            'book_aging' => fn (): mixed => $ledger->reports->aging($snapshot),
            'allowance_exposure' => fn (): mixed => $ledger->reports->exposure($snapshot, $receivable),
            'control_reconciliation' => fn (): mixed => $ledger->reports->controlReconciliation($snapshot, $receivable),
            'open_items_first_page' => fn (): mixed => $ledger->reports->openItems($snapshot, limit: 500),
            'open_items_middle_page' => fn (): mixed => $ledger->reports->openItems($snapshot, limit: 500, after: $middle),
            'party_open_items' => fn (): mixed => $ledger->reports->openItems($snapshot, $party),
            'party_aging' => fn (): mixed => $ledger->reports->aging($snapshot, $party),
            'party_statement' => fn (): mixed => $ledger->reports->partyStatement($snapshot, $receivable, $party),
            'party_balances_first_page' => fn (): mixed => $ledger->reports->partyBalances($snapshot, $receivable, limit: 500, includeZero: true),
            'party_balances_middle_page' => fn (): mixed => $ledger->reports->partyBalances($snapshot, $receivable, limit: 500, afterPartyId: $middleParty, includeZero: true),
        ];
        $measured = [];
        foreach ($reads as $name => $read) {
            $read();
            $times = [];
            $peak = 0;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                memory_reset_peak_usage();
                $base = memory_get_usage();
                $start = hrtime(true);
                $result = $read();
                $times[] = (hrtime(true) - $start) / 1e6;
                $peak = max($peak, memory_get_peak_usage() - $base);
                unset($result);
            }
            sort($times);
            $measured[$name] = ['median_ms' => round($times[2], 2), 'php_peak_kib' => round($peak / 1024, 1)];
        }
        $counts = $db->run(Permission::Read, fn (): array => [
            'journals' => $db->table('ledger_journals')->where('book_id', $book)->count(),
            'postings' => $db->table('ledger_postings')->where('book_id', $book)->count(),
        ], $book);

        return ['parties' => $parties, 'cycles' => $cycles, 'journals' => $counts['journals'], 'postings' => $counts['postings'], 'allocations' => $allocations,
            'seed_seconds' => round($seedSeconds, 1), 'bill_run_seconds_per_document' => $billSeconds / $parties, 'reads' => $measured];
    }

    /** @param array{tenant: string, entity: string, book: string, configuration: string} $fixture */
    private static function contract(Connection $connection, array $fixture, int $iterations, int $worker): string
    {
        $ledger = (new Ledger($connection, new PermitAll))->for(new Scope($fixture['tenant'], 'benchmark-setup'));
        $payments = [];
        for ($index = 1; $index <= $iterations; $index++) {
            $payments[] = ['due_on' => (new DateTimeImmutable('2026-01-01'))->modify('+'.$index.' days')->format('Y-m-d'), 'payment' => '1', 'interest_paid' => '0'];
        }
        $ledger->catalog->dimensionValue($fixture['entity'], 'instrument', 'worker-'.$worker);

        return $ledger->finance->open($fixture['book'], 'loan-'.$worker, 'LOAN-'.$worker, '2026-01-01', 'loan_asset', $fixture['configuration'], [
            'opening' => (string) $iterations, 'rate_per_slot' => '0', 'interest_cash_flow' => 'operating', 'policy_version' => 'benchmark:1', 'basis_reference' => 'synthetic:1', 'payments' => $payments,
        ], dimensions: ['instrument' => 'worker-'.$worker]);
    }

    private static function worker(): void
    {
        $input = fgets(STDIN);
        $command = json_decode($input === false ? '{}' : $input, false, 512, JSON_THROW_ON_ERROR);
        if (! $command instanceof stdClass) {
            throw new RuntimeException('Missing benchmark command.');
        }
        $connection = Database::connection();
        $connection->statement('SET statement_timeout = 60000');
        $ledger = (new Ledger($connection, new PermitAll))->for(new Scope(Row::text($command, 'tenant'), 'benchmark-worker'));
        $book = Row::text($command, 'book');
        $money = Money::of('1', new Currency('KES', 2));
        $workload = Row::text($command, 'workload');
        $latencies = [];
        $measure = static function (string $label, Closure $work) use (&$latencies): void {
            $start = hrtime(true);
            $work();
            $latencies[$label][] = (hrtime(true) - $start) / 1e6;
        };
        $connection->getPdo();
        fwrite(STDOUT, "READY\n");
        fflush(STDOUT);
        if (fgets(STDIN) !== "GO\n") {
            throw new RuntimeException('Missing worker start signal.');
        }
        for ($index = 0; $index < (int) Row::text($command, 'iterations'); $index++) {
            $key = Row::text($command, 'worker').'-'.$index;
            if ($workload === 'reader') {
                $measure('report', function () use ($ledger, $book, $command): void {
                    $report = $ledger->reports->incomeStatement(new Snapshot($book, '2030-12-31', Row::text($command, 'cutoff')), '2026-01-01');
                    if ($report['profit']->decimal() !== Row::text($command, 'seed').'.00') {
                        throw new RuntimeException('Concurrent reader changed a fixed numerical cutoff.');
                    }
                });
            } elseif ($workload === 'journal') {
                $measure('journal', fn () => $ledger->journals->post(new JournalRequest($book, 'post-'.$key, '2026-06-30', 'Benchmark journal', [
                    new Posting(Row::text($command, 'cash'), Side::Debit, $money), new Posting(Row::text($command, 'income'), Side::Credit, $money),
                ])));
            } elseif (in_array($workload, ['fifo', 'average'], true)) {
                $measure('inventory_issue', fn () => $ledger->inventory->issue($book, Row::text($command, 'pool'), 'issue-'.$key, '2026-06-30', new Quantity('1'), Row::text($command, 'clearing'), 'Benchmark issue'));
            } elseif ($workload === 'finance') {
                $date = (new DateTimeImmutable('2026-01-01'))->modify('+'.($index + 1).' days')->format('Y-m-d');
                $measure('finance_period', fn () => $ledger->finance->period($book, Row::text($command, 'contract'), 'period-'.$key, $date, $index + 1, 'Benchmark finance'));
            } else {
                $receivable = Row::text($command, 'receivable');
                $party = Row::text($command, 'party');
                $debitJournal = '';
                $creditJournal = '';
                $measure('invoice', function () use ($ledger, $book, $key, $money, $receivable, $party, $command, &$debitJournal): void {
                    $debitJournal = $ledger->journals->post(new JournalRequest($book, 'invoice-'.$key, '2026-06-30', 'Benchmark invoice', [
                        new Posting($receivable, Side::Debit, $money, partyId: $party), new Posting(Row::text($command, 'clearing'), Side::Credit, $money),
                    ]))->id;
                });
                $measure('receipt', function () use ($ledger, $book, $key, $money, $receivable, $party, $command, &$creditJournal): void {
                    $creditJournal = $ledger->journals->post(new JournalRequest($book, 'receipt-'.$key, '2026-06-30', 'Benchmark receipt', [
                        new Posting(Row::text($command, 'cash'), Side::Debit, $money), new Posting($receivable, Side::Credit, $money, partyId: $party),
                    ]))->id;
                });
                [$debit, $credit] = self::openItemPair($connection, Row::text($command, 'tenant'), $book, $debitJournal, $creditJournal);
                if ($workload === 'oldest') {
                    $measure('allocate_oldest', fn () => $ledger->settlements->allocateOldest($book, $credit, '2026-06-30', 'Benchmark oldest-first allocation'));
                } else {
                    $measure('allocation', fn () => $ledger->settlements->allocate(new AllocationRequest($book, 'allocate-'.$key, $debit, $credit, $money, '2026-06-30', 'Benchmark allocation')));
                }
            }
            if ($connection->transactionLevel() !== 0) {
                throw new RuntimeException('A measured command did not reach its outermost commit.');
            }
        }
        fwrite(STDOUT, json_encode(['latencies' => $latencies, 'finished_at_ns' => hrtime(true)], JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array{string, string} */
    private static function openItemPair(Connection $connection, string $tenant, string $book, string $debitJournal, string $creditJournal): array
    {
        $db = new ScopedDatabase($connection, new Scope($tenant, 'benchmark-worker'), new PermitAll);

        return $db->run(Permission::Read, function () use ($db, $debitJournal, $creditJournal): array {
            $debit = $db->table('ledger_open_items')->where('journal_id', $debitJournal)->first();
            $credit = $db->table('ledger_open_items')->where('journal_id', $creditJournal)->first();
            if (! $debit instanceof stdClass || ! $credit instanceof stdClass) {
                throw new RuntimeException('Missing allocation inputs.');
            }

            return [Row::text($debit, 'id'), Row::text($credit, 'id')];
        }, $book);
    }

    /** @param list<array<string, int|string>> $commands
     * @return array{list<array{latencies: array<string, list<float>>}>, float, float}
     */
    private static function concurrent(array $commands): array
    {
        $workers = [];
        try {
            foreach ($commands as $command) {
                $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if ($process === false) {
                    throw new RuntimeException('Cannot start benchmark worker.');
                }
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 600);
                fwrite($pipes[0], json_encode($command, JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
                if (fgets($pipes[1]) !== "READY\n") {
                    throw new RuntimeException('Benchmark worker failed to start: '.stream_get_contents($pipes[2]));
                }
            }
            $start = hrtime(true);
            foreach ($workers as [$process, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fclose($pipes[0]);
            }
            $results = [];
            $lastWriter = $start;
            foreach ($workers as $index => [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                if (proc_close($process) !== 0 || $output === false) {
                    throw new RuntimeException('Benchmark worker failed: '.$error);
                }
                $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! is_array($decoded['latencies'] ?? null) || ! is_int($decoded['finished_at_ns'] ?? null)) {
                    throw new RuntimeException('Invalid benchmark worker result.');
                }
                $latencies = [];
                foreach ($decoded['latencies'] as $label => $values) {
                    if (! is_string($label) || ! is_array($values) || ! array_is_list($values) || $values === []) {
                        throw new RuntimeException('Invalid benchmark latency series.');
                    }
                    foreach ($values as $value) {
                        if ((! is_float($value) && ! is_int($value)) || $value < 0) {
                            throw new RuntimeException('Invalid benchmark latency.');
                        }
                        $latencies[$label][] = (float) $value;
                    }
                }
                if ($commands[$index]['workload'] !== 'reader') {
                    $lastWriter = max($lastWriter, $decoded['finished_at_ns']);
                }
                $results[] = ['latencies' => $latencies];
            }
            $workers = [];

            return [$results, ($lastWriter - $start) / 1e9, (hrtime(true) - $start) / 1e9];
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

    /** @param list<float> $values
     * @return array{count: int, p50: float, p95: float, p99: float, max: float}
     */
    private static function distribution(array $values): array
    {
        if ($values === []) {
            throw new RuntimeException('No measured commands.');
        }
        sort($values, SORT_NUMERIC);
        $percentile = static fn (float $fraction): float => $values[(int) ceil(count($values) * $fraction) - 1];

        return ['count' => count($values), 'p50' => $percentile(0.5), 'p95' => $percentile(0.95), 'p99' => $percentile(0.99), 'max' => max($values)];
    }
}

LedgerBenchmark::main();
