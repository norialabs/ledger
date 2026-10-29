# Pending code

Engine work that code can close, in build order. The README documents implemented behavior and [subledgers.md](subledgers.md) its accounting conventions; an IFRS requirement missing from both and from this list is not implied to be implemented. Each item lands with database-enforced invariants where financial state changes, exact corrections, immutable evidence, hand-verified fixtures in the test file for its area, and passing gates. Accounting policy is always an explicit, versioned input supplied by the host or a configuration pack, never a silent default; the choices each item needs are listed in [pending-non-code.md](pending-non-code.md#policy-decisions-per-code-item). Remove an item only after the README documents its contract.

Items 1–10 are implemented: the [reporting model](../README.md#classified-reporting-policies), [typed public results](../README.md#public-result-contracts), [comprehensive income and changes in equity](../README.md#comprehensive-income-and-changes-in-equity), [budget commitments](../README.md#budget-commitments), [current income tax](../README.md#current-income-tax), [tax bases and deferred tax](../README.md#tax-bases-and-deferred-tax), [individually assessed and forward-looking credit losses](../README.md#individually-assessed-and-forward-looking-credit-losses), [retrospective policy changes](../README.md#retrospective-policy-changes), [contract revenue](../README.md#contract-revenue), and [loan fees, floating rates and replacement instruments](../README.md#loan-fees-floating-rates-and-replacement-instruments) are implemented and documented. Entity-specific accounting-policy approval remains in [pending-non-code.md](pending-non-code.md#accountant-review).

Size is relative: **S** is about half of **M**, **M** is roughly the C1 and C2 work together (paged receivables reads and billing runs), **L** about twice that, **XL** three or more times. **Before** items block the first release, together with the non-code gates in [pending-non-code.md](pending-non-code.md#first-release).

| # | Item | Depends on | Size | Release |
|---|---|---|---|---|
| 11 | [Controlled backdated reposting](#11-controlled-backdated-reposting) | — | XL | After |
| 12 | [Group-accounting extensions](#12-group-accounting-extensions) | 6 | XL | After |
| 13 | [Scale harness coverage](#13-scale-harness-coverage) | — | S | Before |
| 14 | [Kenya: iTax VAT3 upload and eTIMS VSCU](#14-kenya-itax-vat3-upload-and-etims-vscu) | non-code inputs | M | After |

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
