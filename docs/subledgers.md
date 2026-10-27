# Valuation and consolidation contracts

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

Cross-pool supplier-return provenance, batch/serial/expiry operations, serial-specific cost selection, and automated historical reposting remain separate capabilities. A plain receipt does not imply a linked return.

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

- `revalue($book, $asset, $key, $date, $uplift, $surplusAccount, $residual, $plan, $policyVersion, $reason)` recognizes due/stub depreciation, then revalues the carrying amount upward by the explicit uplift. It eliminates accumulated depreciation against gross cost, adjusts gross cost so the new carrying amount equals the revalued amount, and credits an equity revaluation-surplus account. A replacement residual/plan conserves the new carrying amount. `revalue()` retries and reverses like other events. The surplus account must be a live, functional-currency, non-control equity account. Assets with outstanding impairment are rejected because recovery may belong in profit or loss, not wholly in surplus. Likewise, `impair()` rejects assets with active revaluation history until a surplus-loss allocation policy is implemented. Downward revaluation and recoverable-amount impairment-reversal calculations remain separate capabilities.

Example: cost 12,000 with January depreciation 1,000 carries 11,000. A reviewed uplift of 3,000 revalues to 14,000. Gross cost becomes 14,000, accumulated depreciation and impairment become zero, and revaluation surplus credits 3,000. Reversing the event restores gross cost 12,000, depreciation 1,000, and the prior plan.

Component-specific identities and schedules, construction-in-progress/register transfers, and new recoverable-amount impairment-reversal calculations are not implemented. An exact correction reversal is not an IAS 36 impairment-reversal calculation.

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

Automatic acquisition-date fair values, full-goodwill and acquisition-date NCI measurement, changing control, equity-method investments, intercompany matching, unrealized profit/deferred-tax policies, foreign-operation disposal and CTA recycling, hyperinflation adjustments, and statutory group disclosures remain unimplemented. Assess each explicitly before using these reports for statutory consolidation.

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

This is the proportionate (partial-goodwill) method using explicitly selected acquisition book balances. Automatic fair-value remeasurement, full goodwill including measured NCI, goodwill impairment, additional investment cost/ownership changes, and step acquisitions are not computed. Acquisition fair-value adjustments, deferred tax, distributions and other group-specific eliminations require reviewed treatment inputs and journals.
