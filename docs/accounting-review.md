# Accountant review cases

Status: pending independent accounting review. The numerical fixtures below are executable examples, not approvals of an organization's accounting policy or statutory treatment.

## School fees, sponsors, and overpayments

Raise KES 1,200 of fees: guardian owes 800 by January 31; sponsor owes 400 by March 31. The student is the beneficiary, not automatically the debtor. For a policy that recognizes the charge immediately:

| Event | Debit | Credit |
|---|---|---|
| Invoice | Guardian receivable 800; sponsor receivable 400 | Tuition income 1,200 |
| Guardian receipt | Bank 1,000 | Guardian receivable 1,000 |

Allocate 800 from the receipt to the guardian's obligation. The guardian retains an unallocated credit of 200; the sponsor still owes 400. Allocation does not post another bank receipt or recognize more income. If tuition is earned over a term, use a reviewed deferred-income policy instead of immediate recognition and recognize it through the service period.

Verify recognition timing, sponsorship classification, scholarship/discount treatment, refund policies, and whether advances should use a liability control account. Executable reference: `IndustryWorkflowsTest.php` and `ClosingAndSchedulesTest.php`.

## Hospital split responsibility

For services priced at an agreed contractual amount of KES 600, the insurer owes 450 and the patient/guarantor owes 150:

| Debit | Credit |
|---|---|
| Insurer receivable 450; guarantor receivable 150 | Service income 600 |

The encounter is a beneficiary/source reference, and a subsequent cash payer may be someone else. A claim denial must result in the reviewed credit, write-off, or transfer-of-responsibility treatment, retaining the original history. The engine does not infer that denial transfers legal liability to the patient.

Verify recognition before claim approval, contractual adjustments, disputed claims, impairments, and transfer of responsibility. Executable reference: `IndustryWorkflowsTest.php`.

## Utility deposit and consumption

Receive a refundable KES 300 deposit, then bill KES 300 for consumption:

| Event | Debit | Credit |
|---|---|---|
| Deposit receipt | Bank 300 | Refundable-deposit liability 300 |
| Consumption bill | Receivable 300 | Service income 300 |
| Approved application of deposit | Deposit liability 300 | Receivable 300 |

Allocate the deposit liability's debit/credit pair and the receivable's debit/credit pair. The deposit receipt itself creates no service income. Verify legal eligibility to apply deposits, billing periods, estimated consumption, unbilled accruals, tariff changes, penalties, and refunds. Executable reference: `IndustryWorkflowsTest.php`.

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

Review capitalization eligibility, dates available for use, useful life, residual, selected partial-period convention, impairment recoverable amount, replacement plans, tax differences, and disposal gain/loss presentation. New impairment-reversal policies remain outside these cases. Executable references: `AssetTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Asset upward revaluation through equity surplus

For cost 12,000 with January depreciation 1,000 and no impairment, carrying value is 11,000. A reviewed fair-value uplift of 3,000 revalues to 14,000. The implementation eliminates accumulated depreciation against gross cost, adjusts gross cost so carrying equals 14,000, and credits an equity revaluation-surplus account 3,000. After revaluation, gross cost is 14,000, accumulated depreciation is zero, and carrying value is 14,000.

After January impairment of 3,000, carrying is 8,000. An uplift cannot automatically be credited wholly to surplus: recovery may reverse a prior profit-or-loss charge. The current surplus-only command rejects assets with outstanding impairment. The impairment command also rejects active revalued assets because losses may first reduce revaluation surplus. These mixed treatments require a future reviewed recovery/surplus-loss allocation policy.

Review fair-value measurement, the revaluation-surplus equity classification, gross-versus-net presentation, replacement depreciation on the revalued amount, and surplus recycling on disposal. The command is explicitly net upward revaluation to surplus; downward revaluation below carrying value is not expressed through it, and separate recoverable-amount impairment-reversal calculations are not implemented. Executable references: `AssetTest.php`.

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

Review whether the partial-goodwill method is appropriate, book-value net assets versus acquisition-date fair values, bargain purchase recognition, and post-acquisition equity movements. Acquisition-date fair-value remeasurement and full goodwill are not computed. Executable reference: `ConsolidationTest.php`.

Review the group perimeter, ownership/control, acquisition cutoff, historical equity rollforwards, income-rate suitability, elimination entries, differences in accounting policy, reserves, and period comparability. Controlled members contribute 100% of assets, liabilities, income, and expenses. Automatic acquisition fair values, measured-NCI/full-goodwill methods, goodwill impairment, deferred tax and changing control remain outside this implementation. Executable reference: `ConsolidationTest.php`.

## Year closing and statement continuity

Recognize income 100 and expense 30. Cash/other net assets increase by 70. Closing transfers net earnings to retained earnings:

| Debit | Credit |
|---|---|
| Income 100 | Expense 30; retained earnings 70 |

Income and expense closing balances become zero, retained earnings gains a credit of 70, and the year's income statement still reports profit of 70 because it excludes the closing transfer. Closing preserves dimensions and closes all complete, contiguous periods in the requested year.

PostgreSQL independently verifies complete contiguous periods, cleared earnings by account/dimension, the declared retained-earnings destination, and cleared balances at transaction commit. Verify fiscal-calendar boundaries, retained-earnings mappings, dimension treatment, adjustments after closing, reopening permissions, and restatement workflows. Executable references: `ClosingAndSchedulesTest.php` and `SubledgerCommitTest.php`.

## Bank evidence and cash flow

A statement with opening zero, a receipt of 100, and closing 100 can be matched to the existing debit of bank 100. Matching creates no new cash. Releasing a match changes only reconciliation evidence. A corrected import retains the original through a void and replacement. An omitted opening balance remains an unexplained reconciliation difference.

Simple cash entries can use reviewed counter-account cash-flow classifications. Compound/noncash acquisitions must use explicit classifications or remain visibly unclassified. Verify the classification independently; exact balancing alone does not establish the economic category.

Statement voids invalidate evidence at the knowledge cutoff, allowing corrected historical imports without rewriting older snapshots. Their event dates must follow the statement closing date and all match/release history. Cross-book batch journals likewise require a matching atomic batch correction; one-sided journal reversals fail at commit.

Executable references: `BankingTest.php` and `CashFlowTest.php`.

## Review record

Record reviewer, qualifications, review date, policy version, approved cases, corrections, and outstanding issues in the host project's accounting policy records. An approval of one case or industry must not be generalized to all cases, countries, or customers.
