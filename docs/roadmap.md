# Roadmap

What the engine still needs before it is a complete accounting core, in the order to build it. Everything not listed here is implemented and tested; the README documents it and [subledgers.md](subledgers.md) gives its accounting conventions.

Each item lands the same way as the rest of the engine: database-enforced invariants, exact corrections, immutable evidence, and hand-verified fixtures in the test file for its area. An item is done when its acceptance fixture passes and this file no longer lists it.

## 1. Income tax and deferred tax

**Why.** Every company's year-end needs a current tax charge and deferred tax (IAS 12). Today deferred tax exists only on consolidation fair-value adjustments (`Consolidation\FairValue`); a single company cannot produce its tax expense, tax payable, or deferred tax balances.

**Scope.**
- Tax base per asset beside the accounting register: capital-allowance (Kenya wear-and-tear / investment deduction) classes, rates and pools, effective-dated and version-pinned like VAT rules.
- Tax computation per book and year: accounting profit, explicit permanent and temporary adjustments, losses brought forward and their expiry, rate, and instalment tax paid.
- Deferred tax from temporary differences on assets (carrying amount vs tax base), allowances, provisions and tax losses, with a recognition decision for deferred tax assets.
- Posting through roles `tax.income.current`, `tax.income.payable`, `tax.deferred.asset`, `tax.deferred.liability`, with movements through profit or loss or OCI (for revaluation surplus).
- Immutable computation evidence, corrections, and a link to the year-end checklist.

**Where.** A new `IncomeTax` service and `database/income_tax.pgsql.sql`; tax-base columns or a companion register beside `Assets`; `Tax\Kenya` supplies the capital-allowance classes.

**Acceptance.** A year with depreciation 1,000 against capital allowances 1,500 at 30% gives deferred tax liability 150; tax losses brought forward reduce current tax; a revaluation surplus books its deferred tax through OCI; reversing an adjustment restores the prior computation exactly.

## 2. Backdated reposting for inventory and currency

**Why.** A late stock receipt, a corrected unit cost or a revised exchange rate does not reprice issues and valuations already posted after it. Users must post manual adjustments.

**Scope.**
- A controlled repost command per inventory pool from a date: recompute FIFO/moving-average layers and issue costs after that date, and post one adjustment journal per affected period with the old and new cost of every movement as evidence.
- The same for revaluations of open items and monetary accounts after a rate revision.
- Locked periods block reposting unless reopened; saved snapshots stay unchanged; the repost itself is reversible.

**Where.** `Inventory`, `Revaluations`, `MonetaryAccounts`, `Rates`.

**Acceptance.** Receive 10 at 100, issue 5, then backdate a receipt of 10 at 70 before the issue under moving average: repost moves cost of sales by the exact difference, and reversing the repost restores the original layers.

## 3. Typed report and evidence results

**Why.** `Reports::statement()`, `Reports::statementEvidence()`, `Consolidation::get()`, `Taxes::preview()` and the `evidence()` methods return `array<string, mixed>`. Every consumer, including our tests, has to dig through them untyped.

**Scope.** Readonly result objects (or precise PHPStan array shapes where the JSON is the contract) for statements, consolidation reports, tax previews and returns, and each subledger's evidence. Row-returning reads (`generalLedger()`, `Taxes::register()`, `Banking::lines()`) return typed rows instead of `stdClass`.

**Acceptance.** The tests read these results without `data_get`, `text()` or `items()`.

## 4. Individually assessed and forward-looking credit losses

**Why.** Receivables only have the provision matrix (`Settlements::provide()`). A specific doubtful customer or a macro-economic overlay cannot be expressed.

**Scope.** Per-party or per-item assessments that override the matrix for those items, an explicit forward-looking adjustment by bucket, and the movement reconciliation (opening, charge, write-off, recovery, closing) needed for disclosure.

**Where.** `Settlements` and `ledger_allowance_runs`.

## 5. Contract revenue

**Why.** Revenue recognition stops at schedules and the advance-funded register. There are no contract assets, variable consideration, or contract modifications.

**Scope.** Contract assets for revenue earned before billing and their transfer to receivables on invoice; variable consideration (discounts, rebates, penalties) with constraint and reassessment; allocation across performance obligations for billed (not only advance-funded) contracts; modifications as separate contracts or cumulative catch-up.

**Where.** `Finance` revenue registrations and `Documents`.

## 6. Loans: effective interest with fees and floating rates

**Why.** `Finance::modify()` handles fixed-rate, fee-free, non-derecognizing changes only.

**Scope.** Effective interest including arrangement fees and transaction costs; floating-rate resets; derecognition and a new instrument on substantial modification (the ten-percent test already rejects these); lease remeasurement at a revised discount rate; automated stage and default-horizon models feeding the existing reviewed-ECL inputs.

**Where.** `Finance` and `database/finance.pgsql.sql`.

## 7. Group accounting

Only needed by multi-entity users.

- Automatic elimination journals from `Consolidation::matchIntercompany()` results.
- Unrealized profit in intercompany inventory and asset transfers.
- Ownership changes: step acquisition (associate to subsidiary), changes in a stake after control, loss of control.
- Foreign subsidiaries: goodwill impairment in foreign currency and translation-reserve recycling on disposal.
- Associates: ranked long-term interests forming part of the net investment.

## 8. Retrospective policy changes

**Why.** `YearEnd::restate()` corrects one prior period through retained earnings. A change in accounting policy applied across several prior years, with restated opening balances for each comparative, is not supported.

## Before the first release

- **Accountant review.** A Kenyan accountant signs off the cases in [accounting-review.md](accounting-review.md); record reviewer, date and corrections there.
- **Scale.** Measure posting throughput, report query plans and backup/restore on production-sized data.
- **Repository.** Create the GitHub repository, make CI's `verify` job a required check on `main`, and register the package on Packagist.

## Kenya localization

- iTax upload files for VAT3. KRA publishes the CSV columns only inside the downloaded workbook; build them from a current template.
- eTIMS: certification against the KRA sandbox with a real PIN and device serial, and the VSCU variant.
- Payroll statutory deductions (PAYE, NSSF, SHIF, housing levy) belong in a separate payroll package that posts into the ledger.

## Not planned in this package

Host applications or separate packages own these: dunning and dispute workflows, live bank and M-Pesa API feeds, exchange-rate provider adapters, practice-firm membership and engagement workflows, and every user interface.
