<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class DeferredTaxPosition
{
    /** @param array<array-key, mixed> $inputs
     * @param  array<array-key, mixed>  $policy
     */
    public function __construct(public string $id, public string $bookId, public string $configurationId, public string $source,
        public string $effectiveOn, public string $throughSequence, public string $sequence, public string $recordedAt, public string $actorId, public ?string $firmId, public ?string $priorId,
        public array $inputs, public array $policy, public string $resultJson, public string $resultHash,
        public Money $carrying, public Money $taxBase, public Money $capitalAllowance, public Money $temporaryDifference,
        public Money $unrecognizedAsset, public Money $openingAsset, public Money $openingLiability,
        public Money $asset, public Money $liability, public Money $charge, public Money $openingMovement) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $r = (object) Row::json($row, 'result');

        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'configuration_id'), Row::text($row, 'source'),
            Row::text($row, 'effective_on'), Row::text($row, 'through_sequence'), Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'), Row::nullable($r, 'prior_id'),
            Row::json($row, 'inputs'), Row::json($row, 'policy'), Row::text($row, 'result'), Row::text($row, 'result_hash'),
            Money::minor(Row::text($r, 'carrying_minor'), $currency), Money::minor(Row::text($r, 'tax_base_minor'), $currency),
            Money::minor(Row::text($r, 'allowance_minor'), $currency), Money::minor(Row::text($r, 'difference_minor'), $currency),
            Money::minor(Row::text($r, 'unrecognized_asset_minor'), $currency), Money::minor(Row::text($r, 'opening_asset_minor'), $currency),
            Money::minor(Row::text($r, 'opening_liability_minor'), $currency), Money::minor(Row::text($r, 'asset_minor'), $currency),
            Money::minor(Row::text($r, 'liability_minor'), $currency), Money::minor(Row::text($r, 'charge_minor'), $currency),
            Money::minor(Row::text($r, 'opening_movement_minor'), $currency));
    }
}
