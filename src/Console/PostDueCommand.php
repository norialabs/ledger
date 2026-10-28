<?php

declare(strict_types=1);

namespace Noria\Ledger\Console;

use Illuminate\Console\Command;
use Noria\Ledger\Catalog\Book;

final class PostDueCommand extends Command
{
    use ResolvesTenant;

    protected $signature = 'ledger:post-due
        {--tenant= : Ledger tenant UUID}
        {--book=* : Book UUIDs; every readable book of the tenant when omitted}
        {--date= : Post work due on or before this date (defaults to today)}';

    protected $description = 'Post due recurring journals, auto-reversals, schedule lines and depreciation';

    public function handle(): int
    {
        $tenant = $this->stringOption('tenant');
        if ($tenant === null) {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }
        $date = $this->stringOption('date') ?? date('Y-m-d');
        $ledger = $this->session($tenant);
        $books = array_values(array_filter((array) $this->option('book'), 'is_string'));
        if ($books === []) {
            $books = array_map(fn (Book $book): string => $book->id, $ledger->catalog->books());
        }
        $rows = [];
        foreach ($books as $book) {
            $recurring = count($ledger->schedules->postRecurring($book, $date));
            $scheduled = 0;
            foreach (array_unique(array_column($ledger->schedules->dueSchedules($book, $date), 'schedule_id')) as $schedule) {
                $scheduled += count($ledger->schedules->postDue($book, $schedule, $date));
            }
            $depreciation = array_sum(array_map('count', $ledger->assets->depreciateDue($book, $date)));
            $rows[] = [$book, $recurring, $scheduled, $depreciation];
        }
        $this->table(['Book', 'Recurring and reversals', 'Schedule lines', 'Depreciation events'], $rows);

        return self::SUCCESS;
    }
}
