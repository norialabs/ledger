<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class SubmissionDefinition
{
    public function __construct(
        public string $id,
        public string $bookId,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $sequence,
        public string $recordedAt,
        public string $actorId,
        public ?string $firmId,
        public string $kind,
        public ?string $journalId,
        public ?string $returnId,
        public ?string $originalId,
        public ?string $replacesId,
        public string $channel,
        public string $environment,
        public string $adapter,
        public string $adapterVersion,
        public string $requestReference,
        public string $contentType,
        public string $payload,
        public string $payloadHash,
        public string $sourceHash,
        public Money $gross,
        public int $maxAttempts,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'kind'), Row::nullable($row, 'journal_id'), Row::nullable($row, 'return_id'), Row::nullable($row, 'original_id'),
            Row::nullable($row, 'replaces_id'), Row::text($row, 'channel'), Row::text($row, 'environment'), Row::text($row, 'adapter'),
            Row::text($row, 'adapter_version'), Row::text($row, 'request_reference'), Row::text($row, 'content_type'), Row::text($row, 'payload'),
            Row::text($row, 'payload_hash'), Row::text($row, 'source_hash'),
            Money::minor(Row::text($row, 'gross_minor'), new Currency(Row::text($row, 'source_currency'), (int) Row::text($row, 'source_scale'))),
            (int) Row::text($row, 'max_attempts'));
    }
}
