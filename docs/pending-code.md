# Pending code

Engine work that code can close, in build order. The README documents implemented behavior and [subledgers.md](subledgers.md) its accounting conventions; an IFRS requirement missing from both and from this list is not implied to be implemented. Each item lands with database-enforced invariants where financial state changes, exact corrections, immutable evidence, hand-verified fixtures in the test file for its area, and passing gates. Accounting policy is always an explicit, versioned input supplied by the host or a configuration pack, never a silent default; the choices each item needs are listed in [pending-non-code.md](pending-non-code.md#policy-decisions-per-code-item). Remove an item only after the README documents its contract.

Items 1–6 are implemented: the [reporting model](../README.md#classified-reporting-policies), [typed public results](../README.md#public-result-contracts), [comprehensive income and changes in equity](../README.md#comprehensive-income-and-changes-in-equity), [budget commitments](../README.md#budget-commitments), [current income tax](../README.md#current-income-tax), and [tax bases and deferred tax](../README.md#tax-bases-and-deferred-tax) are implemented and documented. Entity-specific accounting-policy approval remains in [pending-non-code.md](pending-non-code.md#accountant-review).

Size is relative: **S** is about half of **M**, **M** is roughly the C1 and C2 work together (paged receivables reads and billing runs), **L** about twice that, **XL** three or more times. **Before** items block the first release, together with the non-code gates in [pending-non-code.md](pending-non-code.md#first-release).

| # | Item | Depends on | Size | Release |
|---|---|---|---|---|
| 7 | [Individually assessed and forward-looking credit losses](#7-individually-assessed-and-forward-looking-credit-losses) | — | M | After |
| 8 | [Retrospective policy changes](#8-retrospective-policy-changes) | 5 | L | After |
| 9 | [Contract revenue](#9-contract-revenue) | — | L | After |
| 10 | [Loans: fees, floating rates, substantial modification](#10-loans-fees-floating-rates-substantial-modification) | — | L | After |
| 11 | [Controlled backdated reposting](#11-controlled-backdated-reposting) | — | XL | After |
| 12 | [Group-accounting extensions](#12-group-accounting-extensions) | 6 | XL | After |
| 13 | [Scale harness coverage](#13-scale-harness-coverage) | — | S | Before |
| 14 | [Kenya: iTax VAT3 upload and eTIMS VSCU](#14-kenya-itax-vat3-upload-and-etims-vscu) | non-code inputs | M | After |

## 7. Individually assessed and forward-looking credit losses

**Today.** `Settlements::provide()` applies an aging provision matrix per control account.

**Build.** Per-party or per-item assessments that replace the matrix for those items, an explicit forward-looking overlay by bucket, and the allowance movement reconciliation: opening, charge, write-offs, recoveries, closing.

**Acceptance.** Of receivables 1,000, a specifically assessed item of 200 needs a loss of 100. The remaining 800 at 5% needs 40, and an overlay adds 10: allowance 150 with no double counting. Changing the specific loss to 60 releases 40. The movement reconciles, and saved assessments keep their inputs.

## 8. Retrospective policy changes

**Today.** `YearEnd::restate()` corrects one prior period through retained earnings.

**Build.** One policy-change identity with per-period as-if effects, earliest-comparative opening adjustment, tax effects, an explicit impracticability decision and immutable replacement comparative evidence. Existing saved reports stay unchanged.

**Acceptance.** A policy reduces earliest opening retained earnings by 30 and each of two comparative years' profit by 10. Cumulative equity effects are -30, -40 and -50 at the successive boundaries, with no duplicate current-period expense. Tax and affected balances reconcile, and a correction restores every linked effect together.

## 9. Contract revenue

**Today.** Revenue schedules and the advance-funded revenue register (allocation across obligations funded in advance).

**Build.** Contract assets for revenue earned before an unconditional right to bill, transferred to receivables on invoice. Variable consideration (discounts, rebates, penalties) with a constraint and recorded reassessments. Allocation across performance obligations for billed contracts by standalone selling price. Modifications as a separate contract or a cumulative catch-up.

**Acceptance.** Earning 100 before billing gives contract asset 100 and revenue 100; invoicing transfers 100 to receivables without recognizing revenue again. A reassessment changes variable consideration from 20 to 10 and is retained. Standalone prices 80/40 allocate consideration 100 as 66.67/33.33. Both modification treatments conserve consideration and correct exactly.

## 10. Loans: fees, floating rates, substantial modification

**Today.** The finance register handles fixed-rate, fee-free contracts and non-derecognizing modifications; the ten-percent test rejects substantial ones.

**Build.** Effective interest including integral fees and transaction costs. Floating-rate resets that keep accrued history. Derecognition and a replacement instrument on a reviewed substantial modification. Lease remeasurement at a revised discount rate. Stage and default-horizon models that feed the existing reviewed ECL inputs.

**Acceptance.** A lender disburses 1,000 and receives an integral fee of 50 (amortized cost 950); one receipt of 1,100 a period later earns interest 150 with fee evidence. A floating reset keeps history and conserves the revised terminal balance. A substantial modification derecognizes and replaces atomically; a non-substantial one keeps the original effective rate. Lease remeasurement and stage decisions have their own fixtures and exact corrections.

## 11. Controlled backdated reposting

**Today.** Chronology is enforced: late receipts, corrected costs and revised rates do not reprice later issues or valuations. Explicit corrections and `Inventory::adjustValue()` are the fallback.

**Build.** A repost command that recomputes affected inventory and currency histories through new events, never by editing old layers or evidence. It keeps old and new costs per movement and posts conserving per-period adjustments. It covers dependent returns, transfers, production, write-downs, allocations and later valuations, or rejects unsupported dependency graphs explicitly. Locked periods need reopening, saved snapshots stay unchanged, and reversing a repost restores the prior state in dependency order. Histories posted before the feature need a defined transition.

**Acceptance.** Receive 10 at 100, issue 5 for 500. Insert a reviewed earlier receipt of 10 at 70: the moving-average issue cost becomes 425, remaining 15 units are worth 1,275, and cost of sales falls by 75. Reversing restores the prior state without changing saved cutoffs. Separate fixtures cover cross-pool dependencies, closed periods, partial settlements, revised FX rates, retries and concurrent activity; unsupported graphs fail atomically.

## 12. Group-accounting extensions

**Today.** Consolidation with translation, NCI, investment elimination, goodwill, associates and `matchIntercompany()`.

**Build.**
- Automatic elimination journals from matched intercompany balances.
- Unrealized profit in intercompany inventory and asset transfers, with its tax effect.
- Ownership changes: step acquisition (associate to subsidiary), stake changes after control, loss of control.
- Foreign subsidiaries: goodwill impairment in foreign currency and translation-reserve recycling on disposal.
- Associates: ranked long-term interests forming part of the net investment.

**Acceptance.** Matched balances of 100 eliminate once. An intercompany sale of 100 at cost 80 with half still held eliminates unrealized profit 10 and its tax effect. Hand-verified fixtures reconcile each ownership change, foreign goodwill impairment and reserve recycling, and ranked associate interests. Results pin source and elimination cutoffs; corrections preserve saved reports.

## 13. Scale harness coverage

**Today.** `tests/benchmark.php` measures committed command throughput for six workloads and, with `--scaling`, compares read and billing-run costs at two book sizes.

**Build.** Add same-tenant multi-book traffic, skewed dimension distributions and deeper inventory and finance histories to the harness, so the staging run in [pending-non-code.md](pending-non-code.md#scale-acceptance) has workloads for every protected register. Measurements must not run inside an enclosing transaction or with disabled triggers, relaxed durability or a bypass-RLS role; they record hardware, PostgreSQL/PHP settings, cardinalities, revision and inputs.

## 14. Kenya: iTax VAT3 upload and eTIMS VSCU

**Today.** VAT3 section totals, the eTIMS OSCU client, sale and credit-note builder, and the statutory delivery adapter.

**Build.**
- An iTax VAT3 upload file. KRA publishes the CSV columns only inside its downloadable workbook, so this needs the current template first.
- The eTIMS VSCU variant beside OSCU, from KRA's VSCU specification.

Both are blocked on inputs listed in [pending-non-code.md](pending-non-code.md#kenya-inputs).

## Not planned in this package

Host applications or separate packages own these: dunning and dispute workflows, live bank and M-Pesa API feeds, exchange-rate provider adapters, practice-firm membership and engagement workflows, payroll statutory deductions (PAYE, NSSF, SHIF, housing levy) posted into the ledger by a payroll package, and every user interface.

IAS 37 recognition judgments, contingent-liability/asset assessments and narrative disclosures remain host-owned. Reviewed ordinary provision postings and explicit discount-unwinding schedules use existing journals/schedules; a dedicated protected provisions register is not planned. See [subledgers.md](subledgers.md#provisions-and-contingencies).
