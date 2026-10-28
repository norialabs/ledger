<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tax\TaxReturn;
use stdClass;

/**
 * @phpstan-import-type Result from TaxReturn
 *
 * @phpstan-type SourcePosting array{account_id: string, side: string, amount_minor: string, functional_minor: string, currency_code: string, rate: string, rate_as_of: string, rate_source: string, rounding: string, party_id: ?string, due_on: ?string, dimensions: array<string, string>, description: string, valuation: bool, reporting_movement?: array{kind: string, policy_version: string, reference: string, oci_category: ?string, source_journal_id: ?string, source_line_no: ?int}}
 * @phpstan-type Source array{kind: 'invoice'|'credit_note', currency: string, scale: int, gross_minor: string, journal_id: string, effective_on: string, source_type: string, source_reference: string, postings: list<SourcePosting>, metadata: array<string, mixed>, reverses_id: ?string}|array{kind: 'tax_return', currency: string, scale: int, gross_minor: string, return_id: string, starts_on: string, ends_on: string, through_sequence: string, configuration_id: string, result_hash: string, result: Result}
 */
final readonly class Submission
{
    /** @param Source $source */
    public function __construct(public string $tenantId, public string $bookId, public string $id, public string $claimId, public string $mode,
        public string $channel, public string $environment, public string $adapter, public string $adapterVersion, public string $requestReference,
        public string $contentType, public string $payload, public string $payloadHash, public string $sourceHash, public array $source,
        public ?string $originalReceipt, public int $attempts) {}

    /** @return Source */
    public static function source(string $json): array
    {
        $source = Row::object(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $common = ['currency' => Row::text($source, 'currency'), 'scale' => (int) Row::text($source, 'scale'), 'gross_minor' => Row::text($source, 'gross_minor')];
        if (Row::text($source, 'kind') === 'tax_return') {
            return [...$common, 'kind' => 'tax_return', 'return_id' => Row::text($source, 'return_id'), 'starts_on' => Row::text($source, 'starts_on'),
                'ends_on' => Row::text($source, 'ends_on'), 'through_sequence' => Row::text($source, 'through_sequence'),
                'configuration_id' => Row::text($source, 'configuration_id'), 'result_hash' => Row::text($source, 'result_hash'),
                'result' => TaxReturn::result(Row::object($source->result), new Currency($common['currency'], $common['scale']))];
        }
        $kind = Row::text($source, 'kind');
        Assert::that(in_array($kind, ['invoice', 'credit_note'], true), 'Unknown statutory source kind.');

        return [...$common, 'kind' => $kind, 'journal_id' => Row::text($source, 'journal_id'), 'effective_on' => Row::text($source, 'effective_on'),
            'source_type' => Row::text($source, 'source_type'), 'source_reference' => Row::text($source, 'source_reference'),
            'postings' => Row::objects($source->postings, self::posting(...)),
            'metadata' => Row::map($source->metadata, fn (mixed $v): mixed => $v), 'reverses_id' => Row::nullable($source, 'reverses_id')];
    }

    /** @return SourcePosting */
    private static function posting(stdClass $r): array
    {
        $result = ['account_id' => Row::text($r, 'account_id'), 'side' => Row::text($r, 'side'), 'amount_minor' => Row::text($r, 'amount_minor'),
            'functional_minor' => Row::text($r, 'functional_minor'), 'currency_code' => Row::text($r, 'currency_code'), 'rate' => Row::text($r, 'rate'),
            'rate_as_of' => Row::text($r, 'rate_as_of'), 'rate_source' => Row::text($r, 'rate_source'), 'rounding' => Row::text($r, 'rounding'),
            'party_id' => Row::nullable($r, 'party_id'), 'due_on' => Row::nullable($r, 'due_on'), 'dimensions' => Row::stringMap($r->dimensions),
            'description' => Row::text($r, 'description'), 'valuation' => Row::boolean($r, 'valuation')];
        if (isset($r->reporting_movement)) {
            $result['reporting_movement'] = ReportingMovement::fromArray(get_object_vars(Row::object($r->reporting_movement)))->jsonSerialize();
        }

        return $result;
    }
}
