<?php

declare(strict_types=1);

namespace Noria\Ledger\Associates;

use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Associate
{
    /** @param array<string, string> $dimensions */
    public function __construct(
        public string $id,
        public string $bookId,
        public string $idempotencyKey,
        public string $fingerprint,
        public string $sequence,
        public string $recordedAt,
        public string $actorId,
        public ?string $firmId,
        public string $investeeEntityId,
        public string $reference,
        public string $effectiveOn,
        public string $ownershipFraction,
        public Money $cost,
        public Money $fairValueNetAssets,
        public Money $carrying,
        public Money $goodwill,
        public Money $bargain,
        public string $investmentAccountId,
        public string $fundingAccountId,
        public string $profitAccountId,
        public string $ociAccountId,
        public string $dividendAccountId,
        public string $impairmentAccountId,
        public ?string $lossLiabilityAccountId,
        public string $influenceBasis,
        public string $policyVersion,
        public string $dividendCashFlow,
        public array $dimensions,
        public ?string $journalId,
    ) {}

    public static function fromRow(stdClass $row, Currency $currency): self
    {
        $money = fn (string $key): Money => Money::minor(Row::text($row, $key), $currency);

        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'idempotency_key'), Row::text($row, 'fingerprint'),
            Row::text($row, 'sequence'), Row::text($row, 'recorded_at'), Row::text($row, 'actor_id'), Row::nullable($row, 'firm_id'),
            Row::text($row, 'investee_entity_id'), Row::text($row, 'reference'), Row::text($row, 'effective_on'), Row::text($row, 'ownership_fraction'),
            $money('cost_minor'), $money('fair_value_net_assets_minor'), $money('carrying_minor'), $money('goodwill_minor'), $money('bargain_minor'),
            Row::text($row, 'investment_account_id'), Row::text($row, 'funding_account_id'), Row::text($row, 'profit_account_id'),
            Row::text($row, 'oci_account_id'), Row::text($row, 'dividend_account_id'), Row::text($row, 'impairment_account_id'),
            Row::nullable($row, 'loss_liability_account_id'), Row::text($row, 'influence_basis'), Row::text($row, 'policy_version'),
            Row::text($row, 'dividend_cash_flow'), Row::dimensions($row), Row::nullable($row, 'journal_id'));
    }
}
