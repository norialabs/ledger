<?php

declare(strict_types=1);

namespace Noria\Ledger\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Noria\Ledger\Configuration\StarterChart;
use Noria\Ledger\Money\Currency;

final class InstallCommand extends Command
{
    use ResolvesTenant;

    protected $signature = 'ledger:install
        {--tenant= : Ledger tenant UUID; a new one is generated when omitted}
        {--entity= : Legal entity name}
        {--book=General ledger : Book name}
        {--currency= : Functional currency code}
        {--country= : Two-letter country code}
        {--year= : First fiscal year, e.g. 2026}
        {--start=01-01 : Fiscal year start as MM-DD}
        {--years=1 : Number of fiscal years of monthly periods to open}
        {--without-chart : Do not install the starter chart of accounts}';

    protected $description = 'Create a legal entity, book, monthly periods and the starter chart of accounts';

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $tenant = is_string($tenant) && $tenant !== '' ? $tenant : (string) Str::uuid7();
        $entityName = $this->stringOption('entity') ?? $this->ask('Legal entity name');
        $country = strtoupper($this->stringOption('country') ?? $this->configString('ledger.defaults.country', 'KE'));
        $code = strtoupper($this->stringOption('currency') ?? $this->configString('ledger.defaults.currency', 'KES'));
        $year = (int) ($this->stringOption('year') ?? date('Y'));
        $start = $this->stringOption('start') ?? '01-01';
        $years = max(1, (int) ($this->stringOption('years') ?? '1'));
        if (! is_string($entityName) || trim($entityName) === '' || preg_match('/^\d{2}-\d{2}$/D', $start) !== 1) {
            $this->error('An entity name and a fiscal start in MM-DD format are required.');

            return self::FAILURE;
        }

        $ledger = $this->session($tenant);
        $entity = $ledger->catalog->entity($entityName, $country);
        $book = $ledger->catalog->book($entity, $this->stringOption('book') ?? 'General ledger', Currency::iso($code));
        $from = new DateTimeImmutable($year.'-'.$start);
        for ($month = 0; $month < 12 * $years; $month++) {
            $periodStart = $from->modify('+'.$month.' months');
            $periodEnd = $periodStart->modify('+1 month')->modify('-1 day');
            $ledger->periods->open($book, $periodStart->format('Y-m'), $periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d'));
        }
        $configuration = $this->option('without-chart') ? null : $ledger->configurations->install($book, StarterChart::pack());

        $this->table(['Item', 'Identifier'], array_filter([
            ['Tenant', $tenant], ['Legal entity', $entity], ['Book', $book], $configuration === null ? null : ['Starter chart configuration', $configuration],
        ]));

        return self::SUCCESS;
    }
}
