<?php

declare(strict_types=1);

namespace Noria\Ledger\Assets;

use JsonSerializable;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Scheduling\ScheduleLine;
use Noria\Ledger\Support\Assert;

final readonly class AssetRequest implements JsonSerializable
{
    public Money $openingDepreciation;

    public Money $openingImpairment;

    public Money $openingSurplus;

    public Money $openingLoss;

    /** @param list<ScheduleLine> $plan
     * @param  array<string, string>  $dimensions
     */
    public function __construct(public string $bookId, public string $key, public string $reference, public string $effectiveOn,
        public string $availableOn, public Money $cost, public Money $residual, public array $plan,
        public string $costAccountId, public string $depreciationAccountId, public string $impairmentAccountId,
        public string $expenseAccountId, public string $counterAccountId, public string $policyVersion,
        public array $dimensions = [], ?Money $openingDepreciation = null, ?Money $openingImpairment = null,
        public StubConvention $stubConvention = StubConvention::ActualDays, ?Money $openingSurplus = null, ?Money $openingLoss = null, public ?string $surplusAccountId = null)
    {
        Assert::uuid($bookId);
        Assert::text($key, 128);
        Assert::text($reference, 128);
        Assert::text($policyVersion, 128);
        Assert::date($effectiveOn);
        Assert::date($availableOn);
        $this->openingDepreciation = $openingDepreciation ?? Money::minor('0', $cost->currency);
        $this->openingImpairment = $openingImpairment ?? Money::minor('0', $cost->currency);
        $this->openingSurplus = $openingSurplus ?? Money::minor('0', $cost->currency);
        $this->openingLoss = $openingLoss ?? $this->openingImpairment;
        $cost->sameCurrency($this->openingSurplus);
        $cost->sameCurrency($this->openingLoss);
        Assert::that(! $this->openingSurplus->minor->isNegative() && ! $this->openingLoss->minor->isNegative()
            && ($this->openingSurplus->minor->isZero() || $surplusAccountId !== null), 'Invalid opening surplus or profit-or-loss recovery balance.');
        if ($surplusAccountId !== null) {
            Assert::uuid($surplusAccountId);
        }
        $remaining = $cost->minus($this->openingDepreciation)->minus($this->openingImpairment)->minus($residual);
        Assert::that($cost->minor->isPositive() && ! $remaining->minor->isNegative() && ! $residual->minor->isNegative()
            && ! $this->openingDepreciation->minor->isNegative() && ! $this->openingImpairment->minor->isNegative(), 'Invalid asset cost, residual, or opening balances.');
        $sum = Money::minor('0', $cost->currency);

        foreach ($plan as $line) {
            $sum = $sum->plus($line->amount);
        }

        Assert::that($sum->minor->isEqualTo($remaining->minor), 'Asset plan must equal its remaining depreciable amount.');
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $result = ['book_id' => $this->bookId, 'reference' => $this->reference, 'effective_on' => $this->effectiveOn,
            'available_on' => $this->availableOn, 'cost' => $this->cost, 'residual' => $this->residual, 'plan' => $this->plan,
            'cost_account_id' => $this->costAccountId, 'depreciation_account_id' => $this->depreciationAccountId,
            'impairment_account_id' => $this->impairmentAccountId, 'expense_account_id' => $this->expenseAccountId,
            'counter_account_id' => $this->counterAccountId, 'policy_version' => $this->policyVersion, 'dimensions' => $this->dimensions,
            'opening_depreciation' => $this->openingDepreciation, 'opening_impairment' => $this->openingImpairment, 'stub_convention' => $this->stubConvention->value];
        if (! $this->openingSurplus->minor->isZero() || ! $this->openingLoss->minor->isEqualTo($this->openingImpairment->minor) || $this->surplusAccountId !== null) {
            $result += ['opening_surplus' => $this->openingSurplus, 'opening_loss' => $this->openingLoss, 'surplus_account_id' => $this->surplusAccountId];
        }

        return $result;
    }
}
