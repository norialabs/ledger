<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

final readonly class JournalRun
{
    /** @param non-empty-array<string, string> $journalIds */
    public function __construct(
        public string $bookId,
        public array $journalIds,
        public int $posted,
        public int $replayed,
        public string $afterSequence,
        public string $throughSequence,
    ) {}
}
