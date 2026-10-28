# Valuation and consolidation contracts

Accounting conventions of the implemented subledgers. Missing capabilities are in [roadmap.md](roadmap.md).

These modules are available on the scoped `Session`. Account IDs below refer to configured posting accounts in the indicated book. Host approvals, source documents, and policy selection remain explicit inputs. The modules perform no provider calls.

## Foreign open-item revaluation

```php
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Rounding;

$rate = new ExchangeRate($usd, $kes, '140', '2026-01-31', 'approved-treasury-rate:v1', Rounding::HalfEven);
$journal = $accounting->revaluations->revalue(
    $book, $itemId, 'revalue-item-january', '2026-01-31', $rate,
    $unrealizedGain, $unrealizedLoss, 'January reporting-date revaluation',
);
$corrections = $accounting->revaluations->clear(
    $book, $itemId, 'clear-item-january', '2026-02-01', 'Reverse January valuation',
);
```

`revalue()` translates the remaining source amount and adjusts its current functional carrying amount, including earlier valuations. Gains/losses depend on the open item's debit/credit direction. The journal changes functional carrying value with zero source-currency movement. A zero difference creates evidence without a journal and returns `null`.

Rate, date, provenance, rounding, actor, and sequence remain immutable. Dates must follow the item's allocations and earlier valuations. Repeated keys must retain their inputs. `clear()` reverses active valuations in descending recorded order; an identical retry returns its original correction journals even if a new valuation has since been created. Clearing keys are scoped to an item within the book.

Allocation and release clear active valuations on both affected items atomically before applying historical remaining carrying amounts and realized FX. For partial settlement, this reverses the whole item's unrealized adjustments; run an explicit valuation on the unpaid balance at the next reporting date. This is an explicit reversal policy, not proportional preservation of unrealized FX. Grant `Revalue`, `Reverse`, and `Post` as required in addition to `Allocate`.

Example: USD 100 initially worth KES 13,000 is revalued to 14,000, then settled for 14,500. Clearing reverses unrealized gain 1,000, and settlement recognizes realized gain 1,500. The foreign obligation and functional control balance both become zero. A partial USD 40 settlement recognizes 600; the remaining USD 60 carries 7,800 before its next valuation.

This module covers foreign control-account open items. Rates are supplied and approved by the host; the engine does not fetch rates or decide rate suitability.

## Foreign non-control monetary accounts

Use `monetaryAccounts->register($book, $account, $policyVersion)`, then `revalue($book, $account, $key, $date, $rate, $gainAccount, $lossAccount, $reason, $dimensions = [])`. Registration explicitly declares a foreign non-control asset/liability monetary; it is not inferred for all foreign accounts. The returned `Valuation` exposes `id`, `journalId`, `sourceBalance`, `carryingBefore`, `adjustment`, and `carryingAfter()`. `reverse($book, $valuationId, $key, $date, $reason)` corrects the latest active valuation for that exact dimension bucket.

```php
$accounting->monetaryAccounts->register($book, $usdBank, 'monetary-bank-policy:v1');
$valuation = $accounting->monetaryAccounts->revalue(
    $book, $usdBank, 'bank-january', '2026-01-31', $approvedClosingRate,
    $exchangeGain, $exchangeLoss, 'Revalue the USD bank balance',
);
$translatedBalance = $valuation->carryingAfter();
```

Revaluation changes functional carrying value without inventing foreign quantity, including when the foreign balance is zero and a functional remainder exists. Cash-account adjustments are classified as exchange effects in cash flow. No-change records still retain approved rate evidence. Later commands cannot be dated before earlier valuation history, and postings recorded after a valuation cannot silently backdate before it in the same bucket. Ordinary future-dated transactions do not prevent closing an earlier reporting date.

The host owns monetary-item eligibility, rate approval, and realized/unrealized classifications. Non-control account revaluation does not allocate payments or calculate transaction-level realized FX automatically. Control accounts continue to use open-item revaluation.

## Perpetual stock valuation

```php
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Money\Money;

$pool = $accounting->inventory->pool(
    $book, 'MAIN-STORE:MEDICINE-42', 'MEDICINE-42', $inventoryAsset,
    ValuationMethod::Fifo, ['department' => 'pharmacy'],
);
$receipt = $accounting->inventory->receive(
    $book, $pool, 'receipt-42', '2026-01-01', new Quantity('10'),
    Money::of('1000', $kes), $goodsReceivedClearing, 'Receive purchased medicines',
);
$issue = $accounting->inventory->issue(
    $book, $pool, 'issue-42', '2026-01-02', new Quantity('2.5'),
    $costOfGoods, 'Issue dispensed medicines',
);
$freight = $accounting->inventory->adjustValue(
    $book, $pool, 'freight-42', '2026-01-03', Money::of('30', $kes),
    $freightClearing, 'Capitalize freight on remaining stock',
);
$balance = $accounting->inventory->balance($snapshot, $pool);
```

A pool is a fixed SKU, valuation method, GL account, and dimension set. Choose whether a pool represents a warehouse or another approved cost boundary. Pools can share an inventory account. The host owns units of measure, conversions, physical custody, batch/serial/expiry, procurement, and operational documents. Convert to the pool's base unit using exact arithmetic before calling it.

Quantities allow at most 12 decimal places and magnitudes below 10²⁶. Money remains in the book's functional currency. Receipt cost is the total received value, not a floating unit price. Zero-cost quantities remain tracked and require an open period even without a journal.

FIFO consumes the oldest remaining layer. A partial issue rounds its share of the layer's remaining cost; complete depletion consumes the exact remainder. Moving average allocates from total remaining cost and quantity using half-even rounding, without persisting a rounded unit price. Receiving 10 units for 1,000 and 20 for 2,400, then issuing 15, costs 1,600 under FIFO and 1,700 under moving average.

`adjustValue()` changes only the value of remaining stock. A positive adjustment can represent approved landed costs; a negative one can represent a write-down. FIFO distributes it by remaining layer values, or quantities when all remaining values are zero, conserving the exact adjustment. This is not a receipt-specific landed-cost allocation that also adjusts goods already sold. Select the correct counter account through the host's policy.

Negative stock, negative remaining value, changing methods, and posting before a pool's latest accounting date are rejected. Corrections use `reverse($book, $movementId, $key, $date, $reason)` in reverse dependency order. A reversed issue restores its original layers; a reversed adjustment restores its original allocations. No silent historical reposting occurs.

Stock GL accounts accept only linked valuation movements. Inventory and asset valuation accounts cannot overlap or serve as each other's counter accounts. Retrying a movement returns the original identity/cost even after later pool activity. Invalid commands roll back both journal and stock history.

Use `returnIssue($book, $issueId, $key, $date, $quantity, $reason, $destinationPoolId = null)` to return no more than the original issue's remaining returnable quantity, using its remaining original cost and original counter account/dimensions. A return becomes a new receipt layer dated when received; it does not restore an old FIFO position. Partial returns allocate the original issue's remaining aggregate cost proportionally, consuming its final cost cent on the final return. A linked return is separate from any customer credit note.

`transfer($book, $sourcePool, $destinationPool, $key, $date, $quantity, $clearingAccount, $reason)` moves the same SKU within one book at the source issue's exact cost. The clearing account must be a non-cash, non-control balance-sheet account. Both movements and their link commit together; the returned `Transfer` contains `id`, `issue`, and `receipt`. `reverseTransfer($book, $transferId, $key, $date, $reason)` restores both sides together in dependency order. Individual transfer movements cannot be reversed independently or treated as customer issues eligible for return. Different pool methods/dimensions are allowed; clearing balances net to zero for the complete book, while dimension-level clearing balances can differ.

### Supplier returns and price variances

```php
$returned = $accounting->inventory->returnReceipt(
    $book, $receipt->id, 'supplier-return-42', '2026-01-05', new Quantity('2'),
    $supplierReturnClearing, $purchasePriceVariance, 'Return rejected purchased goods',
);
```

`quantity` is positive at the API boundary and becomes a negative stock movement. `Movement::value` is the signed inventory carrying effect, while `commercialValue` is the signed original receipt-price credit. Both values conserve remaining remainders through partial returns and exact corrections. The original must be an active non-transfer purchase receipt in the same pool. Cumulative supplier returns cannot exceed that receipt's quantity. FIFO additionally requires sufficient quantity in that original receipt's remaining layer, including any approved value adjustments; it never takes an unrelated newer layer. Moving average uses the current pool's carrying value and treats stock as fungible, subject to pool availability and original-receipt return limits.

The selected counter account receives the original receipt-price credit. The variance account must be a distinct functional income/expense account. A receipt of 10 for 100 followed by 10 for 300 gives a moving-average carrying cost of 20 per unit. Returning two from the first receipt credits inventory 40, debits the selected clearing account 20, and debits purchase-price variance 20. Remaining inventory is 360, not a silently repriced 380. Under FIFO the same unadjusted return removes 20 with no variance. If freight increased that FIFO layer to 150, returning all ten credits inventory 150, debits clearing 100, and debits variance 50. A write-down can produce the opposite variance.

The host must approve whether the receipt-price amount is the correct supplier credit and how the variance is classified. Supplier payable/tax corrections use a separate `PurchaseCredit` or reviewed treatment against the commercial documents. A stock return itself does not reduce a supplier control account or imply a bank refund. Explicitly selecting a cash counter account means cash has actually moved. Customer returns, transfer receipts, and already reversed receipts cannot be used as purchase-receipt origins.

`reverse()` restores the exact stock, clearing, variance, and cost-layer effects. Existing chronology, reverse-dependency, open-period, retry, and atomic-commit rules apply. PostgreSQL independently recomputes the carrying cost and original-price credit and validates all journal effects, including zero-carrying or zero-commercial-value cases.

### Manufacturing

```php
use Noria\Ledger\Inventory\Quantity;

$production = $accounting->inventory->produce(
    $book, [['pool' => $materials, 'quantity' => new Quantity('4')]], $finished, new Quantity('1'),
    $wipAccount, 'produce-42', '2026-01-02', 'Assemble one unit',
);
$reversal = $accounting->inventory->reverseProduction($book, $production->id, 'unproduce-42', '2026-01-03', 'Correct assembly');
```

`produce()` consumes positive component quantities from one or more pools and produces a finished quantity into a distinct output pool. Component issues use each pool's FIFO or moving-average cost. All component issues, the output receipt, and their journals commit atomically through a shared `production_id`, with a non-cash, non-control functional-currency WIP asset account as the counter for every leg. A finished-goods receipt of 1 unit from a 4-unit issue costing 40 is valued at exactly 40. Zero-cost components and receipts remain physically tracked, including fractional issues that round to zero cost.

To absorb conversion costs, first recognize eligible labour/overhead through explicit WIP journals, then pass `conversionCost: Money::of('15', $kes)`. Output cost is the exact component cost plus this allocation: materials 40 and conversion 15 produce finished goods 55. Unallocated WIP remains in WIP; the command never automatically sweeps its balance. PostgreSQL rejects allocations exceeding recognized WIP and later corrections that invalidate historical conversion-cost funding. Cost eligibility and allocation across outputs remain reviewed inputs.

`reverseProduction()` restores the output receipt, every original component issue, and the conversion allocation together in reverse dependency order. Conversion costs return to WIP; correcting their original recognition is a separate journal reversal. One-sided reversals, duplicate component identities, substituted reversal legs, standalone customer returns of component issues, and supplier returns of manufactured receipts are rejected independently in PostgreSQL. Retries return the original identity, and the ordinary chronology, open-period, dimension, and atomic-commit rules apply.

A plain receipt does not imply a linked return.

## Fixed-asset financial lifecycle

```php
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Assets\StubConvention;
use Noria\Ledger\Scheduling\Schedule;

$asset = $accounting->assets->capitalize(new AssetRequest(
    $book, 'capitalize-equipment-42', 'EQUIPMENT-42', '2026-01-01', '2026-01-01',
    Money::of('12000', $kes), Money::of('2000', $kes),
    Schedule::equal(Money::of('10000', $kes), $approvedDates),
    $equipmentCost, $accumulatedDepreciation, $accumulatedImpairment,
    $depreciationExpense, $capitalizationClearing, 'equipment-policy:v1',
    stubConvention: StubConvention::ActualDays,
));
$events = $accounting->assets->depreciate($book, $asset, '2026-01-31');
$position = $accounting->assets->position($snapshot, $asset);
```

Capitalization posts gross cost against the supplied counter account. Supplier obligations belong in payables; use a clearing account to connect the approved capitalized amount to the supplier bill. `openingDepreciation` and `openingImpairment` allow imports with a matching opening equity/clearing counter account. Reconcile imported balances; do not post untracked asset account balances first.

Cost, accumulated depreciation, and accumulated impairment require three distinct functional-currency, non-cash, non-control asset accounts. Multiple assets can share those accounts while preserving each account's role. Depreciation uses a functional expense account. Valuation accounts are protected from detached journals and journal-only reversals.

The plan must sum to cost minus opening depreciation, opening impairment, and residual. Supply explicit nonnegative `ScheduleLine` values for an approved method. Dates must strictly increase and cannot precede recognition/availability. Equal allocation, exact production-weighted allocation, and per-slot declining balance are available as generators in `Schedule`. They do not infer useful life, residual, capitalization eligibility, tax allowances, production forecasts, or annual-to-period rate conventions.

`depreciate()` posts due active plan lines once, including under concurrent runners. To make a reviewed catch-up posting in a later open period, pass `postingOn:` on or after the requested cutoff and existing asset history. Only slots due by the requested cutoff are included. Repeating the same catch-up does not post them again.

Before an estimate change, impairment, or disposal, the command posts due depreciation. Partial-period depreciation uses the asset's immutable `StubConvention`: `ActualDays` prorates inclusively within the next plan interval, `CompletedPeriods` charges no partial interval, and `FullPeriod` charges the full next interval. Select a reviewed convention when capitalizing; the engine does not claim one convention suits every asset or method.

- `revise($book, $asset, $key, $date, $residual, $plan, $policyVersion, $reason)` starts a prospective plan after the change date and availability date.
- `impair($book, $asset, $key, $date, $loss, $lossAccount, $residual, $plan, $policyVersion, $reason)` recognizes a positive impairment expense and a replacement plan that conserves the reduced amount.
- `dispose($book, $asset, $key, $date, $proceeds, $proceedsAccount, $gainAccount, $lossAccount, $reason)` clears cost/contra balances and recognizes proceeds less carrying amount as gain/loss. Cash proceeds receive an explicit investing classification.
- `reverse($book, $event, $key, $date, $reason)` reverses the latest active event, its exact journal, partial-period depreciation, and plan change. Restore downstream events first.
- `cancel($book, $asset, $key, $date, $reason)` cancels capitalization only after downstream events have been reversed. It reverses the original journal instead of recording an invented disposal loss. Cancellation is final for that asset identity.
- `addCost($book, $asset, $key, $date, $cost, $counterAccount, $residual, $plan, $policyVersion, $reason)` recognizes due/stub depreciation, debits additional gross cost, credits the approved counter account, and starts a replacement plan conserving the enhanced remaining carrying value.
- `disposePart($book, $asset, $key, $date, $fraction, $proceeds, $proceedsAccount, $gainAccount, $lossAccount, $residual, $plan, $policyVersion, $reason)` recognizes due/stub depreciation, then removes the supplied fraction of current gross cost and accumulated depreciation/impairment using half-even minor-unit rounding. The fraction is strictly between zero and one with at most 18 decimals. A rounded zero/full gross-cost removal or negative relieved carrying value fails. Proceeds less relieved carrying value determine gain/loss; the replacement plan conserves the remainder.

Example: cost 12,000, monthly depreciation 1,000, no residual, disposal February 14. With actual days, January depreciation is 1,000 and February depreciation is 500. Proceeds of 8,000 produce a loss of 2,500. Reversing disposal restores February's 500 depreciation along with disposal effects; January's posted depreciation remains.

An asset costing 12,000 with January depreciation 1,000 has carrying value 11,000. Adding 3,000 at January 31 produces gross cost 15,000 and carrying value 14,000. A replacement residual of 1,000 requires future depreciation totaling 13,000. Full disposal removes the enhanced gross cost, not just the original capitalization.

Without that addition, disposing of 25% at January 31 removes gross cost 3,000 and accumulated depreciation 250, so relieved carrying value is 2,750. Proceeds of 2,500 produce loss 250. Remaining gross cost is 9,000, depreciation 750, carrying value 8,250, and a zero-residual replacement plan must total 8,250. This is an explicit proportionate-disposal convention, not automatic identification of a separately measured physical component. Reversing the event restores original gross/contra balances, stub depreciation, and the prior plan after downstream events are corrected.

Cash capitalization/additions are investing outflows; full/partial sale proceeds are investing inflows. Explicit cash-flow metadata handles compound depreciation/disposal journals. Supplier bills, recoverable taxes, and component identification remain separate reviewed inputs.

- `revalue($book, $asset, $key, $date, $uplift, $surplusAccount, $residual, $plan, $policyVersion, $reason)` accepts a signed, nonzero carrying-value change after due/stub depreciation. It eliminates accumulated depreciation/impairment against gross cost. Decreases first debit the asset's available surplus and then `lossAccountId:` (an expense account). Increases first recover prior profit-or-loss charges through `gainAccountId:` within `unimpairedCarrying:`, then credit surplus. Supply the reviewed depreciated no-loss carrying ceiling whenever prior profit-or-loss charges remain. The surplus account is pinned per asset. A replacement plan conserves the new carrying amount, including a zero fair value.

- `recover($book, $asset, $key, $date, $recoverableAmount, $unimpairedCarrying, $recoveryAccount, $residual, $plan, $policyVersion, $reason)` reverses impairment up to the least of recoverable amount, the supplied depreciated no-impairment ceiling, and the amount supported by outstanding impairment. Prior profit-or-loss charges recover first; any remaining reversal restores the revalued asset's surplus. The recovery account is a live functional income/expense account. Recoverable amount and the counterfactual depreciated ceiling are explicit valuation inputs, not inferred appraisals or original gross cost. Preserve their calculation in the host valuation policy records.

`AssetPosition::availableSurplus` and `unrecoveredLoss` expose per-asset attribution budgets. An impairment of 5,000 after a surplus of 3,000 debits surplus 3,000 and profit or loss 2,000. A subsequent recovery of 5,000 credits profit or loss 2,000 and restores surplus 3,000. Partial disposal releases proportional budgets; full disposal releases them completely. Pass `retainedEarningsAccountId:` on a disposal to transfer the released surplus directly to retained earnings without recycling it through profit. Without that election, released surplus remains in the equity account but cannot shield future losses on the continuing asset. Exact reversals restore both financial postings and attribution budgets.

Imported assets may supply `openingSurplus:`, `openingLoss:` and `surplusAccountId:`. Opening loss defaults to opening impairment; use the explicit amount for a reviewed earlier revaluation-loss history. A positive opening surplus is credited separately from a distinct opening-equity counter account.

Example: cost 12,000 with January depreciation 1,000 carries 11,000. A reviewed uplift of 3,000 revalues to 14,000. Gross cost becomes 14,000, accumulated depreciation and impairment become zero, and revaluation surplus credits 3,000. Reversing the event restores gross cost 12,000, depreciation 1,000, and the prior plan.

Exact corrections and economic impairment recoveries are distinct commands.

## Group reporting

```php
use Noria\Ledger\Consolidation\Member;

$cutoffs = $accounting->reports->snapshots([$eliminationBook, $subsidiaryBook], '2026-12-31');
$member = new Member(
    $cutoffs[$subsidiaryBook], $sourceToGroupAccounts,
    closingRate: $approvedClosingRate, incomeRate: $approvedPeriodAverage,
    historicalEquityAmounts: $approvedEquityRollforward,
);
$reportId = $accounting->consolidation->run(
    $cutoffs[$eliminationBook], 'group-report-2026', '2026-01-01', [$member],
    $translationReserve, $roundingReserve, 'group-accounting-policy:v1',
);
$report = $accounting->consolidation->get($eliminationBook, $reportId);
$evidence = $accounting->consolidation->evidence($eliminationBook, $reportId);
```

Members contribute 100% of their assets, liabilities, income, and expenses; this is full consolidation, not aggregation by ownership. `ownership_fraction` (a decimal string in `(0,1]`, default `1`) models the group's controlling share for non-controlling-interest purposes. All books belong to the session's tenant. Select one book per legal entity to avoid double-counting parallel treatments. All snapshots must have the same date and cannot claim future sequence cutoffs. Use `reports->snapshots()` for a consistent capture across books.

The target is a dedicated elimination/reporting book with the group chart and presentation currency. Post approved intercompany/investment/profit-elimination journals there before taking its snapshot. Target balances are already in group currency. Every nonzero source closing balance or period movement requires a target posting account of the same type.

Foreign assets/liabilities use the supplied closing rate. Income/expense balances and period activity use the supplied income rate. The host must determine whether a representative average is appropriate; transaction-date translation and multiple within-period rates are not computed automatically. Historical equity uses either an explicit account rate or a debit-positive group-currency `Money` amount from the approved translated equity rollforward. Exact amounts avoid manufacturing approximate blended rates for contributions or retained earnings.

Example: USD cash 200 at closing 140, capital 100 at historical 130, and income 100 at average 135 produce group cash 28,000, capital 13,000, profit 13,500, and a credit translation reserve of 1,500. Income-statement activity excludes fiscal closing transfers.

The translation reserve offsets the exact translated net balance. The separate rounding reserve absorbs only the remaining difference caused by independently quantized accounts. Neither becomes operating income. Retained earnings from previous periods require an approved historical translation rollforward; applying the current period's average to all prior profits is not supplied as an automatic policy.

Reports are immutable materializations retaining rendered labels, all numerical inputs, cutoffs, policy, and actor/firm. Backdated entries do not rewrite a saved report. `evidence()['result_hash']` is the SHA-256 of the exact UTF-8 `result_json` bytes supplied alongside it; re-encoding that JSON may change its byte hash. This is reproducibility evidence, not a digital signature or proof that the host's policy/mappings are correct.

Acquisition fair values and measured NCI are explicit reviewed inputs. Associates use the separate equity-method register below rather than full consolidation.

### Non-controlling interest

Pass distinct NCI and retained-earnings equity accounts and set `ownershipFraction` below one on controlled members:

```php
$member = new Member($subsidiaryCutoff, $map, $closingRate, $incomeRate, [$capital => $historicalRate], ownershipFraction: '0.8');
$reportId = $accounting->consolidation->run($eliminationCutoff, 'group-report', '2026-01-01', [$member],
    $translationReserve, $roundingReserve, 'group-policy:v1', nciAccountId: $nciInterest,
    retainedEarningsAccountId: $groupRetainedEarnings);
```

Assets, liabilities, income, and expenses are consolidated in full. NCI is the minority share of closing-rate net assets: `(1 - ownership_fraction) × (translated assets - translated liabilities)`, including deficit balances. Minority equity is reclassified from its mapped equity accounts; the minority share of unclosed earnings is reclassified through the explicit group retained-earnings account. Actual translation differences are attributed proportionately, without creating a currency reserve for same-currency members. `profit_attribution` separately reports parent and NCI period profit or loss while the income statement retains the full consolidated amount. NCI uses exact rational arithmetic and half-even rounding; presentation residuals belong in the rounding reserve.

### Investment elimination and partial goodwill

Pass `Investment` entries with a pinned acquisition snapshot and reviewed consideration to eliminate a parent's investment and recognize acquisition goodwill or a bargain purchase gain:

```php
use Noria\Ledger\Consolidation\Investment;

$reportId = $accounting->consolidation->run($eliminationCutoff, 'group-report', '2026-01-01', [$member],
    $translationReserve, $roundingReserve, 'group-policy:v1', $nciInterest,
    investments: [new Investment($subsidiaryBook, $investmentAccount, $goodwillAccount, $bargainAccount,
        $acquisitionSnapshot, Money::of('90', $groupCurrency), acquisitionRate: $approvedAcquisitionRate)],
    retainedEarningsAccountId: $groupRetainedEarnings);
```

The acquisition snapshot must belong to the investee and precede its reporting cutoff. Use an acquisition-treatment book with pre-acquisition income/expenses closed into equity before capturing the snapshot. Its asset/liability balances supply acquisition-date book net assets. Late postings dated before that acquisition require a reviewed replacement acquisition snapshot. The investee must be a consolidated member. Each investment requires a dedicated group asset account whose carrying value equals the reviewed consideration in group currency. Shared investment accounts and goodwill/investment account collisions are rejected.

Only acquisition-date equity is eliminated; subsequent equity movements and earnings remain. Partial goodwill is `consideration − ownership_fraction × acquisition-date net assets`, measured using the approved acquisition-date rate for a foreign investee. Positive goodwill is subsequently translated at the investee's closing rate without being recomputed from later profits or losses. A negative acquisition residual is a bargain gain, recognized in the acquisition period's income statement and carried through group retained earnings in later reporting periods. Results report nonnegative per-investee `goodwill` and separate `bargain_purchase_gain` amounts. Close source years normally and supply historical translated rollforwards for post-acquisition retained earnings.

Example: a parent pays 120 for a wholly owned subsidiary with acquisition net assets of 100. The group shows subsidiary cash 100 plus goodwill 20. After the subsidiary earns 40, goodwill remains 20 and group assets become 160. Paying 80 instead produces an acquisition-period bargain gain of 20. Paying 90 for 80% produces goodwill 10 and initial NCI 20; subsequent profit of 40 raises NCI to 28 and is attributed 32 to the parent and 8 to NCI. No same-currency translation reserve is created.

Without additional inputs this is the proportionate (partial-goodwill) method using explicitly selected acquisition book balances. Distributions and other group-specific eliminations require reviewed treatment inputs and journals.

### Acquisition fair values, deferred tax and measured NCI

```php
use Noria\Ledger\Consolidation\FairValue;

$fairValue = new FairValue($groupProperty, Money::of('40', $subsidiaryCurrency), '0.3',
    deferredTaxLiabilityAccountId: $deferredTaxLiability,
    movements: [['effective_on' => '2026-01-31', 'amount' => Money::of('-10', $subsidiaryCurrency),
        'counter_account_id' => $fairValueDepreciation]]);
$investment = new Investment($subsidiaryBook, $investmentAccount, $goodwillAccount, $bargainAccount,
    $acquisitionSnapshot, $considerationInGroupCurrency, $acquisitionRate,
    fairValues: [$fairValue], measuredNci: $measuredNciInGroupCurrency);
```

`FairValue` is an incremental acquisition-date asset/liability adjustment in investee functional currency, debit-positive: asset uplifts are positive and new liabilities negative. These inputs represent reviewed fair-value measurements; the engine does not appraise assets or infer which assets/liabilities qualify for recognition. The same source book's existing deferred tax remains in its book net assets. Incremental deferred tax is `-(adjustment - taxBaseAdjustment) × taxRate`. Tax bases default to no incremental change. Rates are explicit fractions in `[0,1]`. Deductible differences recognize an asset only with `recognizeDeferredTaxAsset: true`; the host assesses recoverability and applicable exemptions. Supply distinct compatible deferred-tax asset/liability accounts as needed. No deferred tax is automatically created on goodwill.

Movements roll forward the acquisition adjustment without rewriting acquisition goodwill. Each specifies accounting date, signed carrying change and a profit-or-loss/equity counter account. Optional `tax_base_delta`, `tax_rate` and `recognize_deferred_tax_asset` retain tax-basis, rate and recoverability changes. Related deferred-tax movements follow that same counter classification. Current-period profit/loss affects the income statement and NCI attribution; earlier profit/loss goes to group retained earnings. Equity movements remain in their selected equity accounts. Foreign current-period profit/loss may use the member's approved income rate; earlier-period and equity movements require an explicit historical `rate`. All movements must be between acquisition and the reporting date.

Proportionate NCI uses fair-value-adjusted identifiable net assets. Supplying `measuredNci:` elects full goodwill: `consideration + measured acquisition NCI - acquisition fair-value net assets`. Closing NCI is the translated measured opening interest plus its proportionate share of subsequent identifiable net-asset changes; it is not reset to a proportionate book-value interest. Full goodwill and the measured-NCI opening premium are translated as part of the foreign operation. Results expose `acquisition_net_assets`, `fair_value_adjustments` and `nci_measurement` alongside goodwill, profit attribution and the saved inputs.

Example: book net assets 100, asset uplift 40 and a 30% deferred-tax liability of 12 give acquisition net assets 128. Paying 110 for 80% produces partial goodwill 7.60 and initial NCI 25.60. Measured NCI of 35 instead produces full goodwill 17. Depreciating the uplift by 10 reverses deferred tax 3, so consolidated expense is 7, parent loss is 5.60 and NCI loss is 1.40. Closing NCI is 24.20 under the proportionate method or 33.60 under measured NCI. A later reporting period carries that earlier expense through retained earnings without recognizing it again.

## Associates and the equity method

```php
use Noria\Ledger\Associates\AssociateRequest;

$associate = $accounting->associates->acquire(new AssociateRequest(
    $book, 'acquire-associate-42', 'ASSOCIATE-42', $investeeEntity, '2026-01-01',
    Money::of('100', $currency), Money::of('300', $currency), '0.3',
    $investmentAccount, $fundingAccount, $shareOfProfit, $shareOfOci, $dividendClearing, $investmentImpairment,
    'Board representation and participation in financial policy; no control', 'equity-method:v1',
    lossLiabilityAccountId: $supportObligations,
));
$event = $accounting->associates->profit($book, $associate, 'associate-profit-jan', '2026-01-31',
    Money::of('60', $currency), 'Reviewed January profit', ['statement' => 'associate-jan:v1']);
$accounting->associates->oci($book, $associate, 'associate-oci-jan', '2026-01-31', Money::of('20', $currency), 'Reviewed OCI');
$accounting->associates->dividend($book, $associate, 'associate-dividend', '2026-02-01', Money::of('10', $currency), 'Distribution received');
$position = $accounting->associates->position($snapshot, $associate);
$evidence = $accounting->associates->evidence($snapshot, $associate, limit: 500);
```

This register posts a single equity-method investment in the investor's functional currency. It requires `Permission::Investments` (`ledger.investments`) in addition to ordinary posting/reversal permissions. The investee is a distinct tenant-owned entity reference; its individual assets, liabilities and revenues are not consolidated. Ownership is an exact fraction strictly between zero and one with at most 18 fractional digits. Significant influence and absence of control are separate host assessments recorded in `influenceBasis`; a percentage alone never establishes them.

Acquisition records reviewed cost and the investee's full identifiable fair-value net assets, translated into investor currency. Goodwill remains embedded in the investment and is not amortized or posted to a separate goodwill account. A confirmed excess of the investor's share of fair-value net assets over cost increases the investment and is recognized once through the share-of-profit account. The investment account and optional loss-obligation liability are dedicated, functional, non-control accounts protected from detached journals and other valuation registers.

`profit()`, `oci()` and `dividend()` accept full-investee amounts, not already ownership-weighted amounts. Supply profit/OCI after uniform-policy adjustments, acquisition-basis depreciation, applicable taxes and unrealized-profit eliminations; preserve their reviewed source calculations in `evidence`. Foreign financial statements must be translated before these functional-currency inputs; translation differences can be included in the appropriate OCI input. Ownership shares use cumulative half-even rounding per category, so repeated fractional results retain their final minor units. A cost of 100, 30% of profit 60, OCI 20 and dividends 10 gives carrying value 121: `100 + 18 + 6 - 3`. Dividends reduce carrying value and create no duplicate income. Distributions exceeding the remaining carrying value require a separate reviewed capital-distribution treatment and are rejected by this ordinary-dividend command.

Losses reduce the investment to zero. Further losses remain unrecognized unless an explicit legal/constructive loss-obligation limit is supplied through `obligate()`. That command recognizes suspended losses up to the limit in the dedicated liability; it does not invent an investment asset. `settleObligation()` debits that liability against the selected payment counter account. Subsequent positive results first offset suspended losses, then reverse recognized liabilities, then increase the investment. P&L and OCI suspended-loss buckets are retained separately under a single net-investment loss limit. Positive results offset their own category first, then the other; supply period results in consistent P&L-before-OCI order when they have the same reporting date.

`impair()` takes a reviewed recoverable value for the whole investment, including embedded goodwill. `recover()` takes recoverable value and an explicit no-impairment carrying ceiling, and is additionally capped by recorded impairment and the equity-method net basis. If recovery restores capacity against suspended losses, those losses are recognized against their original P&L/OCI categories as impairment is reversed. The register does not allocate impairment to embedded goodwill separately. `reverse()` restores the latest active event and its exact journal, loss buckets, liability limit and rounding history. `cancel()` reverses an untouched acquisition only after dependent events have been corrected and is final for that identity.

Cash acquisitions/support payments are classified as investing. A cash-dividend account uses the acquisition's explicit `dividendCashFlow` policy (`investing` or `operating`); a non-cash clearing account records an accrual without inventing a bank movement. Acquisition `dimensions:` are immutable and preserved on every event and correction, including mandatory account dimensions. `position()` and paginated `evidence()` use immutable reporting cutoffs. Events, journals, actor/firm, policies, influence basis, valuation inputs and source evidence commit atomically under the book lock, including zero-share events with no financial posting. PostgreSQL independently verifies acquisition calculations, ownership rounding, loss limits, recovery effects and journal linkage.

This single-ordinary-interest model does not infer IFRS 9 measurement or rank separate long-term interests forming part of a net investment. Such interests must be assessed separately before selecting this model.

## Specialized finance treatments

`Finance\FinancialCalculator` exposes `effectiveInterest()`, `lease()`, `expectedCreditLoss()` and `allocateRevenue()`. Rates are exact fractions per supplied slot, not inferred annual rates or day-count conventions. Payment dates must increase. Effective-interest plans round each period's interest. Lease plans discount exact cash flows, round successive carrying balances, derive conserving interest, and expose any difference from rounded-opening interest as `rounding_adjustment`. Advance leases exclude the commencement payment from the unpaid liability. ECL discounts each scenario's cash shortfalls before weighting and final rounding; probabilities must total exactly one. Include a zero-contractual later slot to represent delayed recoveries. Supplied scenarios determine the modeled default horizon; no credit staging is inferred.

Built-in treatments are available through every session without registration:

```php
use Noria\Ledger\Accounting\TreatmentRequest;

$journal = $accounting->treatments->post(new TreatmentRequest(
    $book, 'loan-42-period-1', '2026-06-30', new Source('loan.period', '42:1'),
    'ledger.finance', '1', $configuration, [
        'operation' => 'loan.period', 'position' => 'asset',
        'opening' => '950', 'rate_per_slot' => '0.1', 'payment' => '100',
        'interest_paid' => '95', 'interest_cash_flow' => 'operating',
        'rounding' => 'half_even', 'policy_version' => 'lending-policy:1',
        'basis_reference' => 'approved-contract:42',
    ],
));
```

Every operation requires `policy_version`, `basis_reference` and `rounding`; optional `dimensions` apply to every posting. All amounts use the book's functional currency. Roles must resolve to compatible functional, non-control accounts; `cash` must be a cash-classified asset, and other roles cannot be cash accounts.

| Operation | Additional inputs | Configuration roles |
|---|---|---|
| `loan.period` | `position` (`asset`/`liability`), `opening`, `rate_per_slot`, `payment`, `interest_paid`, `interest_cash_flow` | `loan`, `interest` (income for lender, expense for borrower), `cash` |
| `lease.commence` | `rate_per_slot`, boolean `in_advance`, ordered `payments` of `{due_on, payment}` | `lease.asset`, `lease.liability`, `cash` |
| `lease.period` | Same lease plan, selected by request date, plus `interest_paid`, `interest_cash_flow` | `lease.liability`, `interest` (expense), `cash` |
| `ecl.measure` | `rate_per_slot`, `contractual` list of `{period, amount}`, `scenarios` list of `{weight, receipts}` | `ecl.expense`, `ecl.allowance` (contra asset) |
| `revenue.recognize` | `contract_reference`, `transaction_price`, `obligations` list of `{reference, standalone_price}`, `satisfied` list of references | `contract.liability`, `revenue.<reference>` |

Loan/lease period openings must match the complete exact-dimension carrying bucket. ECL derives the previous credit allowance from that bucket and posts only its signed change. New measurements cannot precede existing bucket activity. Use a dedicated account or full dimension bucket for each instrument/portfolio. Cash interest is explicitly supplied rather than inferred from principal repayments, allowing previously accrued interest; it must be nonnegative and cannot exceed cash paid. Principal cash is investing for lenders and financing for borrowers; interest classification is explicit. Existing schedules can post independently reviewed ROU depreciation.

Revenue uses exact relative standalone prices and largest-remainder allocation. Recognition is limited to the current contract-liability bucket. Active treatment history prevents recognizing the same named obligation twice for a contract and pins continuing allocation inputs, configuration and dimensions. A correction uses the ordinary exact journal reversal; subsequent recognition can then select the corrected obligation again. Contract references must be unique within a book.

These are versioned journal-preparation treatments with immutable calculation evidence, posting policies, source deduplication and ordinary exact reversals. The protected register below supplies the controlled lifecycle. Generic treatments cannot post around its account protections. Zero-change generic finance measurements do not create journals; registered no-change decisions retain events without a journal.

## Provisions and contingencies

IAS 37 recognition, probability/best-estimate decisions, discount rates, contingent liabilities/assets and explanatory disclosures are reviewed host inputs. There is no dedicated protected provisions register. Use ordinary journals on compatible non-control liability/expense accounts and explicit schedules for approved unwinding amounts; retain source references, policy versions and valuation evidence. The host maintains the provision-class movement reconciliation and reassesses estimates at reporting dates.

For a reviewed obligation with present value 100 and settlement 110 in one year, initial recognition debits expense 100 and credits provision 100. A reviewed unwinding schedule debits finance expense 10 and credits provision 10. Settlement debits provision 110 and credits cash 110. An estimate change or unused amount requires its own reviewed journal, not silent editing of the original schedule or a fictional cash movement. Corrections use exact journal/schedule correction workflows. Provisions capitalized into an asset, including decommissioning changes, must also follow that asset's supported lifecycle rather than bypassing its protected accounts.

The `Finance` register accepts loans, leases and revenue contracts; it does not accept a provision kind. Its discounting arithmetic may inform a reviewed calculation but does not establish IAS 37 recognition or provide a provisions lifecycle.

## Protected finance contracts

The session's `finance` service requires `Permission::Finance` (`ledger.finance`) as well as ordinary posting/reversal permission for financial effects. Configuration reads require read access. It owns immutable `ledger_finance_contracts` and `ledger_finance_events`; book locks serialize mutations and source/key retries do not consume new sequences. `position()` and paginated `evidence()` accept ordinary book snapshots and recheck access.

```php
$contract = $accounting->finance->open(
    $book, 'open-loan-42', 'LOAN-42', '2026-01-01', 'loan_asset', $configuration,
    [
        'opening' => '950', 'rate_per_slot' => '0.1',
        'interest_cash_flow' => 'operating',
        'policy_version' => 'lending:1', 'basis_reference' => 'signed-contract:42',
        'payments' => [
            ['due_on' => '2026-06-30', 'payment' => '100', 'interest_paid' => '95'],
            ['due_on' => '2026-12-31', 'payment' => '1039.50', 'interest_paid' => '94.50'],
        ],
    ], dimensions: ['instrument' => 'loan-42'],
);
$first = $accounting->finance->period($book, $contract, 'loan-42:1', '2026-06-30', 1, 'First installment');
$position = $accounting->finance->position($snapshot, $contract);
```

Kinds are `loan_asset`, `loan_liability`, `lease` and `revenue`. Each registration pins an existing configuration and explicit policy/basis references. Its account roles follow the treatment table above. Loan assets additionally require `ecl.allowance` and `ecl.expense`. All roles must be compatible functional, non-control accounts; cash is explicitly cash-classified. Amounts are plain decimal strings. Registered calculations use half-even rounding, rates between zero and one with at most 18 decimal places, and at most 600 payment/discount slots. Dates identify explicit rate slots; annualization and day-count selection remain reviewed inputs.

Loan opening value is the actual amortized-cost advance, including approved integral fee/cost effects. Funding is posted atomically. Initial plans must finish exactly at zero under the selected per-slot interest convention. `period()` selects the next pinned row and cannot skip or repeat a slot. Accounting dates may follow the due date for late recognition but must follow the contract's recorded event dates. By default it posts scheduled cash and its explicit interest allocation. For loans, supply both `paidAmount:` and `interestPaid:` to record actual cash between zero and the scheduled amount. A missed installment can therefore accrue interest with no invented receipt. After a short payment, later periods require explicit actual cash/interest allocations until a reviewed modification replaces the plan. Cash interest cannot exceed cash paid.

For a lease, omit `opening` and supply `rate_per_slot`, future `payments`, `interest_cash_flow`, optional `commencement_payment` and policy/basis references. Future due dates must follow recognition. Unpaid lease liability is the exact discounted future payments rounded once; initial ROU cost is liability plus the separately identified commencement payment. Each period derives conserving interest from successive exact-PV balances so the liability closes without a phantom cent. ROU depreciation can use a separate reviewed schedule and contra account; this register does not yet own accumulated ROU depreciation, impairment, termination or remeasurement.

Contracts own exact complete dimension buckets for carrying, allowance and ROU cost. Different instruments can share chart accounts through distinct registered dimensions; two contracts cannot claim the same bucket. Once a finance account is registered, ordinary journals cannot escape its controls by adding or changing dimensions. Inventory, asset, associate and monetary registrations cannot adopt these accounts. Existing untracked balances must be reconciled before registration. Existing generic treatment journals are historical evidence and are not silently converted into registered contracts.

`measureLoss($book, $contract, $key, $date, $model, $reason)` accepts `stage` (integer 1, 2 or 3), `model_reference`, ordered `contractual` cash flows of `{period, amount}` and probability-weighted `scenarios` of `{weight, receipts}`, plus optional evidence. The original effective rate discounts all scenarios; probabilities sum exactly to one and the allowance cannot exceed gross carrying value. Stage selection, significant credit deterioration, default, the stage-1 default horizon and stage-2/3 lifetime scenarios remain reviewed host assessments. Stage 3 applies net-carrying interest to the next posted slot; no within-slot default-date proration is inferred. The difference from contractual gross accrual increases the allowance. Full contractual settlement releases any remaining allowance through credit-loss expense.

`writeOff()` removes the whole remaining loan carrying value, consuming allowance before recognizing additional loss. It retains a written-off balance, prevents further scheduled accrual and accepts approved supporting evidence. `recover()` records cash against credit-loss recovery without recreating the asset and cannot exceed the unrecovered written-off carrying amount. Partial write-offs and altered recovery/accrual schedules require further treatment work.

Advance-funded `revenue` registrations accept `transaction_price` and `obligations` of `{reference, standalone_price}` with the configured `contract.liability`, `cash` and `revenue.<reference>` roles. Funding creates the contract liability. `recognize()` takes an obligation reference and reviewed cumulative satisfaction fraction in `[0,1]`. PostgreSQL independently allocates consideration by relative standalone prices using largest remainders. Cumulative half-even recognition conserves each obligation's cents, records only the incremental movement and cannot decrease without correction. Repeating unchanged progress records decision evidence without recognizing revenue twice. Contract eligibility/progress evidence is supplied by the host; receivable billing, unbilled contract assets, variable consideration and contract modifications remain separate work.

`reverse()` corrects the latest active event with its exact journal, stage and state effects, including no-journal decisions. `cancel()` reverses funding only after all activity is corrected and permanently retires that contract identity. Correct supporting depreciation/other independently posted schedules separately. New activity rejects retired references; exact event corrections retain historical references. Deferred database checks reject standalone journal reversals and financial postings without corresponding event evidence. The fresh migration installs these protections together.

### Loan changes and carrying-date payoff

`modify()` implements reviewed non-derecognizing changes to fixed-rate future cash flows, without upfront modification fees. It discounts the revised contract at the original effective rate, recognizes the signed gross remeasurement through explicitly selected gain/loss accounts, and records a new plan identity. Loan assets require a complete revised ECL model; its contractual flows must match the revised payment slots, with extra zero-contractual slots permitted for delayed recoveries. Allowance changes and stage assessment are recorded separately from modification gain/loss. Borrowers do not supply an asset-loss model. Gain/loss accounts must be live functional income/expense accounts distinct from interest and credit-loss expense.

```php
$payments = [['due_on' => '2026-12-31', 'payment' => '990', 'interest_paid' => '90']];
$change = $accounting->finance->modify(
    $book, $contract, 'loan-42-change', '2026-06-30', $payments,
    $loanGain, $loanLoss, 'reviewed-non-derecognition:42', 'Revised contractual payments',
    lossModel: [
        'stage' => 2, 'model_reference' => 'modified-credit-model:42',
        'contractual' => [['period' => 1, 'amount' => '990']],
        'scenarios' => [['weight' => '1', 'receipts' => ['880']]],
    ],
);
$plan = $accounting->finance->plan($snapshot, $contract);
$accounting->finance->period($book, $contract, 'loan-42-revised-period', '2026-12-31', 1,
    'Recognize revised installment', planId: $change, paidAmount: '0', interestPaid: '0');
```

`plan()` returns snapshot-bounded payments, original rate, current `plan_id`, `next_line`, recognized carrying date, scheduled carrying value and actual-payment requirement. Modified periods must explicitly identify the active plan; an old worker cannot consume a replacement schedule using an obsolete identity. Exact-PV rollforwards conserve contractual cents and retain any adjustment from rounded-opening interest in immutable journal calculation evidence. Arrears accrue at the original per-slot rate rather than being silently erased by the revised plan's theoretical balance.

Declare optional integer `rate_period_months` (1–120) at registration to pin a calendar rate convention. Dates must follow that grid, with month-end dates retained from the original anchor. Revised schedules can extend maturity along the same grid. Legacy contracts without a declared convention can revise/shorten their remaining dated slots but cannot invent additional discount periods. Zero-payment slots retain deferred time; a postponed receipt cannot be moved across rate slots by collapsing dates. Original rates and conventions are never reset implicitly.

Changes and payoff occur at the funding date or latest recognized period/change/correction date. Recognize all due slots first, using actual cash including zero for missed payments. The model does not infer partial-slot interest, daily accrual, a new floating rate or fee amortization. The reviewed basis reference must establish that the recognized carrying amount and non-derecognition treatment are appropriate. Borrower changes reaching the quantitative ten-percent threshold are rejected for a separate derecognition treatment. That comparison uses unrounded original/revised discounted flows, including recognized arrears, so a final minor-unit rounding difference cannot change the threshold decision. Qualitative factors and upfront fees still need separate review/modeling; falling below the threshold alone does not establish eligibility.

`settle()` records complete legal discharge against actual cash at a recognized carrying date. Supply explicit cash interest, gain/loss accounts and basis evidence. It clears gross principal, releases the allowance through credit-loss expense, classifies principal/interest cash separately and records the residual settlement gain/loss. `position()['settled']` then prevents further activity until the settlement is corrected. Zero-cash discharge is supported and differs from a write-off: no retained recovery entitlement is invented. Partial principal prepayments and off-cycle accrual remain future capabilities.

Modifications and settlements retain calculation evidence checked independently in PostgreSQL. No-change modifications still create an immutable plan event without a journal. Corrections must follow dependency order: reverse dependent periods, then restore the prior modification/plan; reverse a settlement to restore exact principal, allowance and stage. Original definitions, posted history and saved cutoffs remain immutable as new lifecycle events are recorded.

## Localization treatments and worksheets

Built-in `ledger.tax` version `1` requires `operation`, `tax_code`, `tax_base`, `rounding`, `policy_version` and `basis_reference`, with optional `dimensions`. The tax code selects an effective exclusive noncompound rule from the pinned configuration, not a request-supplied rate. Optional configuration setting `tax_treatment_codes` limits allowed codes by operation.

| Operation | Additional inputs | Configuration roles |
|---|---|---|
| `withholding.payment` | `party_id`, gross `settlement`, explicit `cash_flow` category | `payable` control, `cash`, `tax.withholding.payable` |
| `withholding.receipt` | `party_id`, gross `settlement`, explicit `cash_flow` category | `receivable` control, `cash`, `tax.withholding.receivable` |
| `reverse_charge` | `recoverable_fraction` in `[0,1]` | `tax.reverse_charge.payable`, `tax.irrecoverable`, `tax.reverse_charge.input` when configured, otherwise `tax.input` |

Withholding settles the full obligation through cash plus a separate tax balance. Allocate its generated control item against the original document normally; release allocations before reversal. `tax_base` is explicit because it may exclude invoice VAT. Reverse charge posts tax only, leaving the supplier document/debt separate. Recoverable and irrecoverable parts conserve the rounded tax exactly. Cash-flow categories are `operating`, `investing` or `financing`.

`Tax\Kenya::vatPack($version, $validFrom, $validUntil, $policyReference, $roles)` binds an existing reviewed chart to the ten roles shown in its implementation, with distinct posting accounts. The pack contains general VAT 16%, zero VAT and withholding VAT 2%, the KRA source/check date and organization policy evidence. It checks the entity's `KE` country through ordinary pack installation. Exempt supplies are distinct from zero-rated supplies. Withholding requires an appointed-agent/eligibility assessment. The selected dates are explicit policy coverage, not a claim to model every historical rate change.

The pack maps `tax.reverse_charge.input` to a separate pending asset. Imported-service VAT does not enter eligible input VAT automatically; record the tax payment and approved claim reclassification separately. Its `Kenya VAT ledger worksheet` distinguishes domestic output, eligible input, withholding credits, separately assessed import VAT and pending import tax. It reports mapped ledger activity, including corrections and clearing journals. It is not an iTax submission or a full taxable-base/return-box calculation and excludes opening-credit adjustments. Review clearing-account policy when using movement rows.

Save pinned worksheets using `reports->saveConfiguredStatement($book, $key, $configuration, $name, $columns)`. The saved definition must exactly match the pack, each column must fall inside its coverage dates, and results retain the configuration ID/content/storage hashes with the ordinary numerical cutoffs and evidence hash. A later backdated correction requires a new report identity/cutoff; the earlier result remains reproducible. Existing unconfigured saved-statement identities remain compatible.

## Tax register and return lifecycle

The session's `taxes` service owns nonfinancial claim/return evidence in `ledger_tax_decisions`, `ledger_tax_returns` and `ledger_tax_return_events`. Mutations require `Permission::Tax` (`ledger.tax`); register, decision history, preview and saved evidence require book read access. Tax decisions and returns can be recorded after financial periods close because they do not post journals or change financial balances. All commands serialize on the book lock and preserve actor, firm, creation time, sequence and retry identity.

`register($snapshot, $startsOn, $limit, $afterSequence, $afterLine, $afterCode)` derives immutable source lines from `documents->issueTaxed()` and `ledger.tax` treatment journals. Entries include source references, party IDs, configuration IDs, signed taxable bases, tax amounts and expected account movements in book functional minor units. PostgreSQL checks the pinned effective rules and independently recomputes document bases/components, including discounts, inclusive and ordered compound taxes. A compound tax's base includes its preceding-tax basis rather than silently repeating the net price. Foreign amounts use the original document's recorded rate/rounding. Reversals retain their original business reference and source identity but enter the reversal accounting period. Continue pagination with the last row's complete `(sequence, line_no, tax_code)` cursor. Untaxed lines retain an empty code; they are not inferred to be exempt or zero-rated. Plain journals/documents without tax-calculation evidence cannot supply invoice-level taxable bases; their scoped tax postings need explicit reconciliation.

Install a new immutable configuration version containing `settings.tax_returns.<scheme>`. A scheme supplies:

- `accounts`: distinct configured balance-sheet tax roles defining its reconciliation scope.
- `codes`: explicit included tax-code classifications, including an empty-code classification when untaxed supplies have been reviewed.
- `payable_terms`: exact coefficients `-1`, `0` or `1` over `output_tax`, `input_claimed`, `withholding_claimed`, `reverse_charge_tax` and `withholding_payable`.
- `reconciliations`: named purposes with allowed `roles` and an exact `coefficient` applied to the signed posting amount. Settlements/reclassifications ordinarily have coefficient zero; reviewed return adjustments can affect the declaration.
- Optional `period: calendar_month` to require complete calendar months.

`Kenya::vatPack()` includes the `vat` policy from `Kenya::vatReturnPolicy()`: domestic output less claimed input and withholding credits, separately disclosed import assessment/agent-withheld tax, complete calendar months and explicit settlement/reclassification/adjustment purposes. An installed pack's content cannot change in place; select a new pack version when adopting these settings. Scheme configuration is a reviewed accounting/reporting policy, not an official iTax file layout.

```php
$accounting->taxes->decide(
    $book, 'input-review-42', $configuration, 'vat', '2026-01-31',
    $purchaseJournal, 1, 'KE-VAT-STANDARD', 'claim', '0.75',
    'Reviewed recoverability and invoice evidence', ['invoice_evidence' => 'supplier-42'],
);
$snapshot = $accounting->reports->snapshot($book, '2026-01-31');
$preview = $accounting->taxes->preview($snapshot, $configuration, 'vat', '2026-01-01');
$return = $accounting->taxes->prepare($snapshot, 'vat-january', $configuration, 'vat', '2026-01-01');
$accounting->taxes->finalize($book, $return, 'approve-vat-january', '2026-02-01', 'Reviewed reconciliation');
$accounting->taxes->file($book, $return, 'record-vat-receipt', '2026-02-02', 'external-receipt-42', 'Record actual external filing');
```

Claim identities are `(scheme, source journal, register line number, tax code)`. `claim` selects a cumulative fraction in `[0,1]`, with at most 18 decimal places; `defer` and `deny` select zero. A change posts no financial journal: the return recognizes only the difference between independently rounded cumulative tax amounts. Increasing eligibility from 50% to 100% in a later period claims only the remaining half. Reducing eligibility recaptures the previous deduction in the decision's effective period. Effective dates cannot precede the source or an existing later decision. Recorded sequences retain when a backdated review actually became known. `decisions()` provides paginated, snapshot-bounded review history.

Exact source journal reversal automatically recaptures its reviewed fraction in the correction period, including partial claims and already-filed earlier periods. Supplier credits are separate signed source documents and need their own reviewed claim decision; the engine does not infer which original invoice or claimed fraction a commercial credit corrects. Eligible financial-asset recognition, blocked-input write-offs and pending-to-eligible GL reclassification remain explicit accounting journals/treatments; changing a tax decision alone never rewrites the balance sheet.

When the source configuration requires payment before imported-input deduction, approve register line 2 using evidence `payment_journal_id`. The proof must be an unreversed two-line journal debiting the full assessment's tax payable and crediting cash for the same functional amount, dated no later than the decision. One payment journal cannot support another assessment. A later payment reversal marks the claim invalid until corrected or recaptured. This first implementation does not allocate one payment across multiple assessments or accept partial-payment proof. The financial payment/reclassification remains separately posted and reconciled.

`reconcile()` references an actual posting line in a manual journal and an allowed configured purpose. It cannot double-classify generated tax-document/treatment lines. Return reconciliation compares signed register movements plus classified manual postings against actual tax-account GL movement. Every manual posting needs an explanation even when unclassified debits and credits net to zero. Payments classified as settlements explain ledger reductions without reducing declared output tax. Classification is an explicit reviewed decision; the engine does not infer a transaction's economic purpose from balancing alone.

`prepare()` saves the database-computed register slice, claim movements, base/category totals, reconciliation, unresolved items, configuration hash and numerical cutoff. It preserves the PostgreSQL-normalized JSON directly, including empty objects, and exposes its SHA-256 hash through `evidence()`. Unreviewed current-period input, unmapped codes, invalid payment proofs or unexplained postings block `finalize()`. New backdated source/decision changes make an unfinalized preparation stale; prepare a new version with `previousId:` rather than overwriting it. Return periods cannot overlap except through the same period's amendment chain, and amendments cannot branch or move backwards in knowledge.

Supply `priorPeriodId:` to carry the exact credit from the current filed preceding return. The periods must be contiguous and the previous version must actually have a recorded filing. An available filed credit cannot silently be omitted or replaced with an imported amount. If that preceding return is later amended and filed, an already-prepared successor must be replaced before finalization. An initial imported credit instead takes `openingCredit:` and a nonempty `openingReference:` supporting it.

`file()` records an actual external receipt against a finalized version; it sends no network request and makes no claim of authority-side verification. Filing evidence is retained even if later postings mean the approved figures now require amendment. `void()` retires a current prepared/finalized version; filed history requires a linked amendment. Receipt references are unique within a book. The delivery service below supplies transport history.

## Durable statutory delivery

`statutory` owns `ledger_statutory_submissions` and `ledger_statutory_events`. Queue/worker/cancellation commands require `Permission::Deliver` (`ledger.deliver`); evidence reads require book read access. Records are append-only, RLS-scoped, audit/feed indexed and book-serialized. Delivery events use recorded time and book sequence; they do not post journals or alter tax claims, invoice allocations, cash or filed-return status.

```php
use Noria\Ledger\Statutory\SubmissionRequest;
use Noria\Ledger\Tax\Kenya\EtimsAdapter;

$submission = $accounting->statutory->enqueue(new SubmissionRequest(
    $book, 'etims-invoice-42', 'invoice', $invoiceJournal,
    'ke.etims.P051234567A-00', 'kra.etims.oscu', 'oscu-2.0', 'sandbox',
    'etims-invoice-42', $sale->json($device),
));

$accounting->statutory->dispatch($book, $submission, new EtimsAdapter($client));
```

`SubmissionRequest` selects `invoice`, `credit_note` or `tax_return`. Invoice sources are retained sales documents; exact sales-invoice journal reversals can be queued as credits. The database checks source ownership, kind, currency/scale and gross against actual control postings. Return sources must identify a current finalized return. The payload is UTF-8 JSON-object, XML or CSV text, at most 10 MiB. Its exact bytes are hashed, including whitespace, and retained without JSON re-encoding. A separate hash pins the immutable source snapshot. Changed payloads cannot reuse a retry key.

`channel` identifies one canonical provider account/branch; `environment` explicitly selects `sandbox` or `production`. Keep channel naming stable so aliases cannot accidentally become separate delivery routes. `adapter` and `adapterVersion` pin the implementation used to interpret that source/payload. `requestReference` is the immutable external deduplication identity and must be honored by the concrete provider adapter. It is unique within book/channel/environment, including replacement versions. The package does not infer legal invoice numbering or statutory identifiers from opaque journal/party references.

Queue inside an enclosing host transaction when financial posting and delivery intent need to commit atomically. `claim()`, `resolve()` and `dispatch()` must run after the outermost transaction commits. `dispatch()` durably claims work, commits, validates/sends or looks up outside every ledger transaction, then records the outcome in a new transaction. Host adapters must not perform network work during local `validate()`. Endpoint credentials and worker scheduling belong to the host integration.

Implement `Statutory\Adapter::name()`, `version()`, `validate(Submission)`, `submit(Submission): Outcome` and `lookup(Submission): Outcome`. `Submission` exposes exact payload/hash, original financial evidence/hash, tenant/book, route/environment, request reference, claim token, mode and send-attempt count. Credits also expose their original provider receipt. Local validation checks the actual provider schema and financial correspondence. Adapter identity is checked before granting work. Validation exceptions produce a rejected, unsent request; transport/lookup exceptions conservatively record `uncertain` with a retry delay.

Outcomes are:

| Outcome | Required meaning |
|---|---|
| `accepted` | Provider business acceptance, with a nonempty scoped receipt reference |
| `rejected` | Definitive nonacceptance; corrected content needs a linked immutable replacement |
| `retry` | Send was definitively not accepted/transmitted; the same bytes/reference may be sent again |
| `uncertain` | Acceptance is unknown; subsequent work must perform lookup |
| `not_found` | Lookup authoritatively establishes nonacceptance and makes resend safe |

A generic HTTP failure or weak/eventually consistent 404 is not authoritative absence. Map it to `uncertain`. Concrete adapters must supply provider idempotency and authoritative lookup semantics; the core cannot establish exactly-once external effects on a provider without those capabilities. Evidence accepts exact JSON values; preserve a raw response as text when ordinary JSON parsing would lose numeric precision. `delaySeconds` bounds retry scheduling to 0–86,400 seconds.

Worker leases last 1–900 seconds. Only one current claim is granted; repeating the same claim key does not issue the grant again. Lease expiry permits another worker to claim lookup. A newer claim fences the old token, including late accepted responses. A result from the still-current worker can be recorded after its lease expires if no replacement claim has won the book lock. Lookup can resolve ambiguity after source correction or book retirement. Ready sends exclude reversed documents, retired books and stale/superseded/voided returns. Send budgets are immutable (1–1,000 attempts); lookup does not consume the send budget or turn exhaustion into an unsafe resend.

`pending()` lists currently actionable work; `evidence()` returns the pinned definition/source, operational state and paginated event history. Operational readiness depends on database time, while event ordering uses immutable book sequences. `cancel()` permits only queued, definitively retryable or exhausted work. Ambiguous/inflight or accepted requests cannot be cancelled. `replacesId:` creates a nonbranching correction only after definitive rejection, cancellation or exhaustion, retaining the same source/route and a new external retry identity. Accepted financial invoices require a credit rather than payload replacement.

Queue credits only after original acceptance is known so the payload can contain the correct provider reference. `originalId:` must identify an accepted invoice on the same route/environment/currency. Counterparties must belong to the original invoice, and active/accepted credit reservations cannot exceed original gross in source-currency minor units. Ambiguous credits keep their reservation; rejected/cancelled/exhausted credits release it. Receipt uniqueness, source uniqueness, replacement rules and worker fencing are independently checked in PostgreSQL.

Transport acceptance does not automatically call `taxes->file()` or allocate accounting receipts. Record actual filing evidence only when the provider has established filing, and keep invoice settlement tied to real payments. This milestone ships the complete delivery core and versioned adapter contract with fixture-backed end-to-end tests. It does not ship concrete OSCU/VSCU HTTP requests, KRA authentication, item/branch/tax-code mapping, approved iTax exports, provider webhook authentication or KRA sandbox certification.
