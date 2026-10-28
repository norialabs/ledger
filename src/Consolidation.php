<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Consolidation\Investment;
use Noria\Ledger\Consolidation\Member;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Consolidation
{
    public function __construct(private ScopedDatabase $db, private Reports $reports) {}

    /** @param non-empty-list<Member> $members
     * @param  list<Investment>|null  $investments
     */
    public function run(Snapshot $target, string $key, string $startsOn, array $members, string $translationReserveId, string $roundingReserveId, string $policyVersion, ?string $nciAccountId = null, ?array $investments = null, ?string $retainedEarningsAccountId = null): string
    {
        Assert::text($key, 128);
        Assert::text($policyVersion, 128);
        Assert::date($startsOn);
        Assert::that($startsOn <= $target->effectiveOn && $members !== [], 'Consolidation requires members and a valid reporting period.');
        Assert::that($translationReserveId !== $roundingReserveId, 'Translation and rounding adjustments require separate accounts.');

        if (in_array(true, array_map(fn (Member $member): bool => $member->ownershipFraction !== '1', $members), true)) {
            Assert::that($nciAccountId !== null, 'Non-controlling interest requires an explicit NCI equity account.');
        }

        Assert::that(($nciAccountId === null && ($investments ?? []) === []) || $retainedEarningsAccountId !== null, 'Acquisition and NCI accounting require an explicit retained earnings account.');

        return $this->db->run(Permission::Consolidate, function () use ($target, $key, $startsOn, $members, $translationReserveId, $roundingReserveId, $policyVersion, $nciAccountId, $investments, $retainedEarningsAccountId): string {
            $investments = $investments ?? [];
            $bookIds = [$target->bookId];

            foreach ($members as $member) {
                Assert::that($member->snapshot->effectiveOn === $target->effectiveOn && ! in_array($member->snapshot->bookId, $bookIds, true), 'Each source book must be distinct and use the same report date.');
                $bookIds[] = $member->snapshot->bookId;
            }

            $memberByBook = [];
            foreach ($members as $member) {
                $memberByBook[$member->snapshot->bookId] = $member;
            }

            $eliminated = [];
            $investmentAccounts = [];
            $goodwillAccounts = [];
            foreach ($investments as $investment) {
                Assert::that(isset($memberByBook[$investment->investeeBookId]), 'Investment elimination requires a consolidated investee member.');
                Assert::that(! isset($eliminated[$investment->investeeBookId]), 'Each investee can only be eliminated once.');
                Assert::that(! isset($investmentAccounts[$investment->investmentAccountId]), 'Each investment requires a dedicated elimination account.');
                $eliminated[$investment->investeeBookId] = $investment;
                $investmentAccounts[$investment->investmentAccountId] = true;
                $goodwillAccounts[$investment->goodwillAccountId] = true;
            }

            sort($bookIds, SORT_STRING);
            $books = [];

            foreach ($bookIds as $bookId) {
                $this->db->authorize(Permission::Read, $bookId);
                $books[$bookId] = $this->db->lockBook($bookId);
            }

            $fingerprint = Canonical::hash([$target, $startsOn, $members, $translationReserveId, $roundingReserveId, $policyVersion, $nciAccountId, $investments, $retainedEarningsAccountId]);
            $existing = $this->db->table('ledger_consolidation_runs')->where('book_id', $target->bookId)->where('idempotency_key', $key)->first();

            if ($existing instanceof stdClass) {
                $legacyFingerprint = null;
                if ($nciAccountId === null && $investments === [] && $retainedEarningsAccountId === null
                    && array_filter($members, fn (Member $member): bool => $member->ownershipFraction !== '1') === []) {
                    $legacyMembers = array_map(fn (Member $member): array => array_diff_key($member->jsonSerialize(), ['ownership_fraction' => true]), $members);
                    $legacyFingerprint = Canonical::hash([$target, $startsOn, $legacyMembers, $translationReserveId, $roundingReserveId, $policyVersion]);
                }
                Assert::that(in_array(Row::text($existing, 'fingerprint'), [$fingerprint, $legacyFingerprint], true), 'Consolidation key was already used with different inputs.');

                return Row::text($existing, 'id');
            }

            $currency = $this->db->currency(Row::text($books[$target->bookId], 'currency_code'));
            $accounts = [];
            $closing = [];
            $period = [];
            $accountExact = [];

            foreach ($this->db->table('ledger_accounts')->where('book_id', $target->bookId)->where('is_group', false)->get() as $account) {
                $id = Row::text($account, 'id');
                $accounts[$id] = $account;
                $closing[$id] = Money::minor('0', $currency);
                $period[$id] = Money::minor('0', $currency);
                $accountExact[$id] = BigRational::zero();
            }

            foreach ([$translationReserveId, $roundingReserveId] as $id) {
                Assert::that(isset($accounts[$id]) && Row::text($accounts[$id], 'type') === 'equity', 'Consolidation adjustments require equity posting accounts.');
            }

            if ($nciAccountId !== null) {
                Assert::that(isset($accounts[$nciAccountId]) && Row::text($accounts[$nciAccountId], 'type') === 'equity'
                    && ! in_array($nciAccountId, [$translationReserveId, $roundingReserveId], true), 'Non-controlling interest requires a distinct equity posting account.');
            }

            if ($retainedEarningsAccountId !== null) {
                Assert::that(isset($accounts[$retainedEarningsAccountId]) && Row::text($accounts[$retainedEarningsAccountId], 'type') === 'equity'
                    && ! in_array($retainedEarningsAccountId, [$translationReserveId, $roundingReserveId, $nciAccountId], true), 'Retained earnings requires a distinct equity posting account.');
            }

            foreach ($investments as $investment) {
                Assert::that(isset($accounts[$investment->investmentAccountId]) && Row::text($accounts[$investment->investmentAccountId], 'type') === 'asset', 'Investment elimination requires a group asset investment account.');
                Assert::that(isset($accounts[$investment->goodwillAccountId]) && Row::text($accounts[$investment->goodwillAccountId], 'type') === 'asset', 'Goodwill requires a group asset account.');
                Assert::that(isset($accounts[$investment->bargainAccountId]) && Row::text($accounts[$investment->bargainAccountId], 'type') === 'income', 'Bargain purchase requires a group income account.');
                Assert::that(! in_array($investment->investmentAccountId, [$translationReserveId, $roundingReserveId, $nciAccountId], true), 'Investment elimination accounts must be distinct from adjustment reserves.');
                Assert::that(! isset($investmentAccounts[$investment->goodwillAccountId]), 'Goodwill cannot use another investment elimination account.');
                Assert::that($investment->consideration->currency == $currency, 'Acquisition consideration must use the group currency.');
                Assert::that((Row::nullable($accounts[$investment->goodwillAccountId], 'currency_code') ?? $currency->code) === $currency->code, 'Goodwill requires a functional group-currency asset account.');
                Assert::that($memberByBook[$investment->investeeBookId]->ownershipFraction !== '1' || $investment->measuredNci === null || $investment->measuredNci->minor->isZero(), 'A wholly owned subsidiary cannot have measured acquisition NCI.');
            }

            $adjustmentIds = [$translationReserveId, $roundingReserveId, $nciAccountId, $retainedEarningsAccountId];
            foreach ($investments as $investment) {
                array_push($adjustmentIds, $investment->investmentAccountId, $investment->goodwillAccountId, $investment->bargainAccountId);
            }
            foreach (array_filter($adjustmentIds) as $id) {
                Assert::that(Row::boolean($accounts[$id], 'active') && Row::nullable($accounts[$id], 'deleted_at') === null
                    && ! Row::boolean($accounts[$id], 'is_control') && Row::nullable($accounts[$id], 'cash_flow') !== 'cash', 'Consolidation adjustments require live non-control non-cash posting accounts.');
            }

            $exact = BigRational::zero();
            $nciExact = BigRational::zero();
            $nciProfitExact = BigRational::zero();
            $goodwill = [];
            $bargains = [];
            $entities = [];
            $acquisitions = [];
            $acquisitionNetAssetResults = [];
            $fairValueResults = [];
            $nciMeasurement = [];
            $this->validateCutoff($target, $books[$target->bookId]);

            foreach ($this->reports->trialBalance($target) as $balance) {
                $closing[$balance->accountId] = $balance->closing;
                $accountExact[$balance->accountId] = $balance->closing->rational();
                $exact = $exact->plus($balance->closing->rational());
            }

            foreach ($this->reports->trialBalance($target, $startsOn, includeClosing: false) as $balance) {
                $period[$balance->accountId] = $balance->debits->minus($balance->credits);
            }

            foreach ($members as $member) {
                $book = $books[$member->snapshot->bookId];
                $sourceCurrency = $this->db->currency(Row::text($book, 'currency_code'));
                $entity = Row::text($book, 'entity_id');
                Assert::that(! in_array($entity, $entities, true), 'Do not consolidate parallel books of the same entity twice.');
                $entities[] = $entity;
                $this->validateCutoff($member->snapshot, $book);
                $movements = [];
                $netAssets = BigRational::zero();
                $investment = $eliminated[$member->snapshot->bookId] ?? null;
                $acquisitionBalances = [];
                $acquisitionNetAssets = BigRational::zero();
                $acquisitionRate = null;
                $minority = BigRational::one()->minus(BigRational::of($member->ownershipFraction));
                $memberProfit = BigRational::zero();
                $memberEarnings = BigRational::zero();

                if ($investment !== null) {
                    $acquisition = $investment->acquisitionSnapshot;
                    Assert::that($acquisition->effectiveOn <= $target->effectiveOn && BigInteger::of($acquisition->throughSequence)->isLessThanOrEqualTo($member->snapshot->throughSequence), 'Acquisition must precede the member reporting snapshot.');
                    Assert::that(! $this->db->table('ledger_journals')->where('book_id', $acquisition->bookId)->where('effective_on', '<', $acquisition->effectiveOn)
                        ->where('sequence', '>', $acquisition->throughSequence)->where('sequence', '<=', $member->snapshot->throughSequence)->exists(), 'Late pre-acquisition postings require a reviewed replacement acquisition snapshot.');
                    $acquisitionRate = $investment->acquisitionRate;
                    Assert::that($sourceCurrency == $currency ? $acquisitionRate === null : ($acquisitionRate instanceof ExchangeRate
                        && $acquisitionRate->from == $sourceCurrency && $acquisitionRate->to == $currency && $acquisitionRate->asOf === $acquisition->effectiveOn), 'Foreign acquisitions require an approved acquisition-date rate.');

                    foreach ($this->reports->trialBalance($acquisition) as $balance) {
                        $acquisitionBalances[$balance->accountId] = $balance->closing;
                        if (in_array($balance->type, [AccountType::Asset, AccountType::Liability], true)) {
                            $acquisitionNetAssets = $acquisitionNetAssets->plus($balance->closing->rational());
                        } elseif (in_array($balance->type, [AccountType::Income, AccountType::Expense], true)) {
                            Assert::that($balance->closing->minor->isZero(), 'Close pre-acquisition income and expenses into equity in the acquisition book before capturing its snapshot.');
                        }
                    }
                }

                foreach ($this->reports->trialBalance($member->snapshot, $startsOn, includeClosing: false) as $balance) {
                    $movements[$balance->accountId] = $balance->debits->minus($balance->credits);
                }

                if ($investment !== null && $investment->acquisitionSnapshot->effectiveOn >= $startsOn) {
                    foreach ($this->reports->trialBalance($investment->acquisitionSnapshot, $startsOn, includeClosing: false) as $balance) {
                        $movements[$balance->accountId] = $movements[$balance->accountId]->minus($balance->debits)->plus($balance->credits);
                    }
                }

                foreach ($this->reports->trialBalance($member->snapshot) as $balance) {
                    $movement = $movements[$balance->accountId];
                    $amount = $balance->closing;
                    $acquiredEquity = $balance->type === AccountType::Equity ? ($acquisitionBalances[$balance->accountId] ?? Money::minor('0', $amount->currency)) : Money::minor('0', $amount->currency);
                    $historical = $member->historicalEquityAmounts[$balance->accountId] ?? null;

                    if ($amount->minus($acquiredEquity)->minor->isZero() && $movement->minor->isZero() && ($historical === null || $historical->minor->isZero())) {
                        continue;
                    }

                    if ($investment !== null && $balance->type === AccountType::Equity && $amount->minor->isEqualTo($acquiredEquity->minor)
                        && ($historical === null || $historical->rational()->isEqualTo($acquiredEquity->rational()->multipliedBy($acquisitionRate->rate ?? '1')))) {
                        continue;
                    }

                    $mapped = $member->accountMap[$balance->accountId] ?? '';
                    Assert::that(isset($accounts[$mapped]) && Row::text($accounts[$mapped], 'type') === $balance->type->value
                        && ! in_array($mapped, [$translationReserveId, $roundingReserveId, $nciAccountId], true), 'Every non-zero source account requires a compatible group account mapping.');

                    if ($historical !== null) {
                        Assert::that($balance->type === AccountType::Equity && $balance->closing->currency != $currency && $historical->currency == $currency,
                            'Historical translated balances apply only to foreign equity in the group currency.');
                        $rate = null;
                        $translated = $historical;
                        $translatedExact = $historical->rational();
                    } elseif ($balance->type === AccountType::Equity && $amount->minor->isZero()) {
                        $rate = null;
                        $translated = Money::minor('0', $currency);
                        $translatedExact = BigRational::zero();
                    } else {
                        $rate = $this->rate($member, $balance->accountId, $balance->type, $balance->closing->currency, $currency);
                        $translatedExact = $balance->closing->rational()->multipliedBy($rate->rate ?? '1');
                        $translated = $rate?->convert($balance->closing) ?? $balance->closing;
                    }

                    if ($investment !== null && $balance->type === AccountType::Equity) {
                        $eliminatedEquity = $acquiredEquity->rational()->multipliedBy($acquisitionRate->rate ?? '1');
                        $translatedExact = $translatedExact->minus($eliminatedEquity);
                        $translated = $translated->minus(Money::rounded($eliminatedEquity, $currency, Rounding::HalfEven));
                    }

                    if (in_array($balance->type, [AccountType::Asset, AccountType::Liability], true)) {
                        $netAssets = $netAssets->plus($translatedExact);
                    }

                    $closing[$mapped] = $closing[$mapped]->plus($translated);
                    $accountExact[$mapped] = $accountExact[$mapped]->plus($translatedExact);
                    $exact = $exact->plus($translatedExact);

                    if ($balance->type === AccountType::Equity && ! $minority->isZero()) {
                        $attribution = $translatedExact->multipliedBy($minority);
                        $closing[$mapped] = $closing[$mapped]->minus(Money::rounded($attribution, $currency, Rounding::HalfEven));
                        $accountExact[$mapped] = $accountExact[$mapped]->minus($attribution);
                        $exact = $exact->minus($attribution);
                    }

                    if (in_array($balance->type, [AccountType::Income, AccountType::Expense], true)) {
                        $period[$mapped] = $period[$mapped]->plus($rate?->convert($movement) ?? $movement);
                        $memberProfit = $memberProfit->minus($movement->rational()->multipliedBy($rate->rate ?? '1'));
                        $memberEarnings = $memberEarnings->minus($translatedExact);
                    }
                }

                $fairValueAccounts = [];
                foreach ($investment->fairValues ?? [] as $fairValue) {
                    $id = $fairValue->accountId;
                    Assert::that(isset($accounts[$id]) && in_array(Row::text($accounts[$id], 'type'), ['asset', 'liability'], true)
                        && ! isset($investmentAccounts[$id]) && ! isset($goodwillAccounts[$id]) && ! isset($fairValueAccounts[$id]), 'Each acquisition fair-value line requires a distinct compatible group asset or liability account.');
                    Assert::that(Row::boolean($accounts[$id], 'active') && Row::nullable($accounts[$id], 'deleted_at') === null && ! Row::boolean($accounts[$id], 'is_control')
                        && Row::nullable($accounts[$id], 'cash_flow') !== 'cash', 'Fair-value adjustments require live non-cash non-control accounts.');
                    $fairValueAccounts[$id] = true;
                    Assert::that($fairValue->adjustment->currency == $sourceCurrency, 'Acquisition fair values must use the investee functional currency.');
                    $rate = $this->rate($member, $id, AccountType::from(Row::text($accounts[$id], 'type')), $sourceCurrency, $currency);
                    $carrying = $fairValue->adjustment->rational();
                    $taxBase = $fairValue->taxBaseAdjustment->rational();
                    $tax = $fairValue->taxAdjustment($carrying, $taxBase);
                    $currentTaxRate = $fairValue->taxRate;
                    $recognizeTaxAsset = $fairValue->recognizeDeferredTaxAsset;
                    $acquisitionNetAssets = $acquisitionNetAssets->plus($carrying)->plus($tax);

                    foreach ($fairValue->movements as $movement) {
                        Assert::that($investment !== null && $movement['effective_on'] >= $investment->acquisitionSnapshot->effectiveOn
                            && $movement['effective_on'] <= $target->effectiveOn, 'Fair-value movements must fall between acquisition and the reporting date.');
                        $nextCarrying = $carrying->plus($movement['amount']->rational());
                        $taxBase = $taxBase->plus(($movement['tax_base_delta'] ?? Money::minor('0', $sourceCurrency))->rational());
                        $currentTaxRate = $movement['tax_rate'] ?? $currentTaxRate;
                        $recognizeTaxAsset = $movement['recognize_deferred_tax_asset'] ?? $recognizeTaxAsset;
                        $nextTax = $fairValue->taxAdjustment($nextCarrying, $taxBase, $currentTaxRate, $recognizeTaxAsset);
                        $change = $movement['amount']->rational()->plus($nextTax->minus($tax));
                        $counter = $movement['counter_account_id'];
                        Assert::that(isset($accounts[$counter]) && in_array(Row::text($accounts[$counter], 'type'), ['income', 'expense', 'equity'], true)
                            && Row::boolean($accounts[$counter], 'active') && Row::nullable($accounts[$counter], 'deleted_at') === null
                            && ! in_array($counter, [$translationReserveId, $roundingReserveId, $nciAccountId], true), 'Fair-value movements require an explicit compatible profit-or-loss or equity counter account.');
                        $profitOrLoss = Row::text($accounts[$counter], 'type') !== 'equity';
                        $inPeriod = $movement['effective_on'] >= $startsOn;
                        $movementRate = $movement['rate'] ?? null;
                        if ($movementRate !== null) {
                            Assert::that($movementRate->from == $sourceCurrency && $movementRate->to == $currency && $movementRate->asOf <= $movement['effective_on'], 'Fair-value movement rates must match their currencies and accounting dates.');
                            Assert::that($sourceCurrency != $currency || BigRational::of($movementRate->rate)->isEqualTo(1), 'Same-currency fair-value movements require an identity rate.');
                        } elseif ($sourceCurrency != $currency) {
                            Assert::that($inPeriod && $profitOrLoss, 'Prior-period and equity fair-value movements require explicit historical exchange rates.');
                            $movementRate = $this->rate($member, $counter, AccountType::Income, $sourceCurrency, $currency);
                        }
                        if ($profitOrLoss && ! $inPeriod) {
                            $counter = $retainedEarningsAccountId ?? '';
                        }
                        $counterExact = $change->negated()->multipliedBy($movementRate->rate ?? '1');
                        $closing[$counter] = $closing[$counter]->plus(Money::rounded($counterExact, $currency, Rounding::HalfEven));
                        $exact = $exact->plus($counterExact);
                        if ($profitOrLoss && $inPeriod) {
                            $period[$counter] = $period[$counter]->plus(Money::rounded($counterExact, $currency, Rounding::HalfEven));
                            $memberProfit = $memberProfit->minus($counterExact);
                            $memberEarnings = $memberEarnings->minus($counterExact);
                        } elseif (! $minority->isZero()) {
                            $attribution = $counterExact->multipliedBy($minority);
                            $closing[$counter] = $closing[$counter]->minus(Money::rounded($attribution, $currency, Rounding::HalfEven));
                            $exact = $exact->minus($attribution);
                        }
                        $carrying = $nextCarrying;
                        $tax = $nextTax;
                    }
                    $translated = $carrying->multipliedBy($rate->rate ?? '1');
                    $closing[$id] = $closing[$id]->plus(Money::rounded($translated, $currency, Rounding::HalfEven));
                    $exact = $exact->plus($translated);
                    $translatedTax = $tax->multipliedBy($rate->rate ?? '1');
                    $taxId = $tax->isPositive() ? $fairValue->deferredTaxAssetAccountId : $fairValue->deferredTaxLiabilityAccountId;
                    foreach ([$fairValue->taxAdjustment($fairValue->adjustment->rational(), $fairValue->taxBaseAdjustment->rational()), $tax] as $taxAmount) {
                        if ($taxAmount->isZero()) {
                            continue;
                        }
                        $taxAccount = $taxAmount->isPositive() ? $fairValue->deferredTaxAssetAccountId : $fairValue->deferredTaxLiabilityAccountId;
                        Assert::that($taxAccount !== null && isset($accounts[$taxAccount]) && Row::text($accounts[$taxAccount], 'type') === ($taxAmount->isPositive() ? 'asset' : 'liability')
                            && Row::boolean($accounts[$taxAccount], 'active') && Row::nullable($accounts[$taxAccount], 'deleted_at') === null
                            && ! Row::boolean($accounts[$taxAccount], 'is_control') && Row::nullable($accounts[$taxAccount], 'cash_flow') !== 'cash'
                            && ! isset($investmentAccounts[$taxAccount]) && ! isset($goodwillAccounts[$taxAccount]), 'Deferred tax requires explicit compatible live asset or liability accounts.');
                    }
                    if (! $tax->isZero() && $taxId !== null) {
                        $closing[$taxId] = $closing[$taxId]->plus(Money::rounded($translatedTax, $currency, Rounding::HalfEven));
                        $exact = $exact->plus($translatedTax);
                    }
                    $netAssets = $netAssets->plus($translated)->plus($translatedTax);
                    $fairValueResults[$member->snapshot->bookId][] = ['account_id' => $id, 'carrying_adjustment' => Money::rounded($translated, $currency, Rounding::HalfEven),
                        'deferred_tax_adjustment' => Money::rounded($translatedTax, $currency, Rounding::HalfEven), 'deferred_tax_account_id' => $taxId];
                }
                if ($investment !== null) {
                    $acquisitions[$member->snapshot->bookId] = [$acquisitionNetAssets, $acquisitionRate];
                    $acquisitionNetAssetResults[$member->snapshot->bookId] = Money::rounded($acquisitionNetAssets->multipliedBy($acquisitionRate->rate ?? '1'), $currency, Rounding::HalfEven);
                    $nciMeasurement[$member->snapshot->bookId] = $investment->measuredNci === null ? 'proportionate' : 'fair_value';
                }

                if (! $minority->isZero()) {
                    $nciExact = $nciExact->plus($netAssets->multipliedBy($minority));
                    if ($investment?->measuredNci !== null) {
                        $closingRate = $this->rate($member, $investment->investmentAccountId, AccountType::Asset, $investment->acquisitionRate->from ?? $currency, $currency);
                        $premium = $investment->measuredNci->rational()->dividedBy($acquisitionRate->rate ?? '1')->minus($acquisitionNetAssets->multipliedBy($minority));
                        $nciExact = $nciExact->plus($premium->multipliedBy($closingRate->rate ?? '1'));
                    }
                    $nciProfitExact = $nciProfitExact->plus($memberProfit->multipliedBy($minority));
                    $attribution = $memberEarnings->multipliedBy($minority);
                    $retained = $retainedEarningsAccountId ?? '';
                    $closing[$retained] = $closing[$retained]->plus(Money::rounded($attribution, $currency, Rounding::HalfEven));
                    $exact = $exact->plus($attribution);
                }
            }

            foreach ($investments as $investment) {
                $member = $memberByBook[$investment->investeeBookId];
                $consideration = $closing[$investment->investmentAccountId];
                Assert::that($consideration->minor->isEqualTo($investment->consideration->minor), 'The dedicated investment carrying value must equal the reviewed acquisition consideration.');
                [$acquisitionNetAssets, $acquisitionRate] = $acquisitions[$investment->investeeBookId];
                $residual = $investment->measuredNci === null
                    ? $investment->consideration->rational()->minus($acquisitionNetAssets->multipliedBy(BigRational::of($member->ownershipFraction))->multipliedBy($acquisitionRate->rate ?? '1'))
                    : $investment->consideration->rational()->plus($investment->measuredNci->rational())->minus($acquisitionNetAssets->multipliedBy($acquisitionRate->rate ?? '1'));
                $closing[$investment->investmentAccountId] = Money::minor('0', $currency);
                $exact = $exact->minus($accountExact[$investment->investmentAccountId]);
                $closingRate = $acquisitionRate === null ? null : $this->rate($member, $investment->goodwillAccountId, AccountType::Asset, $acquisitionRate->from, $currency);
                $amount = $residual->isNegative() ? $residual : $residual->dividedBy($acquisitionRate->rate ?? '1')->multipliedBy($closingRate->rate ?? '1');
                $value = Money::rounded($amount, $currency, Rounding::HalfEven);
                $goodwill[$investment->investeeBookId] = $residual->isNegative() ? Money::minor('0', $currency) : $value;
                $bargains[$investment->investeeBookId] = $residual->isNegative() ? $value->negated() : Money::minor('0', $currency);
                $plug = $residual->isNegative() ? ($investment->acquisitionSnapshot->effectiveOn < $startsOn ? ($retainedEarningsAccountId ?? '') : $investment->bargainAccountId) : $investment->goodwillAccountId;
                $closing[$plug] = $closing[$plug]->plus($value);
                if ($residual->isNegative() && $investment->acquisitionSnapshot->effectiveOn >= $startsOn) {
                    $period[$plug] = $period[$plug]->plus($value);
                }
                $exact = $exact->plus($amount);
            }

            $nci = Money::minor('0', $currency);

            if ($nciAccountId !== null) {
                $nci = Money::rounded($nciExact, $currency, Rounding::HalfEven);
                $closing[$nciAccountId] = $closing[$nciAccountId]->minus($nci);
                $exact = $exact->minus($nciExact);
            }

            $foreignSources = array_filter($members, fn (Member $member): bool => Row::text($books[$member->snapshot->bookId], 'currency_code') !== $currency->code);
            Assert::that($foreignSources !== [] || $exact->isZero(), 'Same-currency consolidation cannot create a currency translation adjustment.');

            $translation = Money::rounded($exact->negated(), $currency, Rounding::HalfEven);
            $closing[$translationReserveId] = $closing[$translationReserveId]->plus($translation);

            $sum = Money::minor('0', $currency);

            foreach ($closing as $value) {
                $sum = $sum->plus($value);
            }

            $rounding = $sum->negated();
            $closing[$roundingReserveId] = $closing[$roundingReserveId]->plus($rounding);
            $totals = array_fill_keys(['asset', 'liability', 'equity', 'income', 'expense'], Money::minor('0', $currency));
            $income = Money::minor('0', $currency);
            $expenses = Money::minor('0', $currency);
            $rows = [];

            foreach ($accounts as $id => $account) {
                $type = Row::text($account, 'type');
                $totals[$type] = $totals[$type]->plus($closing[$id]);
                $rows[] = ['account_id' => $id, 'code' => Row::text($account, 'code'), 'name' => Row::text($account, 'name'), 'type' => $type, 'closing' => $closing[$id]];

                if ($type === 'income') {
                    $income = $income->minus($period[$id]);
                } elseif ($type === 'expense') {
                    $expenses = $expenses->plus($period[$id]);
                }
            }

            $earnings = $totals['income']->plus($totals['expense'])->negated();
            $nciProfit = Money::rounded($nciProfitExact, $currency, Rounding::HalfEven);
            $result = ['trial_balance' => $rows, 'income_statement' => ['income' => $income, 'expenses' => $expenses, 'profit' => $income->minus($expenses)],
                'balance_sheet' => ['assets' => $totals['asset'], 'liabilities' => $totals['liability']->negated(), 'equity' => $totals['equity']->negated(), 'unclosed_earnings' => $earnings,
                    'difference' => $totals['asset']->plus($totals['liability'])->plus($totals['equity'])->minus($earnings)],
                'translation_adjustment' => $translation, 'rounding_adjustment' => $rounding, 'non_controlling_interest' => $nci, 'goodwill' => $goodwill, 'bargain_purchase_gain' => $bargains,
                'profit_attribution' => ['parent' => $income->minus($expenses)->minus($nciProfit), 'non_controlling_interest' => $nciProfit],
                'acquisition_net_assets' => $acquisitionNetAssetResults, 'fair_value_adjustments' => $fairValueResults, 'nci_measurement' => $nciMeasurement,
                'source_snapshots' => array_map(fn (Member $member): Snapshot => $member->snapshot, $members),
                'elimination_snapshot' => $target, 'policy_version' => $policyVersion];
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_consolidation_runs', ['id' => $id, 'book_id' => $target->bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $target->effectiveOn, 'inputs' => Canonical::json(['target' => $target, 'members' => $members, 'starts_on' => $startsOn,
                    'translation_reserve_id' => $translationReserveId, 'rounding_reserve_id' => $roundingReserveId, 'nci_account_id' => $nciAccountId,
                    'retained_earnings_account_id' => $retainedEarningsAccountId, 'investments' => $investments, 'policy_version' => $policyVersion]),
                'result' => Canonical::json($result)]);

            return $id;
        }, $target->bookId);
    }

    /** @return array<array-key, mixed> */
    public function get(string $bookId, string $reportId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $reportId): array {
            $report = $this->authorizedReport($bookId, $reportId);

            return Row::json($report, 'result');
        }, $bookId);
    }

    /** @return array<string, mixed> */
    public function evidence(string $bookId, string $reportId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $reportId): array {
            $report = $this->authorizedReport($bookId, $reportId);

            return ['id' => Row::text($report, 'id'), 'inputs' => Row::json($report, 'inputs'), 'result_json' => Row::text($report, 'result'),
                'result_hash' => Row::text($report, 'result_hash'), 'input_fingerprint' => Row::text($report, 'fingerprint'),
                'recorded_at' => Row::text($report, 'recorded_at'), 'actor_id' => Row::text($report, 'actor_id'), 'firm_id' => Row::nullable($report, 'firm_id'),
                'sequence' => Row::text($report, 'sequence')];
        }, $bookId);
    }

    private function authorizedReport(string $bookId, string $reportId): stdClass
    {
        $report = $this->db->find('ledger_consolidation_runs', $reportId, $bookId);
        $inputs = Row::json($report, 'inputs');
        $members = $inputs['members'] ?? [];

        if (is_array($members)) {
            foreach ($members as $member) {
                $row = (object) $member;
                $snapshot = (object) ($row->snapshot ?? []);
                $this->db->authorize(Permission::Read, Row::text($snapshot, 'book_id'));
            }
        }

        return $report;
    }

    private function rate(Member $member, string $accountId, AccountType $type, Currency $from, Currency $to): ?ExchangeRate
    {
        if ($from == $to) {
            return null;
        }

        $rate = match ($type) {
            AccountType::Asset, AccountType::Liability => $member->closingRate,
            AccountType::Income, AccountType::Expense => $member->incomeRate,
            AccountType::Equity => $member->historicalEquityRates[$accountId] ?? null,
        };
        Assert::that($rate instanceof ExchangeRate && $rate->from == $from && $rate->to == $to && $rate->asOf <= $member->snapshot->effectiveOn,
            'Foreign consolidation requires approved closing, income, and historical equity rates.');

        return $rate;
    }

    private function validateCutoff(Snapshot $snapshot, stdClass $book): void
    {
        Assert::that(BigInteger::of($snapshot->throughSequence)->isLessThanOrEqualTo(Row::text($book, 'next_sequence')), 'Consolidation cannot use a future knowledge cutoff.');
    }
}
