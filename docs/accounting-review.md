# Accountant review cases

Status: pending independent accounting review. Record the outcome under [Review record](#review-record). The numerical fixtures below are executable examples, not approvals of an organization's accounting policy or statutory treatment.

Review core currency, inventory, assets, year-end/reporting and VAT cases first. Group and specialized cases follow their supported scope; industry illustrations at the end do not substitute for review of the underlying accounting contracts. New reporting and income-tax cases in [pending-code.md](pending-code.md) must be added to the relevant executable suites and reviewed when implemented.

## Foreign-currency settlement

Invoice USD 100 at 130 KES/USD, then receive USD 100 converted at 135 KES/USD:

| Event | Debit KES | Credit KES |
|---|---|---|
| Invoice | USD receivable 13,000 | Income 13,000 |
| Receipt | Bank 13,500 | USD receivable 13,500 |
| Settlement adjustment | USD receivable 500, with zero original-currency movement | Realized exchange gain 500 |

Both USD original amounts become fully allocated, and the functional control balance is zero. Releasing allocation reverses the exact FX adjustment and restores the open items. Partial allocations conserve the original carrying-value remainder.

Verify rate provenance, permitted rate dates, rounding policy, gain/loss classifications, and settlement dates. Executable reference: `SettlementTest.php` and `PrecisionTest.php`.

## Unrealized foreign exchange and partial settlement

Invoice USD 100 at 130 KES/USD. A reporting-date rate of 140 increases the receivable to KES 14,000 and credits unrealized gain 1,000. Full settlement at 145 first reverses unrealized gain 1,000, then recognizes realized gain 1,500. Both control and open-item balances finish at zero.

For a partial USD 40 settlement at 145, the implementation clears the whole item's unrealized adjustment and recognizes realized gain 600. The unpaid USD 60 carries KES 7,800 until a subsequent explicit valuation; revaluing at 150 makes it 9,000. Releasing the partial settlement clears that new unrealized adjustment and restores the original invoice and receipt carrying amounts. Payables produce the corresponding opposite gain/loss directions.

Review the reversal policy, timing of subsequent unpaid-balance valuation, cross-year presentation, monetary-item eligibility, and gain/loss classification. This is not automatic proportional retention of unrealized adjustments. Executable references: `RevaluationTest.php` and `SubledgerCommitTest.php`.

## Foreign bank and loan balances

An explicitly registered USD bank holding 100 at a functional carrying value of KES 13,000 is worth 14,000 at a closing rate of 140. Revaluation retains USD 100, debits bank 1,000, and credits exchange gain 1,000. Cash flow shows exchange effects of 1,000 separately from the original funding. A USD 100 loan credit balance produces exchange loss 1,000 at the same closing rate.

If a complete USD 100 conversion at 145 leaves zero dollars and a KES credit remainder of 1,500 in the foreign bank account, revaluation adjusts that functional remainder to zero without inventing foreign quantity. The host selects reviewed realized/unrealized classifications. Corrections reverse the latest active valuation exactly; later postings cannot silently backdate before recorded valuation history in the same dimension bucket.

Review monetary-item eligibility, currency bucket completeness, overdrafts, rate provenance, realized/unrealized classification, cash-flow exchange presentation, and restatement procedures. Executable references: `MonetaryTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Stock cost and write-downs

Receive 10 units for 1,000, then 20 for 2,400. Issue 15: FIFO expense is 1,600 and remaining inventory 1,800; moving-average expense is 1,700 and remaining inventory 1,700. Receiving 0.3 units for 0.01 and issuing 0.1 three times must expense exactly 0.01 in total.

A remaining-stock value adjustment distributes its total without losing a cent. Writing all remaining value down to zero leaves physical quantity tracked. Correcting an issue restores its original quantity/cost layers; correct later dependent moves first. Negative stock/value and silent backdating are rejected.

Review the valuation boundary, base unit, included purchase costs, lower-of-cost treatment, adjustments for goods already sold, physical count reconciliation, and treatment of returns. Executable references: `InventoryTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Linked customer returns and warehouse transfers

Issue 4 units originally costing 40. Later receipts change current unit cost to 20. Returning 2 of the original units restores inventory 20 and credits the original counter account 20; it must not use today's 40 cost. Partial returns of 0.1 three times against an original 0.3-unit issue costing 0.01 must restore exactly 0.01 in total. Returning to another warehouse changes inventory location/dimensions while preserving the original expense dimensions. Create the customer credit note separately.

Transferring stock between warehouses issues from the source and receives the exact same quantity/cost into the destination. The balance-sheet clearing account nets to zero across the complete book. Both sides commit or roll back together, including when the second movement fails. Transfer corrections restore both sides together after downstream consumption/receipts have been corrected; one-sided corrections are rejected.

Review SKU/base-unit equivalence, original aggregate-cost allocation for partial returns, inspection/quarantine policy, clearing dimensions, and separation of stock returns from customer credits. Executable references: `InventoryFlowTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Manufacturing and work-in-progress

Receive 10 units of raw material for 100, then consume 4 to assemble 1 finished unit. The finished unit is valued at the exact 40 consumed. Raw material inventory falls to 60, finished inventory rises to 40, and the WIP clearing account nets to zero. Consuming 0.3 of one component costing 0.01 and 0.1 of another costing 0.01 to assemble one unit produces a finished unit at 0.02, conserving every minor unit. Reversing production restores both the raw material and finished inventory exactly.

Recognizing labour/overhead 15 in WIP and passing an explicit conversion-cost allocation of 15 produces finished inventory 55 from materials 40. Reversal restores components and returns 15 to WIP. A duplicate component identity cannot inflate output cost, and a reversal cannot substitute another production's components. Zero-cost output remains quantity-tracked. Review cost eligibility, WIP funding, allocation across outputs, and finished-goods base units. Executable references: `InventoryTest.php` and `SubledgerCommitTest.php`.

## Supplier returns and purchase-price variance

Receive ten units for 100, then ten for 300. Under moving average, two units currently carry 40, while the original first-receipt price for two is 20. Returning two against the first receipt credits inventory 40, debits supplier-return clearing 20, and debits the explicitly selected purchase-price variance account 20. Remaining stock value is 360 for eighteen units. Under FIFO the same original layer costs 20 and no variance is posted. If freight increased that original FIFO layer from 100 to 150, returning all ten removes carrying value 150 and recognizes the difference of 50 explicitly.

Original-price cents are conserved through fractional returns. A FIFO receipt already consumed cannot be returned using a newer unrelated layer. Concurrent returns cannot exceed eligible quantity. Corrections reverse stock, clearing, variance, and layer effects together. A separate supplier credit adjusts payables/taxes; a stock return does not imply a cash refund.

Review the supplier's actual credit entitlement, treatment of freight/write-down variances, fungibility under moving average, physical custody, and clearing reconciliation. Executable references: `InventoryFlowTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Asset depreciation, impairment, and disposal

Capitalize equipment at 12,000 with twelve monthly depreciation amounts of 1,000 and zero residual. With actual-day partial-period depreciation, disposal on February 14 recognizes January's 1,000 plus February's 500. Proceeds of 8,000 result in a loss of 2,500, and cost/contra GL balances clear. Reversing disposal restores the exact disposal and February depreciation effects while retaining January depreciation.

Alternatively, after January depreciation, recognize impairment 3,000 and replace the future plan with amounts totaling 8,000. Final accumulated depreciation is 9,000, accumulated impairment 3,000, and carrying value zero. An imported asset at cost 10,000 with opening depreciation 3,000, opening impairment 1,000, and residual 1,000 has future depreciation totaling 5,000.

Review capitalization eligibility, dates available for use, useful life, residual, selected partial-period convention, impairment recoverable amount, replacement plans, tax differences, and disposal gain/loss presentation. Recovery and surplus-loss cases are covered below. Executable references: `AssetTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Asset upward revaluation through equity surplus

For cost 12,000 with January depreciation 1,000 and no impairment, carrying value is 11,000. A reviewed fair-value uplift of 3,000 revalues to 14,000. The implementation eliminates accumulated depreciation against gross cost, adjusts gross cost so carrying equals 14,000, and credits an equity revaluation-surplus account 3,000. After revaluation, gross cost is 14,000, accumulated depreciation is zero, and carrying value is 14,000.

After January impairment of 3,000, carrying is 8,000. An uplift of 2,000 with a reviewed no-loss carrying ceiling of 11,000 credits profit or loss 2,000, rather than surplus. If 8,000 is depreciated by 727.28 in February, a recoverable amount of 12,000 with a depreciated no-impairment ceiling of 10,000 permits recovery of only 2,727.28, leaving impairment 272.72 and carrying value 10,000. Recoveries retain both valuation inputs and reverse exactly.

A revaluation surplus of 3,000 followed by impairment 5,000 debits surplus 3,000 and expense 2,000. Recovery to the earlier carrying amount credits profit or loss 2,000 and restores surplus 3,000. A 25% disposal releases 750 of that surplus and can transfer it directly to retained earnings; only 2,250 remains available against future losses on the continuing asset.

Review recoverable/fair value, the independently calculated depreciated no-loss ceiling, per-asset surplus/loss history, replacement depreciation, imported attribution budgets and direct-equity disposal transfers. Signed revaluation changes and impairment recoveries are independently checked in PostgreSQL. Executable references: `AssetTest.php`, `MigrationTest.php` and `SubledgerCommitTest.php`.

## Capital improvements and proportionate partial disposals

For cost 12,000 and January depreciation 1,000, an approved addition of 3,000 produces gross cost 15,000 and carrying value 14,000. Residual 1,000 requires future depreciation of 13,000. A full sale for 14,000 clears gross cost 15,000 and accumulated depreciation 1,000 with no gain/loss. Correcting the sale and addition restores original gross cost 12,000 and carrying value 11,000.

Alternatively, without the addition, sell 25% at January 31. Relieve cost 3,000 and accumulated depreciation 250. Proceeds 2,500 against relieved carrying value 2,750 produce loss 250. Remaining cost is 9,000, depreciation 750, carrying value 8,250. If the asset also had accumulated impairment 3,000, relieve impairment 750; carrying value relieved is 2,000 and proceeds 2,100 produce gain 100. The remaining asset carries 6,000. Corrections restore exact original balances and the earlier plan.

Review capitalization versus repair expense, availability dates, component identification, proportional allocation suitability, minor-unit rounding, residual/replacement plans, and cash-flow classifications. An explicit proportionate fraction does not identify a separately measured component automatically. Executable references: `AssetTest.php` and `SubledgerCommitTest.php`.

## Configured tax documents and explicit rounding

The test rate of 16% is illustrative and is not an assertion of a statutory rate or eligibility. A price of 100 for quantity 1.5 produces net 150, tax 24, and gross obligation 174. Sales and purchase credits reverse the appropriate control and tax directions. Inclusive price 116 for quantity 1.5 with discount 10% produces net 135, tax 21.60, gross 156.60; debtors can split that gross.

For net 0.03 with two illustrative 20% taxes, individually rounded taxes are 0.01 each while rounded gross is 0.04. The -0.01 residual is posted to an explicitly selected rounding account, never silently absorbed. Split foreign obligations of USD 0.01 twice at 1.5 functional units per dollar round to 0.04 functional units, while the USD 0.02 charge rounds to 0.03. The functional difference of 0.01 requires an explicit rounding role.

Review jurisdiction, eligibility, recoverability, inclusive/compound order, discount rules, permitted rounding, tax account mappings, recognition dates, and statutory return treatment. Configurations preserve exact rule versions and document calculations but do not establish tax compliance. Executable references: `IndustryWorkflowsTest.php` and `TaxTest.php`.

## Foreign group translation and eliminations

A USD subsidiary has cash 200, capital 100, and income 100. At closing rate 140, historical capital rate 130, and approved income average 135, translated cash is KES 28,000, capital 13,000, income 13,500, and a credit translation reserve 1,500. Independently rounded account differences belong in a separate rounding reserve. Historical equity can instead use exact approved translated rollforwards.

For two same-currency books, combine source balances with explicit elimination-book journals. A saved report remains unchanged after subsequent backdated source entries, and reading it requires current permission on every source book. A second accounting-treatment book for the same source entity must not be counted twice.

### Non-controlling interest

A USD subsidiary owns cash 100 against capital 100, controlled 80%. At closing rate 140 and historical capital rate 130, closing-rate net assets are 14,000. NCI is 2,800. Parent capital is 10,400 and the parent's translation reserve is 800 credit, giving group equity 14,000. A same-currency member creates no translation reserve. A 70%-owned member with net liabilities 100 has deficit NCI of 30; losses are not capped at zero. Period profit/loss attribution is reported separately from full consolidated income and expenses.

Review the ownership fraction, control assessment, closing-rate net-asset measurement of NCI, attribution of translation differences, deficit interests, and parent/NCI period loss attribution. Acquisition-date fair-value remeasurement and measured-NCI methods are not computed. Executable reference: `ConsolidationTest.php`.

### Investment elimination and goodwill

A parent pays 120 for a wholly owned subsidiary with acquisition-date book net assets of 100. Acquisition equity and investment are eliminated and goodwill 20 is recognized. Subsequent profit 40 leaves goodwill 20 and raises group assets to 160, both before and after the source closes its earnings into retained earnings. Paying 80 instead produces an acquisition-period bargain gain 20, carried in group retained earnings in subsequent periods. Paying 90 for 80% produces goodwill 10; after profit 40, NCI is 28 and profit attribution is parent 32/NCI 8.

For the 80% foreign case, acquisition rate 130 gives consideration 11,700; closing rate 140 translates goodwill to 1,400. Net assets 140 translate to 19,600, NCI is 3,920, and profit at 135 is 5,400 (parent 4,320/NCI 1,080). The parent's translation reserve credits 1,060. Acquisition snapshots and consideration are retained as evidence rather than inferred from closing balances.

Fair-value inputs can increase an acquired asset by 40 and create deferred tax of 12 at 30%, giving fair-value net assets 128 from book net assets 100. Paying 110 for 80% gives partial goodwill 7.60, or full goodwill 17 with measured NCI of 35. Subsequent uplift depreciation of 10 reverses deferred tax 3 and produces expense 7: parent 5.60 and NCI 1.40. Closing NCI is 24.20 proportionate or 33.60 measured. A deductible provision of 20 creates a tax asset of 6 only when eligibility is explicitly affirmed.

Review consideration, identifiable fair values, incremental tax bases, enacted tax rates, deferred-tax asset eligibility/exemptions, measured NCI, subsequent fair-value/tax movements and their profit/OCI attribution. The engine retains the reviewed inputs rather than inventing valuations. Executable reference: `ConsolidationTest.php`.

Review the group perimeter, ownership/control, acquisition cutoff, historical equity rollforwards, income-rate suitability, elimination entries, differences in accounting policy, reserves, and period comparability. Controlled members contribute 100% of assets, liabilities, income, and expenses. Executable reference: `ConsolidationTest.php`.

## Associates and equity-method investments

Pay 100 for 30% of reviewed fair-value net assets 300. Embedded goodwill is 10. Full-investee profit 60 increases the investment by 18; OCI 20 increases it by 6; distributions 10 reduce it by 3, leaving carrying value 121. Income is 18, not 21. If fair-value net assets are instead 400, the acquisition bargain of 20 increases the investment to 120 and is recognized once as income.

A share of losses of 150 against carrying value 100 recognizes 100 and suspends 50. Subsequent shares of profit of 30 then 30 first absorb that suspended 50 and recognize only the remaining 10. An explicit support obligation of 20 instead recognizes a liability 20 and leaves suspended loss 30; paying that obligation clears the liability without inventing another investment asset. P&L and OCI suspension buckets are tracked separately under the same loss limit.

Impairing an investment from 100 to 50, then incurring a share of loss of 75, leaves investment zero and suspended losses 25. Recovery with recoverable value 60 and no-impairment carrying ceiling 25 reverses impairment 50, recognizes the suspended 25 through its original category and leaves carrying value 25. The impairment expense nets to zero and total recognized equity-method loss is 75. Independent SQL forgery and concurrent loss/retry cases verify the same limits.

Review significant influence versus control, the fair-value acquisition basis, embedded goodwill/bargain purchases, adjusted and translated source results, P&L/OCI sequencing, ordinary distribution limits, legal/constructive obligations, recoverable values, and whether other long-term interests require a broader IFRS 9/IAS 28 net-investment model. Executable references: `AssociateTest.php`, `ConcurrencyTest.php` and `MigrationTest.php`.

## Year closing and statement continuity

Recognize income 100 and expense 30. Cash/other net assets increase by 70. Closing transfers net earnings to retained earnings:

| Debit | Credit |
|---|---|
| Income 100 | Expense 30; retained earnings 70 |

Income and expense closing balances become zero, retained earnings gains a credit of 70, and the year's income statement still reports profit of 70 because it excludes the closing transfer. Closing preserves dimensions and closes all complete, contiguous periods in the requested year.

PostgreSQL independently verifies complete contiguous periods, cleared earnings by account/dimension, the declared retained-earnings destination, and cleared balances at transaction commit. Verify fiscal-calendar boundaries, retained-earnings mappings, dimension treatment, adjustments after closing, reopening permissions, and restatement workflows. Executable references: `ClosingAndSchedulesTest.php` and `SubledgerCommitTest.php`.

## Budget commitments

Budget commitment fixture: budget 1,000 less an approved commitment of 400 leaves availability 600. An invoice of 300 releases 300 of the reservation atomically: actual 300, open commitment 100, availability 600. Cancelling the remainder raises availability to 700. Reversing the cancellation and invoice restores actual zero and open commitment 400; withdrawing the unconsumed PO restores availability 1,000. Earlier snapshots retain their original figures. A correction after a budget range ends restores its original line's actual/reservation attribution on the correction date. Separate branch lines retain independent headroom. An invoice reversal dated before a later cancellation, or posted after a new budget version is selected, still restores its reservation on the original version, where it stays cancellable. Report-only versus rejection elections, missing policy, conflicting versions, ambiguous coverage, aggregate invoice-release bounds, posting-run rollback, forged release evidence and competing reservations have executable coverage in `ReportingTest.php`, `MigrationTest.php` and `ConcurrencyTest.php`. Host approval of the budget and rejection elections remains required.

Implementation verification on 2026-10-29: `composer check` passed with 738 tests, 7,562 assertions, 100% type coverage and 99.9% reported PHP code coverage. This records implementation verification; independent accounting-policy approval remains pending.

## Retrospective policy changes

A reviewed policy reduces earliest-opening retained earnings by 30, then each of two comparative years' profit by 10. Cumulative equity deltas are −30, −40 and −50. With original equity 100, 200 and 300, replacement balances are 70, 160 and 250. Current-year recognition reduces the affected asset and retained earnings by 50 without posting current expense. Both comparative years show profit 90 instead of 100; saved earlier cutoffs and original statements keep 100. Whole-policy correction restores equity 300 and the original comparative profits; saved replacement evidence still shows 90.

Separate reviewed tax effects of opening benefit 9 and annual benefit 3 give net cumulative equity deltas −21, −28 and −35, incremental profit effects −7 each year and a current tax-balance debit of 15. Correction restores that exact tax balance with the other linked effects. Offsetting annual effects −10/+10 retain comparative allocations despite no current journal. A documented impracticability decision preserves the originally presented date, later earliest-practicable boundary and reviewed explanation. Successive policies compose from pinned restated sources and reverse in dependency order; dimension filters retain the original buckets. With tax, restated equity is 79, 172 and 265 and each comparative profit 93, with no balance-sheet difference.

Boundaries are tied to the closures. With 2026 closed and recognition in 2027, a change that stops at 2025 or skips the 2026 comparative is refused; including 2026 gives cumulative equity −60 and restated 2026 profit 90. Closing 2026 after a 2027 recognition that skipped it is refused until the change is corrected. So are an overlapping closure, recognition inside a closed year, and reopening a restated comparative period or reversing its closing journal. Two earliest-practicable changes from the same date compose: the second sees the first's restated opening asset 70 and replaces it with 40. An earliest-practicable effect dated at 2024-01-01 leaves the presented 2023 balance sheet at 100 and opens restated 2024 retained earnings at 70. A different equity account from the closures' retained earnings, or an earliest comparative that is not the start of an active closed year, is refused. A policy version has one active change. When every comparative is impracticable, an opening effect of 50 reduces current-opening equity to 250, shown as the restated 2026 opening retained earnings, while restated 2025 equity and profit stay 300 and 100. A prospective decision records evidence with no journal. Effects on cash, inventory or a measured credit-loss allowance are refused; an allowance effect would otherwise reappear as −50 credit-loss expense in the next current-year measurement. Restated interim dates inside a comparative year are refused. A corrected policy is excluded from restated views at every date, so 2026-02-15 shows restated 300 against recorded 250 until the correction's dated journal. A policy-change journal cannot be claimed as an income-tax payment credit.

Executable references: `ClosingAndSchedulesTest.php`, including actual PostgreSQL commit checks for linked creation/correction, orphan-source rejection and journal-only correction rejection, and `ConcurrencyTest.php`, where competing keys for one policy version apply it once. Review policy eligibility, opening versus annual attribution, tax/no-tax support, fiscal coverage and impracticability judgments. Protected-register histories require their own supported lifecycle, and the policy-change path rejects bypasses. Independent accounting approval remains pending.

## Individually assessed and forward-looking credit losses

Receivables 1,000 include one specifically assessed item of 200 requiring loss 100. The remaining 800 at 5% requires 40; a reviewed forward-looking overlay of 10 gives allowance 150. Reassessing the specific loss to 60 releases exactly 40, leaving 110, with the earlier cutoff still showing 150. A write-off of 30 against allowance leaves 80; reinstatement of 30 restores 110. The movement is opening 110, charge zero, write-offs 30, recoveries/reinstatements 30 and closing 110. A subsequent ordinary credit of 5 is explicitly reported as other movement and closes at 115.

A party assessment spanning current and old items excludes both from the matrix without netting its unapplied credit or another party's exposure. A USD receivable of 100 initially at 130, partially settled by USD 40, has historical remainder 7,800; revalued at 140 its functional exposure is 8,400. A reviewed functional loss of 8,200 is accepted against the revalued amount. Duplicate and overlapping selections, losses above exposure, absent policy references, out-of-range overlays and invented source exposure fail atomically.

Executable reference: `SettlementTest.php`. Review individual-versus-collective selection, recoverability estimates, versioned approval evidence, signed forward-looking overlays and the distinction between reinstatement and direct cash recovery. Independent approval remains pending.

## Current income tax

Accounting profit 10,000 plus permanent disallowances 1,000 less additional reviewed capital allowances 500 gives adjusted profit 10,500. Eligible brought-forward trade losses 2,000 reduce taxable profit to 8,500. At the explicit example rate of 30%, the gross charge is 2,550; booked instalments 2,000 leave current payable 550. The charge debits expense and credits payable; the instalments already debit payable and are not reposted. Correcting the assessment restores loss availability 2,000 and reverses the original charge exactly, preserving earlier cutoff figures and result hashes.

A trade loss expiring before the assessment year end, a wrong-source loss and a vintage from the assessment year are rejected. An explicit reviewed extension permits the old vintage; installing a later rate/expiry policy does not change the saved assessment or prevent posting/correcting its pinned version. A tax loss of 1,000 creates a vintage without a synthetic charge journal; using it against next-year profit 2,000 produces current tax 300. Its origin can only be withdrawn after dependent use is corrected without leaving an interval consuming a nonexistent loss.

Hostile-order fixtures correct an assessment dated 15 January after activity dated 20 January, a rule revision and book archival. Correction events share their journals' sequences and do not burn journal-run numbers. A future-dated correction does not release a vintage for duplicate use at an earlier date; a loss generated by an assessment posted on 1 March 2028 cannot reduce 2027 tax posted on 31 December 2027, and a vintage with a known later-dated withdrawal reports nothing remaining because no new assessment may use it. Trade profit 1,000 at 30% and a capital gain 200 at 15% in the same year give charges 300 and 30, while a deferred-tax expense of 50 on a `tax.income.*` role stays outside both sources' profit and cannot be mapped into one. A booked payment can be reversed after assessment; the reconciliation and checklist expose the stale credit rather than preventing correction. Taxing the same 10,000 profit under a renamed source is refused, so the charge stays 3,000. Under a configuration effective from 1 July, March profit fails the year-end check until a full-year assessment covers it, which charges 3,000. A loss of 1,500 already using 1,000 in a later-dated 2027 assessment reports 500 remaining at 2026, and 2026 preparation accepts exactly 500. A capital loss with no carry-forward limit can still reduce trade profit although the trade source allows none of its own. Posting an approved assessment after its year's rules are superseded is refused, an unrelated pack's `tax.income.*` role on revenue leaves profit at 10,000, and every eligible loss source must carry its own rule. Other fixtures cover maker/checker election, immutable rejection, stale/unmapped profit, exact half-even/half-up/down rounding, malformed rules and credit identities, database-derived results, unrelated/forged journals, charge journals posted or reversed outside the service without the `Tax` permission, journal-free evidence in closed periods, superseded or not-yet-known configurations, dimension-bound posting accounts, immutable evidence and competing assessments using the same loss.

Executable references: `IndustryWorkflowsTest.php`, `ConcurrencyTest.php`, `IntegrityTest.php` and `MigrationTest.php`. Review complete source/profit-role mappings including every `tax.income.*` role, per-source statutory rates, adjustment/allowance support, instalment/withholding attribution, loss expiry/source restrictions and extension approvals. Current-tax OCI/equity attribution, deferred-tax recognition and statutory filing are separate inputs/contracts. Implementation verification on 2026-10-29: `composer check` passed with 872 tests, 8,200 assertions, 100% type coverage and 99.9% reported PHP code coverage. Independent tax/accounting-policy approval remains pending.

## Tax bases and deferred tax

An asset starts with carrying amount and tax base 12,000 and no deferred tax. Book depreciation 1,000 and a reviewed straight-line capital allowance 1,500 leave carrying 11,000, tax base 10,500, taxable temporary difference 500 and deferred liability 150 at the illustrative 30% rate. Correction restores the prior tax base 12,000 and removes the exact charge, while saved cutoffs retain the 10,500 base. An imported liability of 150 is recorded through opening equity, separately from the later profit credit of 150 when its difference reverses.

A legacy asset carrying 12,000 with tax-written-down balance 8,000 imports a deferred liability of 1,200. At the explicit illustrative initial rate of 10% of qualifying cost 12,000, initial allowance is 1,200. Ordinary reducing-balance allowance at 25% of the remaining 6,800 is 1,700: total allowance 2,900, closing tax base 5,100. An effective rule change to 20% produces the next ordinary allowance 1,020. A repeated initial claim is rejected. Fractions, period boundaries and eligibility are reviewed host inputs rather than an inferred statutory calendar.

A loss of 1,000 at 30% gives a potential asset 300. Explicit recognition of zero leaves all 300 unrecognized; a subsequent reviewed decision recognizes 200, leaves 100 unrecognized and does not consume the loss. A provision carrying 1,000 with tax base zero is deductible, not taxable; no asset appears without its separate decision. Explicit exemptions suppress recognition. Revaluation tax 900 on a surplus 3,000 goes to non-recyclable OCI, not current profit expense; a registered-asset fixture also preserves separate profit and OCI source allocations and exact corrections across both registers.

Executable references: `AssetTest.php`, `IndustryWorkflowsTest.php`, `IntegrityTest.php`, `SubledgerCommitTest.php`, `ConcurrencyTest.php` and `MigrationTest.php`. A deferred liability posted to `tax.income.deferred` leaves current-tax accounting profit at 1,000 and charge 300 without repeating the role in the current-tax configuration. A difference of 500 measured at a 15% sale rate gives 75; remeasured at the 30% use rate it gives 150 with a charge of 75. A 2019 loss with five-year carry-forward has no deductible capacity at 2026. The year-end check flags allowances of 1,500 until current tax claims the same 1,500, then flags a stale asset measurement after further depreciation, an unremeasured provision and a later rate change. Tax on an equity component and on a prior-period restatement posts as a contribution and an opening adjustment. A five-year loss from 2021 has deductible capacity 1,000 on 30 December 2026 and none at its 31 December expiry. Tax deferred to recyclable OCI and recycled into deferred-tax expense stays out of current-tax profit. A 1,500 allowance declared with a replacement tax base reconciles with a 1,500 current-tax claim. A balance account holding a legacy 100 must be cleared before installation, and settled or exempt sources stop needing remeasurement. A difference carried at a zero rate is flagged when the rate changes, and a measurement under a configuration superseded for its date cannot post. A trade loss of 1,000 has no deductible capacity after the source is renamed, and 1,000 again once the old name stays as a loss-only source; an expense account already holding depreciation cannot become deferred-tax expense, and a second opening date is refused. Recycling deferred tax out of OCI into revenue is refused, a closing-flagged reclassification outside the year-end close cannot reach current-tax expense, and an impossible extension date reports the loss basis as unavailable instead of failing the checklist. Recycling from a correcting journal is refused for all OCI, so a cancelled reserve of 500 cannot reach profit; a closing journal posing as a year-end close fails at commit; a malformed latest configuration is reported as invalid. Deferred tax in OCI cannot reach inventory through a basis adjustment; a five-year 2021 loss shows 1,000 remaining at its 2026 expiry and nothing a month later; a register adopted after the first year closes opens on its adoption date. An adoption date cannot follow an ordinary measurement or an earlier, since-reversed adoption, while a source found later can still open on the adoption date. Fixtures include immutable review/rejection, partial recognition, malformed policies and amounts, overlapping rules, stale/duplicate-date measurements, dependency-ordered corrections, future-dated withdrawals, revised policies, archived-book correction, direct journal correction, independent database-derived results and rejection of forged amounts and orphan journals at actual commit. Concurrent measurements permit one source balance, while identical retries return one identity. Migration acceptance covers complete and individual rollback/reinstallation and selected-schema isolation.

Review source completeness, qualifying-cost overrides, legacy tax bases, class and initial-allowance eligibility, rate-period fractions, recovery/settlement-manner rates, forecasts supporting partial/full asset recognition, exemptions and source-specific profit/OCI/equity attribution. The register does not infer statutory eligibility or offset rights. Host approval remains in [pending-non-code.md](pending-non-code.md#policy-decisions-per-code-item).

Implementation verification on 2026-10-29: `composer check` passed with 872 tests, 8,200 assertions, 100% type coverage and 99.9% reported PHP code coverage. Independent accounting-policy approval remains pending.

## Classified reporting and performance measures

Trading fixture: revenue 1,000 less operating costs 300 gives operating profit 700. Investing interest 100 gives profit before financing and income taxes 800. Financing costs 50 leave profit before income taxes 750; income tax 150 leaves continuing profit 600; discontinued operations 20 give profit 620. Dedicated non-recyclable OCI 60 and recyclable OCI 10 are excluded from profit. The financing-business fixture explicitly moves interest income and borrowing costs to operating, giving operating profit 750 and unchanged profit 620. Its reviewed paragraph 73 policy omits the profit-before-financing subtotal. Neither policy changes cash-flow metadata, and a later mapping or posting cannot rewrite the saved report.

Performance-measure fixture: west-campus revenue 100 less exceptional operating costs 30 gives operating profit 70. Adding back the reviewed expense gives adjusted operating profit 100, with separately supplied signed tax effect -9 and NCI effect -2. East-campus revenue 200 less exceptional costs 40 gives operating profit 160 and adjusted profit 200, with tax effect -12 and explicit NCI zero. Every adjustment and column requires both effects; changing them under the same save identity fails. The engine does not calculate the tax/NCI methodology or public-communication eligibility. A measure may reconcile to operating profit, the financing subtotal when it is presented, profit before income taxes, profit from continuing operations or profit (paragraphs 118(e)–(f) and 123(c)); other subtotals are rejected.

Source-attribution fixtures distinguish OCI 60 from a direct reserve-to-retained transfer of 20: OCI remains 60, not 40. The protected-asset fixture records revaluation OCI 3,000 and transfers 750 on partial disposal; current OCI remains 3,000 while the reserve's closing credit is 2,250. Asset valuation markers are independently checked against the source event. Associate OCI 60 at 30% gives a reserve of 18; a reviewed half disposal with proceeds 60 against carrying value 59 produces gain 1 plus recycling 9, giving profit 10 and net OCI 9. A detached associate recycling journal fails the deferred source-link check. Recyclable OCI in an income account is rejected in the mapping, configuration and posting, because closing would carry it into retained earnings with no reserve to recycle. Carried-in opening equity (`opening_balance`, including imported asset surplus) is distinct from a restatement (`opening_adjustment`), which records the host's restatement policy version and keeps any attribution the host supplied. A share subscription posted from a bank line and an imported opening-equity line carry their attribution; imports reject recycling, basis adjustments and earnings transfers.

Recycling fixtures recognize a gain of 100 and reclassify 40: profit 40 and net OCI 60. Losses produce profit -40 and net OCI -60. Reversing the release restores the original lot; the original cannot be reversed first. Competing releases of 60 against 100 permit one, while identical retry identities return the same journal. A later release/correction prevents a new backdated release from overdrawing an earlier reporting date. Direct SQL cannot forge the source, category, dimensions, amount, pair or policy evidence. Opening OCI 100 contributes no current OCI; releasing 40 in the next period gives profit 40 and OCI -40. A basis adjustment of 30 moves reserve into asset basis without current OCI/profit and shares the original lot's release limit. Draft approval, recurring occurrences/reversals and year-end source attribution retain the evidence. Saved cutoffs/results remain unchanged.

Review the main-business-activity assessment, mapping completeness, the financing presentation decision, OCI eligibility/recycling categories, opening-transition evidence, equity components and MPM eligibility, definitions and tax/NCI methodology against IFRS 18 paragraphs 47–74, 88–95, B88 and 117–125. Paragraph 91 requires recycling to reduce OCI to avoid double counting; B88 distinguishes non-recyclable asset surplus transfers and direct basis adjustments. Underlying recognition/recycling conditions are reviewed inputs under the applicable asset/instrument standards. Executable references: `ReportingTest.php`, `ConfigurationTest.php`, `AssetTest.php`, `AssociateTest.php`, `JournalTest.php`, `ClosingAndSchedulesTest.php` and `ConcurrencyTest.php`. Source checked 2026-10-28: [IFRS 18, 2026 issued text](https://www.ifrs.org/content/dam/ifrs/publications/html-standards/english/2026/issued/ifrs18.html).

Implementation verification on 2026-10-28: `composer check` passed with 703 tests, 7,252 assertions, full type coverage and 99.9% reported code coverage, including migration rollback/reinstallation, accounting, direct-SQL, multi-process concurrency and numeric-chart JSON round-trip cases. Local measurements are recorded under [scale acceptance](pending-non-code.md#scale-acceptance). This is the reporting-model implementation review handoff, not an accountant's approval. Comprehensive income and the equity roll-forward are now implemented as described below; [pending-code.md](pending-code.md) item 8 still owns retrospective policy-change replacement comparatives. The independent review record below remains pending.

## Comprehensive income and changes in equity

Opening equity 1,000 plus profit 200, OCI 60 and owner contributions 100, less distributions 50, gives closing equity 1,310 and comprehensive income 260. A reserve-to-retained transfer of 20 changes only the components: capital closes at 1,050, retained earnings at 220 and the reserve at 40. Each component reconciles to ledger equity plus unclosed earnings. Fiscal closing leaves both statements' figures unchanged. A prior-year restatement of 30 posted in the following period raises restated opening and closing equity from 1,310 to 1,340, without current profit. Earlier snapshot reads and saved evidence retain their original values and byte hashes.

Recycling 40 from an OCI gain of 100 gives profit 40 and net OCI 60, keeping comprehensive income and closing equity at 100; the loss fixture reverses all signs. A direct hedge basis adjustment of 30 leaves OCI income at 100 while reducing equity to 70 through a separately presented basis-adjustment row. Reviewed carried-in OCI is opening equity, never current comprehensive income. Non-recyclable OCI recognized in an income account is separate from classified profit and rolls into the explicit earnings component before and after fiscal closing. Dimension-filtered comparative columns and later backdated entries retain their own effective dates and knowledge cutoffs.

Review component mappings, the retained/unclosed-earnings component, owner-versus-income classification and opening-restatement evidence. Missing active account mappings, unclassified current equity activity, conflicting OCI categories and non-reconciling closing attribution fail explicitly. These statements cover one book; existing group reporting retains its supported parent/NCI profit attribution without inventing a consolidated OCI split. Executable reference: `ReportingTest.php`. Both statements use the existing immutable configured-statement evidence contract, with no rewrite of historical statement definitions.

## Bank evidence and cash flow

A statement with opening zero, a receipt of 100, and closing 100 can be matched to the existing debit of bank 100. Matching creates no new cash. Releasing a match changes only reconciliation evidence. A corrected import retains the original through a void and replacement. An omitted opening balance remains an unexplained reconciliation difference.

Simple cash entries can use reviewed counter-account cash-flow classifications. Compound/noncash acquisitions must use explicit classifications or remain visibly unclassified. Verify the classification independently; exact balancing alone does not establish the economic category.

Statement voids invalidate evidence at the knowledge cutoff, allowing corrected historical imports without rewriting older snapshots. Their event dates must follow the statement closing date and all match/release history. Cross-book batch journals likewise require a matching atomic batch correction; one-sided journal reversals fail at commit.

Executable references: `BankingTest.php` and `CashFlowTest.php`.

## Billed and unbilled contract revenue

Earning 100 before an unconditional billing right gives contract asset 100 and revenue 100; invoicing transfers the full 100 to customer receivables with no new revenue. Billing before delivery instead gives contract liability 100, released as obligations are satisfied. Relative standalone prices 80/40 allocate consideration 100 as 66.67/33.33, conserving the final cent. A completed contract with fixed 100 and approved bonus 20 earns/bills 120; revising the bonus to 10 posts revenue −10 and a refund liability 10, then a linked `refund` credit reduces receivables without repeating revenue. Credits are capped at their original invoice, and a `refund` credit at the refund liability. A rebate estimate −20 (limit −30) on fixed 100 yields 80 and cannot be constrained into an optimistic price of 90. Invoiced at the gross 100, it carries receivable 100 and refund liability 20 with no contract asset or liability; a rebate credit of 20 settles it, and the refunded 20 cannot be invoiced again, while a penalty of 20 that is refunded and later waived can be billed again exactly once. An upfront bonus of 20 constrained to 0, billed at 120 on a half-performed contract, gives contract liability 50 and refund liability 20. A constrained bonus of 20 included at 0 beside a penalty of −10 gives price 90. A penalty included below its limit, or at its estimate without highly-probable support, is refused.

An integrated service priced 100 at 50% progress has earned 50. A reviewed non-distinct modification to price 120/progress 60% catches up 22 to cumulative 72; completion reaches 120, and dependency-ordered correction restores the exact original 50/progress/allocation. A distinct standalone-priced addition of 30 is a separate contract: its completion adds 30 without altering the original's earned 50, and correcting/cancelling the addition leaves that 50 intact; the original cannot be cancelled while the addition is live. For 80/40 with equipment delivered at 66.67 and service at 16.66, a catch-up to price 130 with service priced 70 and 40% complete keeps equipment at 66.67, allocates service 63.33 and recognizes 25.33. A later bonus of 10 raises only the service allocation to 73.33. Repricing or un-delivering equipment through a catch-up is refused, as is a catch-up that changes no terms. A contract asset of 100 impaired by a reviewed lifetime loss of 5 shows profit 95 and cannot be billed until the allowance is remeasured. Zero-effect decisions retain complete estimate/constraint/method evidence and an immutable terms identity.

Executable references: `FinanceTest.php` and `ConcurrencyTest.php`. Actual-commit fixtures verify the party open item created by billing, exact source/event corrections under forced tenant RLS and append-only evidence. Competing 80 invoices against consideration 100 commit only once; identical retries return one event identity. PostgreSQL rejects invented no-journal calculations, unsupported constraints, optimistic penalty constraints and distinct-remaining catch-ups. Review enforceability, progress, estimation/constraint evidence and limits, standalone-price basis, modification eligibility, refund versus billing-correction credits and contract-asset loss estimates. IFRS 15's separate-contract and cumulative-catch-up judgments are explicit host inputs; other modification treatments require a supported distinct workflow.

## Loan fees, floating resets, replacements and default horizons

A lender advances 1,000 and receives an approved integral fee 50. Initial amortized cost/cash net is 950, with separate gross principal and fee cash evidence and no immediate fee income. One later receipt 1,100 accrues effective interest 150, while cash coupon interest remains 100. Lender transaction costs 20 instead give opening 970/interest 130; borrower fee 50/cost 20 give opening 930/expense 170. Correcting the period and origination restores every original cent. Effective-rate generation returns the conserving 18-decimal rate nearest the exact rational root: 950 to 1,100 gives 0.157894736842105263. A three-payment plan 95,431.11 → 42,317.33/42,317.33/31,424.78 gives 0.110100006680210465, which the earlier neighbour-only search rejected. 600 slots are measured in well under a second.

After a 950 loan at 10% accrues 95 and receives 100, carrying is 945. A pure benchmark reset 6%→8% with 4% contractual spread changes the next rate to 12%; future cash 1,058.40 earns 113.40 and closes at zero, preserving the original 95 interest and saved 10% plan. A fee-bearing floating principal 1,000/fee 50 retains carrying 950 when its coupon increases from 10% to 12%. Re-estimating EIR against revised cash 1,120 yields total effective interest 170, including the unamortized fee 50; simply adding the two-percent benchmark change to the original fee-inclusive EIR would not conserve carrying value and is not used. The same reset after a first coupon of 100 earns total interest 270. Coupons must equal benchmark plus spread on outstanding principal. A registration at EIR 10% with benchmark 50% and spread 40%, or an undeclared discount, is refused. A fee-free floater of 1,000.11 resets from 4% to 5% although its rounded coupons do not present-value exactly to carrying, and closes at zero. A level-payment floater of 1,000 at 10% pays 576.19 twice; after the first slot, a 12% fixing recomputes the final payment to 586.67. A fixed-principal reset that turns amortizing repayments into a bullet is refused. A loan that paid 60 of a scheduled 100 cannot be reset until a reviewed modification reschedules carrying 985 as 1,083.50. A 12% fixing then gives cash 1,103.20 including interest 118.20. Reversing a reset with a later-dated correction, or posting the first slot late on 15 August, keeps the carrying date at 30 June: a mid-slot fixing is refused, and a 30 September accrual is 47.25 (92 of 184 days of 94.50).

A borrower with old carrying 945 exchanges for a reviewed new instrument at fair value 800 with one payment 840 and new EIR 5%. Gross derecognition gain is 145; counterparty fee 20 and third-party extinguishment costs 5 leave gain 120. Future interest is 40, and the new instrument closes exactly. A lender with gross 945/allowance 50 derecognizes net 895 for new gross 800, giving loss 95; a separate new stage-one model expecting 829.50 against contractual 840 at 5% gives allowance 10. A lender replacing a stage-3, partially written-off source with net 795, fair value 800, fee received 20 and cost 5 records gain 5 and new amortized cost 785, which earns 55. A credit-impaired-at-recognition replacement is refused. Reversing dependent periods and replacement restores old gross 945, allowance 50, stage and original 10% instrument identity. The liability ten-percent test includes a counterparty fee 100 on unchanged PV 1,000 exactly at the threshold; 99.99 stays below it even with third-party costs 1,000. Deferred slots are retained: 1,210 in two 10% slots has the same PV 1,000 as 1,100 in one, so time cannot be collapsed to force derecognition.

A lease with remaining liability 110 is remeasured for payment 120 at reviewed revised discount rate 20%, giving liability 100/ROU reduction 10. Its next interest 20 closes liability to zero; exact correction restores 110 and the original rate/ROU effect. With ROU cost 190.91 depreciated by 150, remeasuring 110 to 60.50 at 100% removes the net 40.91 and recognizes gain 8.59. A stated ROU above that net amount is refused.

For two zero-discount contractual receipts of 100, a first-year default case with probability 10% and receipts 100/50 gives stage-one ECL 5: the second-year shortfall still counts because default is within twelve months. Adding a second-year default with probability 20% and receipts 100/0 yields lifetime ECL 25 at stage 2. An already-defaulted case with expected receipts 60/40 yields stage-three ECL 100. The fixture independently records the 30/90-day presumption observations, verifies stage/allowance transitions and restores all three in reverse order. Excess probability, unsupported horizons, inconsistent low-credit-risk elections and losses before the declared default time fail. Monthly slots count a twelfth-slot default (probability 10%, shortfall 200) at stage 1 for ECL 20, and add a thirteenth-slot default (20%, shortfall 100) only over lifetime for 40. A 13-slot stage-1 horizon fails. A loan that received 60 of 100 is assessed with overdue 40 at period 0; a 50% default on the remaining 100 gives stage-2 ECL 50. A year later, reviewed days past due below the ledger's 365 are refused. After a partial write-off, the loan is assessed on its remaining scheduled payments. `measureLoss()` and `modify()` cannot then restage it.

Executable references: `FinanceTest.php`, `ConcurrencyTest.php`, `SubledgerCommitTest.php` and `Unit/FinanceTest.php`. Actual commit rejects detached corrections; competing replacement commands recognize one legal exchange and one new instrument identity, with identical retries returning the same event. Review fee/cost eligibility, actual cash coupon allocations, benchmark-only reset evidence, new fair value/derecognition and non-POCI classification, lease rate eligibility, external risk observations/backstop rebuttals and default/recovery timing. Source standards checked 2026-10-29: [IFRS 15](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-15-revenue-from-contracts-with-customers/) and [IFRS 9](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-9-financial-instruments/). Independent accounting-policy approval remains pending.

## Specialized finance and Kenya VAT

Executable references: `FinanceTest.php` in the unit and integration suites and `IndustryWorkflowsTest.php`.

- A net lending advance of 950 at 10% per slot earns 95, receives 100, carries 945, then earns 94.50 and receives 1,039.50 to close at zero. Borrower entries use the opposite directions. Cash interest and its classification are explicit inputs, including previously accrued interest.
- Three arrears lease payments of 100 discounted at 10% per slot produce liability/ROU cost 248.69. Conserving interest is 24.86, 17.36 and 9.09. The first row exposes a -0.01 quantization adjustment against interest computed from rounded carrying value. Liability closes exactly; a separate depreciation schedule expenses ROU cost 248.69, giving total interest plus depreciation 300.
- A contractual receipt of 1,100 in one slot discounted at 10%, with 70% probability of full receipt and 30% probability of receiving 550, gives ECL 150. Changing the adverse probability to 10% reduces the actual allowance to 50 and credits expense 100. Portfolio dimensions remain separate.
- Consideration 100 against standalone prices 80/40 allocates 66.67/33.33. Equipment satisfaction recognizes only 66.67; service satisfaction recognizes 33.33 later. Repeated active recognition of the same contract obligation fails.
- Supplier settlement 1,160 with an explicitly approved 5% withholding on base 1,000 pays cash 1,110 and creates withholding payable 50. The full supplier obligation is available for allocation. This test rate is illustrative.
- Reverse-charge tax 160 with 75% recovery debits the designated recovery/pending asset 120 and expense 40, and credits tax payable 160 without changing supplier debt. The Kenya pack directs potentially recoverable imported-service VAT into a pending account, outside eligible domestic input VAT until separate payment/claim review.

The Kenya factory's 16% general VAT, 0% zero rate and 2% withholding VAT were checked against [KRA's VAT guidance](https://www.kra.go.ke/individual/filing-paying/types-of-taxes/value-added-tax) on 2026-10-27. Its explicit policy dates are the organization's selected coverage window, not an inferred statutory change history. Review tax-point timing, exemptions, agency status, documents, blocked input, import-tax payment and claim timing, opening credits and return mappings before statutory use.

### Protected finance lifecycle acceptance

Register a loan asset at 100, 10% per slot, no first-slot receipt and final receipt 121. A reviewed stage-3 loss model expects only 60 against current contractual value 100, so allowance is 40. First-slot gross interest is 10 but net interest income is 6. Gross carrying becomes 110, allowance becomes 44 and net carrying becomes 66. Full write-off debits allowance 44 and credit-loss expense 66 and credits gross loan 110. A later recovery of 20 debits cash and credits credit-loss expense without reinstating principal. Correcting recovery and write-off restores gross 110, allowance 44 and net 66 exactly.

For advance-funded consideration 100 allocated 66.67/33.33, recognizing equipment fully and service at reviewed 50% progress recognizes 83.33 in total and leaves liability 16.67. Reconfirming 50% progress creates no duplicate revenue. Final service completion consumes 16.67 exactly. Reverse final completion, the no-change decision and preceding recognition in dependency order before cancelling the original advance.

Review stage assessment, default horizon, original versus credit-adjusted EIR, within-slot timing, expected recoveries, write-off eligibility, recovery cash classification, performance-obligation identification and progress measurement. The engine pins these reviewed inputs and enforces their financial effects independently in PostgreSQL. Concurrent runners cannot consume the same period twice, raw forged effects fail, and journal-only corrections fail at actual commit.

### Modified loans, actual cash and payoff acceptance

Executable references: `FinanceTest.php`, `SubledgerCommitTest.php`, `ConcurrencyTest.php` and `MigrationTest.php`.

- Advance 950 at 10% per slot. After interest 95 and receipt 100, gross carrying is 945. Replacing the remaining 1,039.50 with 990 at the same due slot measures gross 900: lender modification loss 45, borrower modification gain 45. The next interest charge is 90 and payment 990 closes exactly. Earlier snapshots and original inputs retain the prior 945 carrying value and original schedule.
- With stage-3 allowance 190 on gross 950, missing the first 100 receipt recognizes gross interest 95 and net interest 76, leaving gross 1,045 and allowance 209. Revised contractual cash 990 and expected receipt 660 give gross 900 and allowance 300 at the original rate. The separately retained effects are modification loss 145 and allowance increase 91; net carrying becomes 600.
- At carrying 945 with allowance 50, full legal payoff cash 900 recognizes gross settlement loss 45 and releases allowance 50, leaving principal/allowance zero. Reversal restores principal 945 and allowance 50 exactly. Competing payoff commands cannot record a second cash movement, and a journal-only correction fails at commit.
- For a liability carrying 1,000.50 at 10%, a revised payment 990.50 is just below the ten-percent threshold before rounding, while 990.49 exceeds it. Both round to a new present value of 900.45; the unrounded test accepts only the former. Monthly term extension preserves the original calendar grid and conserves the final rounding cent.

Review non-derecognition eligibility, original rate/slot convention, recognized carrying date, qualitative liability factors, revised expected losses, actual cash allocations and legal discharge.

### Tax register and return acceptance

Executable references: `IndustryWorkflowsTest.php`, `SubledgerCommitTest.php`, `ConcurrencyTest.php` and `MigrationTest.php`.

- Sales base 200 gives output tax 32. Purchase base 100 gives tax 16; a reviewed cumulative eligible fraction of 75% claims 12 and declares net 20. A GL tax payment of 10 is reconciled separately and must not reduce declared output to 22.
- Defer input 16 in January, claim 50% in February and 100% cumulatively in March. February claims 8 and carries credit 8; March claims only the remaining 8 and carries credit 16 after opening credit. No invoice tax is claimed twice.
- Reversing the original purchase in February after a January claim of 12 recaptures 12 in February. January's saved figures and hash stay unchanged. A later backdated sales credit requires a linked replacement/amendment.
- Import tax assessed at 160 with potential recovery 120 requires actual full-payment evidence before a claim of 120. The same payment cannot support another assessment, and reversing it invalidates the earlier claim until recaptured or corrected.
- Unmapped untaxed supplies, missing input reviews and unexplained manual tax postings block finalization, including offsetting manual entries with zero net account movement. Forged return JSON fails independent database recomputation; evidence cannot be edited/deleted/truncated. Competing preparation retries retain one identity, and competing original return keys cannot create overlapping periods.

Review scheme scope, tax point versus financial accounting date, category/box mapping, input eligibility and statutory claim deadlines, source/FX evidence, commercial credit relationships, import-tax payment eligibility, financial recoverability reclassification and opening-credit certificates.

### Statutory delivery acceptance

Executable references: `StatutoryTest.php`, `ConcurrencyTest.php` and `MigrationTest.php`.

Queue an immutable invoice payload against posted gross 100. Its body/hash and external retry identity remain fixed through retries. If transmission may have succeeded, the next worker performs lookup; it does not create another invoice send. An authoritative absence result permits another send with the same identity. Competing workers, including identical claim-key retries, receive only one grant. An expired worker is fenced once another worker claims reconciliation.

A provider-accepted invoice anchors a partial credit of 70. A second active credit of 40 fails against the original 100, including when the first credit has not yet completed delivery. Ambiguous outcomes cannot be cancelled or used to discard reserved credit. Exact financial reversals preserve the original invoice/provider link. A finalized return that changes while queued is not sent anew; a transport acknowledgment alone does not mark it filed.

Review each concrete adapter's business-acceptance interpretation, authoritative lookup guarantees, external idempotency identity, exact body/schema mapping, taxpayer/branch/item identifiers and original-invoice references.

## Industry illustrations

### School fees, sponsors, and overpayments

Raise KES 1,200 of fees: guardian owes 800 by January 31; sponsor owes 400 by March 31. The student is the beneficiary, not automatically the debtor. For a policy that recognizes the charge immediately:

| Event | Debit | Credit |
|---|---|---|
| Invoice | Guardian receivable 800; sponsor receivable 400 | Tuition income 1,200 |
| Guardian receipt | Bank 1,000 | Guardian receivable 1,000 |

Allocate 800 from the receipt to the guardian's obligation. The guardian retains an unallocated credit of 200; the sponsor still owes 400. Allocation does not post another bank receipt or recognize more income. If tuition is earned over a term, use a reviewed deferred-income policy instead of immediate recognition and recognize it through the service period.

Verify recognition timing, sponsorship classification, scholarship/discount treatment, refund policies, and whether advances should use a liability control account. Executable reference: `IndustryWorkflowsTest.php` and `ClosingAndSchedulesTest.php`.

### Hospital split responsibility

For services priced at an agreed contractual amount of KES 600, the insurer owes 450 and the patient/guarantor owes 150:

| Debit | Credit |
|---|---|
| Insurer receivable 450; guarantor receivable 150 | Service income 600 |

The encounter is a beneficiary/source reference, and a subsequent cash payer may be someone else. A claim denial must result in the reviewed credit, write-off, or transfer-of-responsibility treatment, retaining the original history. The engine does not infer that denial transfers legal liability to the patient.

Verify recognition before claim approval, contractual adjustments, disputed claims, impairments, and transfer of responsibility. Executable reference: `IndustryWorkflowsTest.php`.

### Utility deposit and consumption

Receive a refundable KES 300 deposit, then bill KES 300 for consumption:

| Event | Debit | Credit |
|---|---|---|
| Deposit receipt | Bank 300 | Refundable-deposit liability 300 |
| Consumption bill | Receivable 300 | Service income 300 |
| Approved application of deposit | Deposit liability 300 | Receivable 300 |

Allocate the deposit liability's debit/credit pair and the receivable's debit/credit pair. The deposit receipt itself creates no service income. Verify legal eligibility to apply deposits, billing periods, estimated consumption, unbilled accruals, tariff changes, penalties, and refunds. Executable reference: `IndustryWorkflowsTest.php`.

## Review record

No independent approvals recorded. Add reviewer, date, approved cases/scope, policy reference, corrections and unresolved issues here as review occurs.

Record reviewer, qualifications, review date, policy version, approved cases, corrections, and outstanding issues in the host project's accounting policy records. An approval of one case or industry must not be generalized to all cases, countries, or customers.
