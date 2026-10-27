# Accounting design and delivery scope

This package is a shared accounting engine for embedded ERPs and an accounting-practice SaaS. Schools, hospitals, utilities, and independent accountants are reference consumers. The implementation does not claim that a generic ledger automatically supplies every industry's accounting rules.

## Ownership

The financial boundary is tenant → legal entity → book → journal → posting. A book represents an accounting treatment and functional currency. Branches, projects, departments, campuses, and funds are dimensions rather than separate legal entities unless their legal/accounting requirements warrant separate entities.

A consulting firm accesses a client's tenant through host-managed delegations. It does not own all client books in a shared accounting tenant. The firm can also have its own tenant for its own accounts. A client can use several firms or change firms while keeping stable tenant/entity/book IDs and history. The actor and acting firm are recorded separately.

The engine owns financial postings, their source snapshots, open items, allocation and revaluation history, recognition schedules, stock valuation, asset financial registers, bank evidence, and derived financial reports. Host systems own students, guardians, patients, claims, meters, service plans, warehouse operations, clinical records, users, engagements, subscriptions, and approval artifacts. References connect these domains without putting their records inside ledger tables.

## Implemented invariants

1. A journal's functional debits equal its functional credits exactly. Positive source amounts are retained even when quantization produces zero functional units.
2. Posting rows, open-item identities, audit records, and batch memberships derive from their parent events. They cannot be independently changed or deleted through ordinary DML.
3. Source references and retry keys have separate uniqueness guarantees. A retry cannot change content.
4. A journal and every derived record commit atomically. Failed work does not consume a book sequence.
5. Accounts, parties, dimensions, journals, and allocations cannot cross their tenant/book/entity boundaries.
6. Period closing and posting contend on the same book lock. Each new posting must have a covering open period.
7. Posted history changes through compensating events. Reversal journals exactly invert the original postings, including currencies, rates, party, and dimensions.
8. Allocation cannot exceed either remaining original amount or incorrectly consume its functional carrying value. Releases restore their exact allocation and linked FX treatment.
9. An original journal with active allocations or bank matches cannot be reversed until those links are released.
10. A control-account valuation must be linked to a corresponding settlement or revaluation before commit. A closing journal must belong to a fiscal closure. Registered inventory, asset, and monetary valuations require their corresponding events. Checks run after journal projections and cannot be bypassed by switching constraints to immediate mode or wrapping commands in an outer transaction.
11. Bank matching changes reconciliation evidence, not cash balances. Statements must independently reconcile to their closing totals.
12. Report numerical results are bounded by both accounting date and committed book sequence. Backdating cannot alter a saved numerical snapshot.
13. Application policies can add restrictions but cannot relax these storage checks.
14. Inventory issues cannot overdraw quantity or cost. FIFO layer allocations and value adjustments conserve every minor unit; reversals restore the original layers in dependency order.
15. Asset plans conserve remaining depreciable value. Events use the active plan, preserve residual value, and cannot silently rewrite earlier history. Disposal recognizes due and convention-based partial-period depreciation before calculating gain/loss.
16. Group reports retain explicit source cutoffs, mappings, rates/historical equity amounts, elimination cutoffs, policy, and result bytes. Source permissions are rechecked when reading a saved report.
17. Stock returns cannot exceed the original issue's remaining quantity or original cost. Cross-pool returns preserve original counter-account dimensions. Transfer movements conserve both quantity and cost, commit together, and can only be corrected together.
18. Foreign monetary eligibility is explicit. Revaluations retain original currency balances, handle signed/zero-source balances, and classify cash adjustments as exchange effects. Subsequent postings cannot silently backdate before a recorded valuation in the same dimension bucket.

The tests exercise these through both public commands and direct SQL under a role subject to RLS. Concurrent posting, allocation, stock issue/return/transfer, depreciation, and monetary-valuation tests use separate processes. This establishes the tested behaviors, not an external accounting audit or a throughput certification.

## Module map

| Area | Implemented | Remaining work |
|---|---|---|
| General ledger | Books, chart, exact postings, reversals, dimensions, source history, batches | Reviewed chart templates, richer journal preparation/review UX in the host |
| Receivables/payables | Obligations, split debtors, installments, payments, partial allocations, releases, aging | Dunning, contractual deduction/dispute workflows, expected-credit-loss policy modules |
| Currency | Explicit rates, source/base amounts, realized settlement differences, foreign open-item revaluation and clearing, registered non-control monetary valuation, group translation/rounding reserves | Additional translation methods, transaction-level non-control realized-FX automation, controlled historical reposting, rate-provider adapters |
| Reporting | GL, trial balance, income statement, balance sheet, classification-aware cash flow, snapshot exports, saved group reports | Full statement layouts/disclosures, cash-basis income reporting, group ownership/NCI policies |
| Closing | Earnings transfer by dimension, retained earnings, contiguous-period validation, locks, audited reopening | Reviewed restatement workflows and automated closing checklists |
| Banking | Statements, deduplication, partial matches, releases, void/replacement, reconciliation | Provider-specific import adapters, automatic matching policies, cashbook review UI |
| Recognition/assets | Recognition schedules; asset register, opening balances, explicit depreciation plans/conventions, estimate changes, impairment losses, disposal, exact corrections | Eligibility policy, asset additions/components, transfers, OCI revaluation, recoverable-amount impairment reversals, method-specific calculators and disclosures |
| Inventory | Immutable perpetual FIFO/moving average, fractional quantities, remaining-stock landed-cost/write-down adjustments, original-cost partial customer returns, atomic warehouse transfers/reversals, exact layer reversals, snapshots | Supplier returns, batch/serial/expiry operations, manufacturing/WIP, controlled historical reposting, broader landed-cost allocation policies |
| Tax/localization | Entity country, exact effective-dated tax calculation and policy evidence | Reviewed Kenyan VAT/withholding/other treatments, statutory exports, eTIMS delivery lifecycle, additional country packs |
| Intercompany | Atomic posting/reversal across books; consistent multi-book snapshots; group account mappings, approved FX translation, explicit elimination-book journals and immutable reports | Automatic due-to/from matching, ownership graph, NCI, acquisition/goodwill, investment elimination, unrealized profit/deferred tax policies, foreign disposal/CTA recycling |
| Practice SaaS | Scoped access contract, book-aware permissions, actor/firm provenance, policy hooks | Firm/client membership, engagement administration, independent-review workflow, client handover/export UI |
| Specialized finance | Exact postings and explicit extension points | Loan, investment, insurance, custody, scarcity/holds, and sector-specific regulatory accounting |

These remaining capabilities need implemented domain rules and tests. Their table presence or interface compatibility must never be presented as evidence that they already exist. [Valuation contracts](subledgers.md) specify the narrower policies implemented by the current modules.

## Kenya and future countries

Kenya is the first review jurisdiction. A country pack should version account/report mappings, tax categories, rate validity, document requirements, and statutory integrations separately from core arithmetic. Rates must not be global constants, and changing a country's current configuration must not reinterpret historical documents.

The current engine intentionally ships no statutory-rate assertions or KRA integration. Those require a reviewed tax matrix and actual implementation. An eTIMS connector must track request identity, submission/retry state, responses, credit-note/correction relationships, and reconciliation independently of ledger posting. A transport acknowledgment must not be treated as accounting settlement.

Other countries can be introduced through additional packs. Currency definitions, company countries, policy evidence, and separate books are already represented in the foundation; local compliance still needs country-specific work.

## Delivery order after this implementation

1. Review the acceptance cases with Kenyan accountants, including school fee recognition, hospital contractual adjustments, utility deposits, closing, and tax treatment.
2. Integrate one school, hospital, and utility vertical slice through the existing contracts. Import representative outstanding documents and reconcile control accounts, bank balances, and statements before adoption.
3. Extend supplier returns, historical reposting, asset additions/revaluation, and statement/disclosure policies using independent expected-result fixtures.
4. Implement Kenyan localization and its statutory adapters against reviewed requirements. Add other jurisdictions through separate versioned packs.
5. Extend group ownership/acquisition/NCI accounting and specialized-sector modules. Measure concurrent throughput, query plans, backup/restore, and operational recovery using representative production-scale data before claiming enterprise capacity.

No consumer application has been migrated, and no package has been published by this implementation. Changes remain confined to the independent local ledger repository and its parent instructions.
