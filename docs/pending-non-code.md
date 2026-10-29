# Pending non-code work

Work that no amount of engine code can close: decisions, reviews, external inputs and accounts. Code items that depend on these are in [pending-code.md](pending-code.md).

| Area | Owner | Blocks |
|---|---|---|
| [Accountant review](#accountant-review) | A qualified accountant | First release |
| [Policy decisions per code item](#policy-decisions-per-code-item) | You, with the accountant | The matching code item's sign-off |
| [Tax legislation confirmation](#tax-legislation-confirmation) | A Kenya tax adviser | Code items 5, 6 and the Kenya rule packs |
| [Scale acceptance](#scale-acceptance) | You and the deployment owner | First release |
| [Kenya inputs](#kenya-inputs) | You | Code item 14 and eTIMS go-live |
| [Repository and publication](#repository-and-publication) | You | First release |

## First release

The first release is an exact posting and subledger engine with an explicitly documented reporting scope, not completion of every code item or a claim to prepare all IFRS disclosures. It needs the code items marked **Before** in [pending-code.md](pending-code.md), plus the accountant review, scale acceptance and repository rows above. The README must state the tax, provision and disclosure work hosts supply.

## Accountant review

Nothing has been independently reviewed; the review record in [accounting-review.md](accounting-review.md#review-record) is empty. The tests prove the engine does what was specified, not that the specification is correct accounting.

- Review the existing cases in [accounting-review.md](accounting-review.md) in this order: foreign-currency settlement and revaluation, stock cost and write-downs, assets, year closing and statement continuity, configured tax documents and Kenya VAT, then group accounting, finance and the industry illustrations.
- Record reviewer, qualifications, date, policy version, approved cases, corrections and open issues in the review record.
- Review each new code item as it lands rather than at the end.

## Policy decisions per code item

Each code item implements the mechanism with these as explicit inputs. Someone has to decide the values for a real entity and the accountant has to approve them.

| Code item | Decisions |
|---|---|
| 1 Reporting model (implemented) | Main business activity per entity type (trading, financing, investment). Account-to-category mappings for the shipped configuration packs. The financing-subtotal presentation decision, including the IFRS 18 paragraphs 73–74 exception. OCI eligibility, recycling/basis-adjustment conditions and reviewed opening-transition evidence. Which management-defined performance measures to offer, their definitions and adjustment-level tax/NCI methodologies. |
| 3 Equity statement (implemented) | The equity components to present (share capital, share premium, retained earnings, revaluation, translation and other reserves), how existing equity accounts map to them, and which component receives earnings. |
| 4 Budget commitments (implemented) | Host approval of the selected budget version and explicit over-budget/unbudgeted rejection settings. Warning delivery remains host-owned. |
| 5 Current income tax | Rates, instalment rules, loss carry-forward period and source restrictions, and the permanent and temporary adjustments to support. |
| 6 Deferred tax | Recognition policy for deferred tax assets, expected recovery manner and rate, and the capital-allowance asset classes. |
| 7 Credit losses | When an item is assessed individually, and how forward-looking overlays are set and approved. |
| 8 Policy changes | When retrospective application is impracticable and what evidence that decision needs. |
| 9 Contract revenue | Constraint policy for variable consideration, and how standalone selling prices are estimated. |
| 10 Loans | Which fees are integral to the effective rate, the floating reference rates, and the staging and default-horizon criteria. |
| 11 Reposting | Whether reposting into a closed period is ever allowed, and who approves it. |
| 12 Groups | Intercompany matching tolerances, and the tax rate applied to eliminated unrealized profit. |

## Tax legislation confirmation

Code items 5 and 6 and the Kenya packs need current Kenyan law confirmed by an adviser, not inferred from summaries:

- Corporate rate, including any reduced rates for specific entities.
- Capital allowance classes, rates, initial allowances and investment deductions, plus their effective dates and transitional rules for legacy tax-written-down balances.
- Loss carry-forward limits, extensions and restrictions.
- Instalment tax dates and amounts.
- VAT rates and rules already used by the shipped VAT pack, re-confirmed at review.

Sources checked 2026-10-28: [IFRS 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/), which replaces [IAS 1](https://www.ifrs.org/issued-standards/list-of-standards/ias-1-presentation-of-financial-statements/) for applicable periods (IFRS for SMEs users need their own reporting policy), and [PwC Kenya: deductions](https://taxsummaries.pwc.com/kenya/corporate/deductions), reviewed by the publisher 2026-07-17. The PwC summary supports straight-line asset-class rules and versioned loss treatment; it is not a substitute for the adviser's confirmation.

## Scale acceptance

The first consumers are BillGrid (water, sewer and waste billing, growing into county utility ERPs), a school management system and a hospital management system, all on PostgreSQL 18 with one shared database under tenant RLS. BillGrid removed an earlier in-app ledger because nothing read it, so statements and aging must be served from the engine at utility scale. The local evidence below shows reads do not grow with the book; it does not set capacity.

- Set latency budgets: one customer's statement, a debtor-list page, book aging and a page of open items.
- Set a billing-run budget, meaning how long a full run may hold the book lock.
- Set recovery objectives: how much data may be lost and how long a restore may take.
- Run `tests/benchmark.php` against a production-sized copy of BillGrid's data on the deployment's database hardware, record the results against those budgets here, and run a restore drill.

### Local evidence, 2026-10-28

Apple M4 Pro (12 cores, 24 GiB), PHP 8.5.12 with Xdebug disabled, PostgreSQL 18.6, `shared_buffers=128MB`, `synchronous_commit=on`, `fsync=on`, non-superuser/non-bypass-RLS role. Committed workloads, revision `352be8f`: four writer processes of 100 iterations each and a separate reader making 100 fixed-cutoff income-statement requests, with planner statistics refreshed after seeding and seeding excluded from writer time. Books in the four-book run belong to distinct tenants.

| Workload | Seed journals/book | Books | Committed commands/s | Command p95 ms | Reader p95 ms |
|---|---:|---:|---:|---:|---:|
| Journal | 10,000 | 1 | 501 | 11.98 | 10.12 |
| Journal | 10,000 | 4 | 1,505 | 3.30 | 10.50 |
| Invoice + receipt + allocation | 1,000 | 1 | 483 | 10.58 (allocation) | 2.74 |
| Invoice + receipt + oldest-first allocation, 100 other parties | 6,000 (3,000 settled pairs) | 1 | 285 | 30.85 (allocation) | 5.26 |
| FIFO issue | 1,000 | 1 | 289 | 19.81 | 2.66 |
| Moving-average issue | 1,000 | 1 | 315 | 17.60 | 3.09 |
| Protected loan period | 1,000 | 1 | 250 | 20.71 | 2.61 |

Allocation throughput counts three separately committed commands per workflow; other rows count one. Inventory uses one funded pool/receipt layer and finance one zero-interest amortizing contract per writer. The oldest-first row ran 50 iterations per writer. These are single short warm-cache trials, not sustained-load certification. All runs verified balancing, saved cutoffs and workload conservation.

A custom-format `pg_dump` of the accumulated 976,000,703-byte disposable database took 3.10 seconds; restoring into a separate disposable database took 17.58 seconds, excluding verification. Counts and ordered whole-row hashes matched for all 58 ledger tables (1,081,073 rows), and a non-bypass-RLS session reproduced old/latest profit 10,000/10,400 from restored cutoffs. This does not set the deployment's recovery objectives.

Scaling, `tests/benchmark.php --scaling --parties=1000 --cycles=5 --factor=10` after a schema reset: books of 1,000 and 10,000 accounts, 9,000 and 90,000 journals, 18,000 and 180,000 postings, 500 and 5,000 oldest-first allocations.

| Read | 1,000 accounts ms | 10,000 accounts ms | Ratio | PHP peak KiB |
|---|---:|---:|---:|---:|
| Trial balance | 5.18 | 30.81 | 5.9× | 25 / 25 |
| Book aging | 5.83 | 31.94 | 5.5× | 22 / 22 |
| Allowance exposure | 5.56 | 29.99 | 5.4× | 23 / 23 |
| Control reconciliation | 8.9 | 60.29 | 6.8× | 33 / 33 |
| Open items, first page of 500 | 4.36 | 5.65 | 1.3× | 1188 / 1188 |
| Open items, middle page of 500 | 4.54 | 5.45 | 1.2× | 1189 / 1189 |
| One party's open items | 1.09 | 1.21 | 1.1× | 33 / 33 |
| One party's aging | 0.81 | 0.83 | 1.0× | 22 / 22 |
| One party's statement | 0.96 | 1.04 | 1.1× | 38 / 38 |
| Party balances, first page of 500 | 4.88 | 5.62 | 1.2× | 520 / 520 |
| Party balances, middle page of 500 | 4.91 | 5.38 | 1.1× | 519 / 520 |

Billing runs posted 1,338 and 1,675 documents/s. Paged and per-party reads stay within 1.0–1.3× at ten times the book, whole-book reads grow 5–7×, and PHP memory does not grow with the book.

Classified reporting, revision `766f5e2` plus the item-1 working tree, `--scaling --parties=100 --cycles=2 --factor=10`: median classified-statement reads of 2.27 ms for 360 journals and 4.78 ms for 3,600 journals including source-attribution validation, 46.4 KiB incremental PHP peak at both sizes. A `--seed=1000 --iterations=100 --workers=4 --books=1 --workload=journal` run committed 400 commands at 551.63 commands/s, journal p95 13.85 ms and concurrent income-statement p95 2.14 ms.

## Kenya inputs

- The current iTax VAT3 workbook from KRA, which holds the upload column layout.
- eTIMS sandbox access with a real PIN and device serial, then KRA certification of the OSCU integration.
- KRA's VSCU specification, if the VSCU variant is needed.

## Repository and publication

- Create the GitHub repository for `norialabs/ledger` and push `main` and `dev`.
- Configure it like the other four packages: `main` requires a PR and the `verify` check, enforced for admins, merge commits only, `dev` never deleted.
- Register the package on Packagist and confirm the first tag through `repo.packagist.org/p2/norialabs/ledger.json`.
