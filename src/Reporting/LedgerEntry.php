<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalLine;

final readonly class LedgerEntry
{
    public function __construct(public string $id, public string $tenantId, public string $createdAt, public Journal $journal, public JournalLine $line) {}
}
