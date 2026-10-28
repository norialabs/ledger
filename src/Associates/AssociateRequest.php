<?php

declare(strict_types=1);

namespace Noria\Ledger\Associates;

use Brick\Math\BigDecimal;
use JsonSerializable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

final readonly class AssociateRequest implements JsonSerializable
{
    public string $ownershipFraction;

    /** @param array<string, string> $dimensions */
    public function __construct(public string $bookId, public string $key, public string $reference, public string $investeeEntityId,
        public string $effectiveOn, public Money $cost, public Money $fairValueNetAssets, string $ownershipFraction,
        public string $investmentAccountId, public string $fundingAccountId, public string $profitAccountId,
        public string $ociAccountId, public string $dividendAccountId, public string $impairmentAccountId,
        public string $influenceBasis, public string $policyVersion, public ?string $lossLiabilityAccountId = null,
        public string $dividendCashFlow = 'investing', public array $dimensions = [])
    {
        foreach ([$bookId, $investeeEntityId, $investmentAccountId, $fundingAccountId, $profitAccountId, $ociAccountId, $dividendAccountId, $impairmentAccountId] as $id) {
            Assert::uuid($id);
        }
        if ($lossLiabilityAccountId !== null) {
            Assert::uuid($lossLiabilityAccountId);
        }
        Assert::date($effectiveOn);
        Assert::text($key, 128);
        Assert::text($reference, 128);
        Assert::text($influenceBasis, 1024);
        Assert::text($policyVersion, 128);
        $fraction = BigDecimal::of(Assert::decimal($ownershipFraction))->toScale(18);
        Assert::that($fraction->isPositive() && $fraction->isLessThan(1), 'An associate requires an ownership fraction strictly between zero and one and a separate significant-influence assessment.');
        $this->ownershipFraction = (string) $fraction;
        $cost->sameCurrency($fairValueNetAssets);
        Assert::that(! $cost->minor->isNegative(), 'Associate acquisition cost cannot be negative.');
        Assert::that(in_array($dividendCashFlow, ['operating', 'investing'], true), 'Associate dividend cash flow must use an explicit operating or investing policy.');
        foreach ($dimensions as $name => $value) {
            Assert::text($name, 64);
            Assert::text($value, 128);
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'reference' => $this->reference, 'investee_entity_id' => $this->investeeEntityId, 'effective_on' => $this->effectiveOn,
            'cost' => $this->cost, 'fair_value_net_assets' => $this->fairValueNetAssets, 'ownership_fraction' => $this->ownershipFraction,
            'investment_account_id' => $this->investmentAccountId, 'funding_account_id' => $this->fundingAccountId, 'profit_account_id' => $this->profitAccountId,
            'oci_account_id' => $this->ociAccountId, 'dividend_account_id' => $this->dividendAccountId, 'impairment_account_id' => $this->impairmentAccountId,
            'loss_liability_account_id' => $this->lossLiabilityAccountId, 'influence_basis' => $this->influenceBasis, 'policy_version' => $this->policyVersion,
            'dividend_cash_flow' => $this->dividendCashFlow, 'dimensions' => $this->dimensions];
    }
}
