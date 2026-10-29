<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class IncomeTaxComputation
{
    /** @param array<array-key, mixed> $inputs
     * @param  array<array-key, mixed>  $policy
     */
    public function __construct(public string $id, public string $bookId, public string $configurationId, public string $startsOn, public string $endsOn,
        public string $source, public string $throughSequence, public string $sequence, public string $recordedAt, public string $actorId, public ?string $firmId,
        public array $inputs, public array $policy, public string $resultJson, public string $resultHash,
        public Money $accountingProfit, public Money $permanentAdjustments, public Money $temporaryAdjustments, public Money $capitalAllowances,
        public Money $adjustedProfit, public Money $lossUsed, public Money $taxableProfit, public Money $taxLoss, public Money $charge, public Money $credits, public Money $payable) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $r = (object) Row::json($row, 'result');

        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'configuration_id'), Row::text($row, 'starts_on'), Row::text($row, 'ends_on'),
            Row::text($row, 'source'), Row::text($row, 'through_sequence'), Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::json($row, 'inputs'), Row::json($row, 'policy'), Row::text($row, 'result'), Row::text($row, 'result_hash'),
            Money::minor(Row::text($r, 'accounting_profit_minor'), $currency), Money::minor(Row::text($r, 'permanent_minor'), $currency),
            Money::minor(Row::text($r, 'temporary_minor'), $currency), Money::minor(Row::text($r, 'capital_allowances_minor'), $currency),
            Money::minor(Row::text($r, 'adjusted_profit_minor'), $currency), Money::minor(Row::text($r, 'loss_used_minor'), $currency),
            Money::minor(Row::text($r, 'taxable_profit_minor'), $currency), Money::minor(Row::text($r, 'tax_loss_minor'), $currency),
            Money::minor(Row::text($r, 'charge_minor'), $currency), Money::minor(Row::text($r, 'credits_minor'), $currency), Money::minor(Row::text($r, 'payable_minor'), $currency));
    }
}
