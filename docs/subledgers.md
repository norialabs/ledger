# Valuation and consolidation contracts

These modules are available on the scoped `Session`. Account IDs below refer to configured posting accounts in the indicated book. Host approvals, source documents, and policy selection remain explicit inputs. The modules perform no provider calls.

## Foreign open-item revaluation

```php
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Rounding;

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
use NoriaLabs\Ledger\Inventory\Quantity;
use NoriaLabs\Ledger\Inventory\ValuationMethod;
use NoriaLabs\Ledger\Money\Money;

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

Manufacturing/WIP, supplier returns, serial-specific cost selection, and automated historical reposting remain separate capabilities. A plain receipt does not imply an original-cost return. Do not infer a return cost from today's moving average.

## Fixed-asset financial lifecycle

```php
use NoriaLabs\Ledger\Assets\AssetRequest;
use NoriaLabs\Ledger\Assets\StubConvention;
use NoriaLabs\Ledger\Scheduling\Schedule;

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

The plan must sum to cost minus opening depreciation, opening impairment, and residual. Supply explicit nonnegative `ScheduleLine` values for an approved method. Dates must strictly increase and cannot precede recognition/availability. Equal allocation is a convenience; the package does not infer useful life, residual, capitalization eligibility, tax allowances, or units of production.

`depreciate()` posts due active plan lines once, including under concurrent runners. To make a reviewed catch-up posting in a later open period, pass `postingOn:` on or after the requested cutoff and existing asset history. Only slots due by the requested cutoff are included. Repeating the same catch-up does not post them again.

Before an estimate change, impairment, or disposal, the command posts due depreciation. Partial-period depreciation uses the asset's immutable `StubConvention`: `ActualDays` prorates inclusively within the next plan interval, `CompletedPeriods` charges no partial interval, and `FullPeriod` charges the full next interval. Select a reviewed convention when capitalizing; the engine does not claim one convention suits every asset or method.

- `revise($book, $asset, $key, $date, $residual, $plan, $policyVersion, $reason)` starts a prospective plan after the change date and availability date.
- `impair($book, $asset, $key, $date, $loss, $lossAccount, $residual, $plan, $policyVersion, $reason)` recognizes a positive impairment expense and a replacement plan that conserves the reduced amount.
- `dispose($book, $asset, $key, $date, $proceeds, $proceedsAccount, $gainAccount, $lossAccount, $reason)` clears cost/contra balances and recognizes proceeds less carrying amount as gain/loss. Cash proceeds receive an explicit investing classification.
- `reverse($book, $event, $key, $date, $reason)` reverses the latest active event, its exact journal, partial-period depreciation, and plan change. Restore downstream events first.
- `cancel($book, $asset, $key, $date, $reason)` cancels capitalization only after downstream events have been reversed. It reverses the original journal instead of recording an invented disposal loss. Cancellation is final for that asset identity.

Example: cost 12,000, monthly depreciation 1,000, no residual, disposal February 14. With actual days, January depreciation is 1,000 and February depreciation is 500. Proceeds of 8,000 produce a loss of 2,500. Reversing disposal restores February's 500 depreciation along with disposal effects; January's posted depreciation remains.

Asset additions/components, construction-in-progress transfers, OCI/upward revaluation, partial disposals, and new recoverable-amount impairment reversals are not implemented. An exact correction reversal is not an IAS 36 impairment-reversal calculation.

## Group reporting

```php
use NoriaLabs\Ledger\Consolidation\Member;

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

Members contribute 100% of their balances; this is aggregation/translation with explicit eliminations, suitable for a reviewed wholly-owned-group policy. It is not a general ownership calculator. All books belong to the session's tenant. Select one book per legal entity to avoid double-counting parallel treatments. All snapshots must have the same date and cannot claim future sequence cutoffs. Use `reports->snapshots()` for a consistent capture across books.

The target is a dedicated elimination/reporting book with the group chart and presentation currency. Post approved intercompany/investment/profit-elimination journals there before taking its snapshot. Target balances are already in group currency. Every nonzero source closing balance or period movement requires a target posting account of the same type.

Foreign assets/liabilities use the supplied closing rate. Income/expense balances and period activity use the supplied income rate. The host must determine whether a representative average is appropriate; transaction-date translation and multiple within-period rates are not computed automatically. Historical equity uses either an explicit account rate or a debit-positive group-currency `Money` amount from the approved translated equity rollforward. Exact amounts avoid manufacturing approximate blended rates for contributions or retained earnings.

Example: USD cash 200 at closing 140, capital 100 at historical 130, and income 100 at average 135 produce group cash 28,000, capital 13,000, profit 13,500, and a credit translation reserve of 1,500. Income-statement activity excludes fiscal closing transfers.

The translation reserve offsets the exact translated net balance. The separate rounding reserve absorbs only the remaining difference caused by independently quantized accounts. Neither becomes operating income. Retained earnings from previous periods require an approved historical translation rollforward; applying the current period's average to all prior profits is not supplied as an automatic policy.

Reports are immutable materializations retaining rendered labels, all numerical inputs, cutoffs, policy, and actor/firm. Backdated entries do not rewrite a saved report. `evidence()['result_hash']` is the SHA-256 of the exact UTF-8 `result_json` bytes supplied alongside it; re-encoding that JSON may change its byte hash. This is reproducibility evidence, not a digital signature or proof that the host's policy/mappings are correct.

Automatic ownership/NCI, acquisitions/goodwill, changing control, equity-method investments, intercompany matching, foreign-operation disposal and CTA recycling, hyperinflation adjustments, and statutory group disclosures remain unimplemented. Assess each explicitly before using these reports for statutory consolidation.
