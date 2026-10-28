<?php

declare(strict_types=1);

namespace Noria\Ledger\Console;

use Illuminate\Console\Command;

final class ChecklistCommand extends Command
{
    use ResolvesTenant;

    protected $signature = 'ledger:checklist
        {--tenant= : Ledger tenant UUID}
        {--book= : Book UUID}
        {--from= : First day of the closing range}
        {--to= : Last day of the closing range}';

    protected $description = 'Report the closing checklist for a book and fail when any check fails';

    public function handle(): int
    {
        $tenant = $this->stringOption('tenant');
        $book = $this->stringOption('book');
        $from = $this->stringOption('from');
        $to = $this->stringOption('to');
        if ($tenant === null || $book === null || $from === null || $to === null) {
            $this->error('--tenant, --book, --from and --to are required.');

            return self::FAILURE;
        }
        $checklist = $this->session($tenant)->yearEnd->checklist($book, $from, $to);
        $this->table(['Check', 'Status', 'Count', 'First items'], array_map(fn (array $check): array => [
            $check['check'], $check['status'], $check['count'], implode(', ', array_slice($check['items'], 0, 3)),
        ], $checklist['checks']));
        $this->line($checklist['ready'] ? 'Ready to close.' : 'Not ready to close.');

        return $checklist['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
