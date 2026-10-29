<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class IncomeTaxRequest
{
    /** @param list<array{kind: string, reference: string, amount_minor: string}> $adjustments
     * @param  array<string, string>  $losses
     * @param  list<array{journal_id: string, line_no: int, amount_minor: string, kind: string, reference: string}>  $credits
     */
    public function __construct(public Snapshot $snapshot, public string $key, public string $configurationId, public string $startsOn,
        public string $source, public array $adjustments, public array $losses, public array $credits, public string $basisReference)
    {
        Assert::text($key, 128);
        Assert::uuid($configurationId);
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Income tax year ends before it starts.');
        Assert::text($source, 64);
        Assert::text($basisReference, 256);
        Assert::that(array_is_list($adjustments) && array_is_list($credits), 'Tax adjustments and credits must be ordered lists.');
        Canonical::json($this->inputs());
    }

    /** @return array{adjustments: list<array{kind: string, reference: string, amount_minor: string}>, losses: array<string, string>, credits: list<array{journal_id: string, line_no: int, amount_minor: string, kind: string, reference: string}>, basis_reference: string} */
    public function inputs(): array
    {
        return ['adjustments' => $this->adjustments, 'losses' => $this->losses, 'credits' => $this->credits, 'basis_reference' => $this->basisReference];
    }
}
