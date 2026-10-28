<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

use Noria\Ledger\Support\Row;
use stdClass;

final readonly class SubmissionEvent
{
    /** @param array<array-key, mixed> $evidence */
    public function __construct(
        public string $id,
        public string $bookId,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $sequence,
        public string $recordedAt,
        public string $actorId,
        public ?string $firmId,
        public string $submissionId,
        public string $kind,
        public ?string $mode,
        public ?string $claimId,
        public ?int $leaseSeconds,
        public ?string $leaseExpiresAt,
        public int $delaySeconds,
        public string $availableAt,
        public ?string $receiptReference,
        public string $channel,
        public string $environment,
        public array $evidence,
        public string $reason,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        $lease = Row::nullable($row, 'lease_seconds');

        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'submission_id'), Row::text($row, 'kind'), Row::nullable($row, 'mode'), Row::nullable($row, 'claim_id'),
            $lease === null ? null : (int) $lease, Row::nullable($row, 'lease_expires_at'), (int) Row::text($row, 'delay_seconds'),
            Row::text($row, 'available_at'), Row::nullable($row, 'receipt_reference'), Row::text($row, 'channel'), Row::text($row, 'environment'),
            Row::json($row, 'evidence'), Row::text($row, 'reason'));
    }
}
