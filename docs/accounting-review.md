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

## Asset depreciation, impairment, and disposal

Capitalize equipment at 12,000 with twelve monthly depreciation amounts of 1,000 and zero residual. With actual-day partial-period depreciation, disposal on February 14 recognizes January's 1,000 plus February's 500. Proceeds of 8,000 result in a loss of 2,500, and cost/contra GL balances clear. Reversing disposal restores the exact disposal and February depreciation effects while retaining January depreciation.

Alternatively, after January depreciation, recognize impairment 3,000 and replace the future plan with amounts totaling 8,000. Final accumulated depreciation is 9,000, accumulated impairment 3,000, and carrying value zero. An imported asset at cost 10,000 with opening depreciation 3,000, opening impairment 1,000, and residual 1,000 has future depreciation totaling 5,000.

Review capitalization eligibility, dates available for use, useful life, residual, selected partial-period convention, impairment recoverable amount, replacement plans, tax differences, and disposal gain/loss presentation. New impairment-reversal and OCI-revaluation policies remain outside these cases. Executable references: `AssetTest.php`, `ConcurrencyTest.php`, and `SubledgerCommitTest.php`.

## Foreign group translation and eliminations

A USD subsidiary has cash 200, capital 100, and income 100. At closing rate 140, historical capital rate 130, and approved income average 135, translated cash is KES 28,000, capital 13,000, income 13,500, and a credit translation reserve 1,500. Independently rounded account differences belong in a separate rounding reserve. Historical equity can instead use exact approved translated rollforwards.

For two same-currency books, combine source balances with explicit elimination-book journals. A saved report remains unchanged after subsequent backdated source entries, and reading it requires current permission on every source book. A second accounting-treatment book for the same source entity must not be counted twice.

Review the group perimeter, ownership/control, historical equity rollforwards, income-rate suitability, elimination entries, differences in accounting policy, reserves, and period comparability. The module sums 100% of members and does not calculate ownership, NCI, goodwill, deferred tax, or automatic eliminations. Executable reference: `ConsolidationTest.php`.

## Year closing and statement continuity

Recognize income 100 and expense 30. Cash/other net assets increase by 70. Closing transfers net earnings to retained earnings:

| Debit | Credit |
|---|---|
| Income 100 | Expense 30; retained earnings 70 |

Income and expense closing balances become zero, retained earnings gains a credit of 70, and the year's income statement still reports profit of 70 because it excludes the closing transfer. Closing preserves dimensions and closes all complete, contiguous periods in the requested year.

Verify fiscal-calendar boundaries, retained-earnings mappings, dimension treatment, adjustments after closing, reopening permissions, and restatement workflows. Executable reference: `ClosingAndSchedulesTest.php`.

## Bank evidence and cash flow

A statement with opening zero, a receipt of 100, and closing 100 can be matched to the existing debit of bank 100. Matching creates no new cash. Releasing a match changes only reconciliation evidence. A corrected import retains the original through a void and replacement. An omitted opening balance remains an unexplained reconciliation difference.

Simple cash entries can use reviewed counter-account cash-flow classifications. Compound/noncash acquisitions must use explicit classifications or remain visibly unclassified. Verify the classification independently; exact balancing alone does not establish the economic category.

Executable references: `BankingTest.php` and `CashFlowTest.php`.

## Review record

Record reviewer, qualifications, review date, policy version, approved cases, corrections, and outstanding issues in the host project's accounting policy records. An approval of one case or industry must not be generalized to all cases, countries, or customers.
