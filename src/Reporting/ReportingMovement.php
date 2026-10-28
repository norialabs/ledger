<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use JsonSerializable;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;

final readonly class ReportingMovement implements JsonSerializable
{
    public const KINDS = ['oci', 'oci_opening', 'recycling', 'contribution', 'distribution', 'transfer', 'basis_adjustment', 'opening_balance', 'opening_adjustment', 'earnings_transfer'];

    public function __construct(
        public string $kind,
        public string $policyVersion,
        public string $reference,
        public ?string $ociCategory = null,
        public ?string $sourceJournalId = null,
        public ?int $sourceLineNo = null,
    ) {
        Assert::that(in_array($kind, self::KINDS, true), 'Unknown reporting movement kind.');
        Assert::text($policyVersion, 128);
        Assert::text($reference, 1024);
        Assert::that(in_array($kind, ['oci', 'oci_opening', 'recycling', 'basis_adjustment'], true)
            ? in_array($ociCategory, ReportingMapping::OCI, true)
            : $ociCategory === null, 'Reporting movement OCI category is missing or incompatible.');
        if (in_array($kind, ['recycling', 'basis_adjustment'], true)) {
            Assert::that($ociCategory === 'recyclable' && $sourceJournalId !== null && $sourceLineNo !== null && $sourceLineNo >= 1 && $sourceLineNo <= 10000, 'Recycling requires a recyclable original OCI posting.');
            Assert::uuid($sourceJournalId);
        } else {
            Assert::that($sourceJournalId === null && $sourceLineNo === null, 'Only recycling or a basis adjustment references an original OCI posting.');
        }
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        Assert::that(array_diff(array_keys($data), ['kind', 'policy_version', 'reference', 'oci_category', 'source_journal_id', 'source_line_no']) === [], 'Unknown reporting movement field.');
        $line = $data['source_line_no'] ?? null;
        Assert::that($line === null || is_int($line), 'Reporting source line must be an integer.');
        $row = (object) $data;

        return new self(Row::text($row, 'kind'), Row::text($row, 'policy_version'), Row::text($row, 'reference'),
            Row::nullable($row, 'oci_category'), Row::nullable($row, 'source_journal_id'), $line);
    }

    /** @return array{kind: string, policy_version: string, reference: string, oci_category: ?string, source_journal_id: ?string, source_line_no: ?int} */
    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind, 'policy_version' => $this->policyVersion, 'reference' => $this->reference,
            'oci_category' => $this->ociCategory, 'source_journal_id' => $this->sourceJournalId, 'source_line_no' => $this->sourceLineNo];
    }
}
