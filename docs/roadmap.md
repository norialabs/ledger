# Roadmap

Remaining capabilities within the package's accounting scope, in build order. The README documents implemented behavior and [subledgers.md](subledgers.md) gives its accounting conventions and host-owned boundaries. This is not an assertion that every unlisted IFRS requirement is implemented.

Each item retains exact corrections, immutable evidence, hand-verified fixtures in the test file for its area, and database enforcement where financial state changes. Completion requires its acceptance cases and the ordinary verification gates; remove completed scope only after documenting the supported contract. Release labels distinguish the first usable package release from completion of the whole roadmap.

## 1. Scale baseline

**Release.** Before release. Repeat affected workloads after material posting/query changes.

**Why.** Book mutations serialize on the book row and linked integrity checks run at commit. Measure their cost while baseline migrations can still be edited in place.

**Scope.** Reproducible committed workloads for simple journals, open-item allocations, FIFO/moving-average stock and protected finance. Compare one busy book with multiple books and tenants, including skewed dimensions and reporting during writes. Capture throughput, p50/p95/p99 command latency, report query plans/buffers, database size and backup/restore time. Record hardware, PostgreSQL/PHP settings, data cardinalities, revision and workload inputs. No enclosing transaction, disabled triggers, relaxed durability or bypass-RLS role may inflate results.

**Acceptance.** A repeatable local baseline verifies balances and saved cutoffs. Before release, the intended deployment owner supplies a production-sized workload and numerical latency, throughput and recovery budgets; record results and pass/fail against those budgets here. Small local runs and harness smoke checks do not complete this gate.

**Local evidence, 2026-10-28.** `tests/benchmark.php`, working tree based on `352be8f`, Apple M4 Pro (12 cores, 24 GiB), PHP 8.5.12 with Xdebug disabled, PostgreSQL 18.6, `shared_buffers=128MB`, `synchronous_commit=on`, `fsync=on`, non-superuser/non-bypass-RLS role. Each run used four writer processes, 100 iterations each and a separate reader making 100 fixed-cutoff income-statement requests. Planner statistics were refreshed after seeding; initialization and seeding are excluded from measured writer time. Books in the four-book run belong to distinct tenants.

| Workload | Seed journals/book | Books | Committed commands/s | Command p95 ms | Reader p95 ms |
|---|---:|---:|---:|---:|---:|
| Journal | 10,000 | 1 | 501 | 11.98 | 10.12 |
| Journal | 10,000 | 4 | 1,505 | 3.30 | 10.50 |
| Invoice + receipt + allocation | 1,000 | 1 | 483 | 10.58 (allocation) | 2.74 |
| Invoice + receipt + oldest-first allocation, 100 other parties | 6,000 (3,000 settled pairs) | 1 | 285 | 30.85 (allocation) | 5.26 |
| FIFO issue | 1,000 | 1 | 289 | 19.81 | 2.66 |
| Moving-average issue | 1,000 | 1 | 315 | 17.60 | 3.09 |
| Protected loan period | 1,000 | 1 | 250 | 20.71 | 2.61 |

Allocation throughput counts three separately committed commands per workflow; other rows count one. Inventory uses one funded pool/receipt layer, and finance uses one zero-interest amortizing contract per writer, each with 100 slots. These are single short warm-cache trials, not a sustained-load or production-sized capacity certification. The reader can finish before writers; this is not a fixed-duration mixed-workload test. Remaining coverage includes same-tenant multi-book traffic, skewed dimensions, deeper inventory/finance histories and deployment-specific budgets.

The first larger run exposed repeated aggregate joins in open-item reads and poor plans from text-cast tenant policies/stale statistics. Open-item aggregates now execute once and RLS compares indexed UUIDs directly; bulk loading must be followed by normal planner-statistics maintenance. On the same 1,800-journal/800-item book, UUID policy changes reduced warm open-item reads from roughly 497 ms to 8–9 ms before refreshing statistics. Freshly analyzed final allocation verification took 7.54 ms. Party-scoped open-item reads then still aggregated every allocation and revaluation in the book, which `allocateOldest()` runs under the book lock; they now aggregate only that party's items, with a partial party index on postings. The oldest-first row ran 50 iterations per writer; on the same fixture the whole-book aggregate gave 201 commands/s and allocation p95 47.90 ms. All runs verified balancing, saved numerical cutoffs and workload-specific conservation.

A custom-format `pg_dump` of the accumulated 976,000,703-byte disposable database took 3.10 seconds; restoration into a separate disposable database took 17.58 seconds, excluding verification. Counts and ordered whole-row hashes matched for all 58 ledger tables (1,081,073 rows); a non-bypass-RLS session reproduced old/latest profit 10,000/10,400 from restored cutoffs. The restore database was then dropped. This local administrative backup/restore exercise does not set the deployment's recovery objectives.

## 2. Reporting model: IFRS 18, OCI and equity movements

**Release.** Settle the contract before typed results; complete the supported reporting scope before release. Full financial-statement preparation and narrative disclosures remain host-owned.

**Why.** `Reports::incomeStatement()` totals income and expenses without reporting OCI. Equity postings alone do not produce a comprehensive-income statement or explain changes in equity. IFRS 18 applies to full-IFRS annual periods beginning on or after 1 January 2027 and affects comparative presentation too.

**Scope.** Versioned reporting mappings and definitions, separate from `AccountType` and cash-flow classifications. Support profit-or-loss categories (operating, investing, financing, income taxes and discontinued operations), required subtotals and explicit main-business-activity policy. Define OCI categories and recycling, equity components and movement attribution, including current profit, distributions, contributions, transfers and restatements. Preserve immutable classifications/evidence for saved results. Management-defined performance measures need explicit reviewed definitions, reconciliation to an IFRS subtotal and tax/NCI effects; the host supplies public-communication eligibility decisions and explanatory disclosures.

**Acceptance.** Accountant-reviewed definitions classify the same interest activity appropriately for an ordinary trading entity and a financing business without changing cash-flow metadata. Required subtotals reconcile, OCI is excluded from profit, an MPM reconciles with explicit tax/NCI inputs, and a revised definition cannot rewrite a saved report. Resolve the movement and comparative contracts needed by items 3–4 before finalizing their public types.

## 3. Typed public report and evidence results

**Release.** Before release.

**Why.** `Consolidation::get()`, `Taxes::preview()` and several subledger `evidence()` methods still return broad arrays. Consumers, including our tests, have to dig through these results untyped; row-returning APIs also expose database objects.

**Scope.** Readonly result objects (or precise PHPStan array shapes where the JSON is the contract) for statements, consolidation reports, tax previews and returns, and each subledger's evidence. Row-returning reads (`generalLedger()`, `Taxes::register()`, `Banking::lines()`) return typed rows instead of `stdClass`.

**Progress.** Declarative statements and their saved results now return `Reporting\Statement` with typed monetary cells, definitions, comparative columns and configuration evidence. `generalLedger()` returns `Reporting\LedgerEntry`, composing the existing typed journal header and line. Tests read these results directly and verify historical JSON/byte-hash compatibility. Consolidation, tax, banking and other subledger evidence remain to be converted; the item is not complete, and the specialized reporting contracts in item 2 are still pending.

**Acceptance.** Public result fields have precise statically checked types and consumers read documented fields without dynamic traversal or unchecked casts. Tests no longer need `data_get`, `text()` or `items()` for these results. Internal database rows and arbitrary external JSON are not the public-result contract. Stored JSON representations, original evidence bytes/hashes and earlier saved results remain readable without re-encoding or rewriting history.

## 4. Comprehensive income and changes in equity

**Release.** Before release for the declared core reporting scope; depends on items 2–3.

**Scope.** Profit, OCI and total comprehensive income, plus opening-to-closing reconciliation per equity component. Distinguish owner contributions/distributions, revaluation/translation reserves, transfers and retrospective opening adjustments. Retain comparative cutoffs, explicit coverage and unclassified-movement failures. Year-end earnings transfers must not recognize profit twice. Include parent/NCI attribution only where the existing group model supports it.

**Where.** `Reports`, statement definitions and the evidence of equity-producing commands.

**Acceptance.** Opening equity 1,000 plus profit 200, OCI 60 and share issue 100 less dividends 50 gives closing equity 1,310 and comprehensive income 260. A transfer of 20 between equity components changes neither total. Closing earnings leaves both statements unchanged. A separately attributed opening restatement of 30 changes restated opening/closing equity, not current profit; prior saved statements retain their original figures.

## 5. Current income tax

**Release.** After release; reviewed tax journals remain available before automation.

**Scope.** Computation per book/year from accounting profit, explicit permanent/temporary adjustments and reviewed capital allowances, losses by vintage/source, enacted or substantively enacted rates, instalments and credits. Pin loss eligibility/expiry/extension rules to each computation, rather than reading a mutable expiry setting. Reconcile charge, payments and payable; retain review decisions, corrections and a year-end checklist link. No asset tax-base register is required for this first increment.

**Where.** `IncomeTax`, `database/income_tax.pgsql.sql`, roles `tax.income.current` and `tax.income.payable`; versioned Kenya rules supplied by `Tax\Kenya`.

**Acceptance.** Profit 10,000 plus disallowed expenses 1,000 less additional allowances 500 and eligible brought-forward losses 2,000 gives taxable profit 8,500. At 30%, tax is 2,550; instalments 2,000 leave payable 550. An expired or wrong-source loss cannot be consumed; a rule revision cannot change earlier evidence; correction restores the exact previous balances and loss usage.

## 6. Tax bases and deferred tax

**Release.** After release; builds on current tax and equity/OCI reporting.

**Scope.** Tax bases alongside assets and other temporary differences, recognized/unrecognized deferred tax assets, reviewed recoverability, exemptions and expected recovery/settlement rates. Track losses separately from their asset-recognition decision. Post through `tax.deferred.asset` and `tax.deferred.liability`, attributed to profit, OCI or direct equity as appropriate. Kenya capital allowances use effective-dated asset-class rules, initial allowances and applicable legacy tax-written-down balances, not an assumed reducing-balance pool model. Accountant review must confirm rates, transitions and loss rules against current legislation.

**Acceptance.** An asset starts with equal carrying amount/tax base and no deferred tax; depreciation 1,000 versus capital allowances 1,500 leaves taxable temporary difference 500 and closing liability 150 at 30%. A later reversal releases the liability; an opening liability is reconciled separately from the current charge. A revaluation surplus attributes related tax to OCI; loss assets require an explicit recognition decision; exact corrections restore bases, postings and evidence.

## 7. Individually assessed and forward-looking credit losses

**Release.** After release.

**Why.** Receivables only have the provision matrix (`Settlements::provide()`). A specific doubtful customer or a macro-economic overlay cannot be expressed.

**Scope.** Per-party or per-item assessments that override the matrix for those items, an explicit forward-looking adjustment by bucket, and the movement reconciliation (opening, charge, write-off, recovery, closing) needed for disclosure.

**Where.** `Settlements` and `ledger_allowance_runs`.

**Acceptance.** Of receivables 1,000, a specifically assessed item of 200 requires 100 loss; the remaining 800 at 5% requires 40, and an explicit overlay adds 10: allowance 150 with no double counting. Changing the specific loss to 60 releases 40. Opening allowance, charge, write-offs, recoveries and closing allowance reconcile; saved assessments and exact corrections retain their inputs.

## 8. Retrospective policy changes

**Release.** After release; depends on comparative and equity movement contracts.

**Why.** `YearEnd::restate()` corrects one prior period through retained earnings. A policy change across several prior years needs restated opening balances and comparative attribution, not repeated unrelated corrections.

**Scope.** One reviewed policy-change identity with per-period as-if effects, earliest comparative opening adjustments, tax effects, explicit impracticability decisions and immutable replacement comparative evidence. Existing saved reports remain unchanged.

**Acceptance.** A policy reduces earliest opening retained earnings by 30 and each of two comparative years' profit by 10. Restated cumulative equity effects are -30, -40 and -50 at the successive boundaries, with no duplicate current-period expense. Tax and affected balance-sheet components reconcile, and correction restores all linked effects together.

## 9. Contract revenue

**Release.** After release.

**Why.** Revenue recognition stops at schedules and the advance-funded register. There are no contract assets, variable consideration, or contract modifications.

**Scope.** Contract assets for revenue earned before billing and their transfer to receivables on invoice; variable consideration (discounts, rebates, penalties) with constraint and reassessment; allocation across performance obligations for billed (not only advance-funded) contracts; modifications as separate contracts or cumulative catch-up.

**Where.** `Finance` revenue registrations and `Documents`.

**Acceptance.** Earn 100 before unconditional billing: contract asset 100 and revenue 100. Invoice it: transfer 100 to receivables without recognizing revenue twice. Reviewed variable consideration changes 20 to 10 through a retained reassessment; standalone prices 80/40 allocate consideration 100 as 66.67/33.33. Separate-contract and cumulative-catch-up modifications conserve consideration and correct exactly.

## 10. Loans: effective interest with fees and floating rates

**Release.** After release.

**Why.** `Finance::modify()` handles fixed-rate, fee-free, non-derecognizing changes only.

**Scope.** Effective interest including arrangement fees and transaction costs; floating-rate resets; derecognition and a new instrument on substantial modification (the ten-percent test already rejects these); lease remeasurement at a revised discount rate; automated stage and default-horizon models feeding the existing reviewed-ECL inputs.

**Where.** `Finance` and `database/finance.pgsql.sql`.

**Acceptance.** A lender disburses 1,000 and receives an integral fee of 50, leaving initial amortized cost/net cash 950; a single receipt of 1,100 one period later earns total interest 150 with fee/cost evidence. A floating reset preserves accrued history and conserves the revised terminal balance. Reviewed substantial modifications derecognize and replace atomically, while non-substantial changes retain the original EIR. Lease remeasurement and stage/horizon decisions retain independent reviewed fixtures and exact corrections.

## 11. Controlled backdated reposting for inventory and currency

**Release.** Deliberately after release. Existing chronology guarantees stay in force until this is implemented.

**Why.** Late receipts, corrected unit costs and revised rates do not automatically reprice later issues/valuations. Existing explicit corrections and permitted value adjustments are the fallback; detached manual journals cannot bypass protected registers. `Inventory::adjustValue()` affects remaining stock, not historical issues.

**Scope.** Recompute affected histories through new events, never by editing old layers/evidence. Retain old/new movement costs and post conserving per-period adjustments. Include dependent returns, transfers, production, write-downs, allocations and later valuations, or reject unsupported dependency graphs explicitly. Locked periods require reopening, saved snapshots stay unchanged, and repost corrections restore the prior active state in dependency order. Define transition support for histories posted before the feature exists.

**Where.** `Inventory`, `Revaluations`, `MonetaryAccounts`, `Rates` and their database protections.

**Acceptance.** Receive 10 units at unit cost 100 and issue 5 for cost 500. Insert a reviewed earlier receipt of 10 at unit cost 70: moving-average issue cost becomes 425, remaining quantity 15/value 1,275 and cost of sales decreases 75. Reversing the repost restores the prior active cost/layer state without changing saved cutoffs. Separate fixtures cover cross-pool dependencies, closed periods, partial settlements, revised FX rates, retries and concurrent activity; unsupported graphs fail atomically.

## 12. Group-accounting extensions

**Release.** After release.

Only needed by multi-entity users.

- Automatic elimination journals from `Consolidation::matchIntercompany()` results.
- Unrealized profit in intercompany inventory and asset transfers.
- Ownership changes: step acquisition (associate to subsidiary), changes in a stake after control, loss of control.
- Foreign subsidiaries: goodwill impairment in foreign currency and translation-reserve recycling on disposal.
- Associates: ranked long-term interests forming part of the net investment.

**Acceptance.** Matched intercompany balances 100 eliminate once; an intercompany sale 100 at cost 80 with half still held eliminates unrealized profit 10 and its reviewed tax effect. Hand-verified fixtures separately reconcile step acquisition, continuing-control ownership change and loss of control, foreign goodwill impairment/reserve recycling, and ranked associate interests. Every result pins source and elimination cutoffs; corrections preserve saved reports.

## Consumer requirements

The first consumers are BillGrid (water, sewer and waste billing, growing into county utility ERPs), a school management system and a hospital management system. All three run PostgreSQL 18, one shared database with RLS by tenant UUID, integer minor units and Laravel 13. Their operational records (meters, tariffs, routes, fee structures, encounters, claims) stay in the host; these items are the core capabilities they need from the engine. BillGrid removed an earlier in-app ledger because nothing read it, so statements and aging must be served from the engine at utility scale.

### C1. Receivables reads at utility scale

**Release.** Before release; it changes the public read contract of item 3.

**Why.** `openItems()` and `aging()` without a party load every open item in the book into PHP. A county utility has 50,000–500,000 accounts.

**Scope.** Aging computed in SQL by currency, bucket and optional control account or dimension; cursor-paginated open items and per-party balances; party statements paginated by sequence. The same fixed-cutoff guarantees as existing reports.

**Acceptance.** On a book with 100,000 parties and 1,000,000 postings, book aging and a single party statement run in bounded memory, return the same totals as the in-memory implementation on the hand-verified fixtures, and meet the C2 budgets.

### C2. Billing-run posting

**Release.** Before release, with the scale gate.

**Why.** A billing run issues one invoice per account per cycle. At the measured 500 journals/s on one book, 100,000 bills take over three minutes under the book lock.

**Scope.** Post many documents in one command under one book lock and one sequence range, with per-document source identities, retries that replay exactly, and all-or-nothing failure. Reports and the integration feed see the run as ordinary journals.

**Acceptance.** A run of 10,000 invoices posts atomically, replays without new sequences, rejects one invalid document without posting the rest, and the scale baseline records run throughput.

### C3. Budget commitments

**Release.** After release.

**Why.** Public schools (vote heads) and county utilities control spending against approved budgets before invoices arrive.

**Scope.** Commitments and their release on invoice or cancellation per budget line and dimension, available-balance checks as an optional posting policy, and budget-versus-commitment-versus-actual reporting.

**Acceptance.** Budget 1,000, commitment 400, invoice 300 against it releasing 300 of the commitment: actual 300, open commitment 100, available 600; cancelling the remainder makes available 700.

## First-release gates

The first release is an exact posting/subledger engine with the explicitly documented reporting scope, not completion of every automation above or a claim to prepare all IFRS disclosures.

- **Public contract.** Complete items 2–4 and C1 for that scope, including typed results and immutable evidence compatibility. Explicitly document the tax, provision and disclosure work supplied by hosts.
- **Scale.** Complete item 1 and C2 against agreed budgets for a 100,000-account utility book, including recovery verification. A benchmark harness alone is insufficient.
- **Accountant review.** Start with core currency, stock, assets, year-end/reporting and VAT cases in [accounting-review.md](accounting-review.md), then industry illustrations. Record reviewer, date, approved scope, corrections and unresolved issues in its review record. Review new capabilities as they land rather than waiting for the end.
- **Repository.** Create the GitHub repository, make CI's `verify` job a required check on `main`, and register the package on Packagist.

## Kenya localization

- iTax upload files for VAT3. KRA publishes the CSV columns only inside the downloaded workbook; build them from a current template.
- eTIMS: certification against the KRA sandbox with a real PIN and device serial, and the VSCU variant.
- Payroll statutory deductions (PAYE, NSSF, SHIF, housing levy) belong in a separate payroll package that posts into the ledger.

## Not planned in this package

Host applications or separate packages own these: dunning and dispute workflows, live bank and M-Pesa API feeds, exchange-rate provider adapters, practice-firm membership and engagement workflows, and every user interface.

IAS 37 recognition judgments, contingent-liability/asset assessments and narrative disclosures remain host-owned. Reviewed ordinary provision postings and explicit discount-unwinding schedules use existing journals/schedules; a dedicated protected provisions register is not currently planned. See [subledgers.md](subledgers.md#provisions-and-contingencies).

## Policy sources

- [IFRS Foundation: IFRS 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/) and [IAS 1](https://www.ifrs.org/issued-standards/list-of-standards/ias-1-presentation-of-financial-statements/), checked 2026-10-28. IFRS 18 replaces IAS 1 for applicable periods; IFRS for SMEs users need their own reporting policy.
- [PwC Kenya: deductions](https://taxsummaries.pwc.com/kenya/corporate/deductions), reviewed by the publisher 2026-07-17 and checked 2026-10-28. Supports the need for straight-line asset-class rules and versioned loss treatment; the accountant must confirm current legislation and transitional provisions before the Kenya income-tax pack is implemented.
