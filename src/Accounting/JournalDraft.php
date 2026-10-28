<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

final readonly class JournalDraft
{
    public function __construct(
        public string $id,
        public string $bookId,
        public string $status,
        public JournalRequest $request,
        public ?string $supersedesId,
        public string $preparedBy,
        public ?string $preparedFirm,
        public string $preparedAt,
        public ?string $decidedBy = null,
        public ?string $decidedAt = null,
        public ?string $reason = null,
        public ?string $journalId = null,
    ) {}
}
