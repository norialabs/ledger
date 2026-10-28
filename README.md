# Noria Ledger

An accounting engine for Laravel 13, PHP 8.3+, and PostgreSQL 18+. Independent of `norialabs/platform` and `norialabs/payments`.

Development status: the shared accounting implementation below is executable and tested. It is not a published release or a claim of universal IFRS, tax, or industry compliance. [Design and remaining modules](docs/design.md) distinguish implemented behavior from planned work. [Accounting acceptance cases](docs/accounting-review.md) are ready for accountant review.

## Implemented

- Tenant isolation, legal entities, separate accounting books, hierarchical charts, parties, dimensions, and fiscal periods.
- Exact money and exchange-rate calculations using Brick Math and Brick Money, with database verification of posted amounts.
- Balanced, atomic, append-only journals, source deduplication, retry protection, exact reversals, and actor/firm attribution.
- Sales invoices, purchase bills, credit notes, split obligations, installments, receipts, disbursements, deposits, and advances.
- Partial allocations, releases, outstanding balances, aging, and realized exchange gains/losses.
- Foreign open-item revaluation, unrealized FX reversals, and clearing of revaluations before settlement or release.
- Explicitly registered foreign monetary accounts, including bank/loan revaluation, zero-source carrying remainders, and cash-flow exchange effects.
- Immutable recognition/amortization/depreciation posting schedules, explicit policy versions, and cancellation of unposted schedule activity.
- Perpetual FIFO and moving-average inventory, exact fractional quantities, remaining-stock value adjustments, linked customer/supplier returns, explicit purchase-price variances, atomic warehouse transfers/manufacturing, allocated WIP conversion costs, and dependent movement reversals.
- Asset capitalization, opening balances, capitalized additions, depreciation, prospective estimates, signed revaluation, ceiling-limited impairment recovery, per-asset surplus/loss attribution, full/proportionate partial disposal, and exact corrections.
- Saved group reports, explicit eliminations, account mapping, currency translation, historical equity, deficit-capable NCI/profit attribution, pinned acquisition fair values and deferred tax, partial/full goodwill and measured NCI, and separate translation/rounding reserves.
- Equity-method associates with explicit significant influence, embedded goodwill/bargain acquisition, profit/OCI/dividend movements, suspended losses, support obligations, whole-investment impairment/recovery, and exact corrections.
- Fiscal closing into retained earnings, period locks, and reasoned reopening.
- Bank-statement validation, partial matching, matching releases, statement corrections, and reconciliation without duplicate cash postings.
- Atomic cross-book posting and reversal batches, including intercompany transfers.
- General ledger, trial balance, income statement, balance sheet, cash-flow classification, and control-account reconciliation.
- Reproducible numerical report snapshots, including after backdated postings and allocation changes.
- Deny-by-default authorization and optional versioned posting policies.
- Immutable configuration packs with chart installation, account roles, effective-dated tax rules, statement definitions, and hash-verifiable evidence.
- Versioned posting treatments, scoped Laravel service replacements, and a durable ordered book event feed.
- Tax-aware sales/purchase documents and credits, explicit tax/FX rounding, exact weighted schedules, and declining-balance plans.
- Declarative comparative statements with account-group selectors, exact formulas, completeness checks, and immutable saved results.
- Exact effective-interest and lease plans, discounted scenario-weighted ECL, relative standalone-price revenue allocation, and built-in versioned finance posting treatments.
- Protected finance contracts with pinned loan/lease plans, actual loan cash/arrears, versioned fixed-rate modifications and carrying-date settlements, reviewed ECL stages, full write-offs/recoveries, cumulative revenue progress, immutable evidence and ordered corrections.
- Withholding payments/receipts, partial-recovery reverse-charge tax, a source-pinned Kenya VAT configuration factory, and configuration-pinned saved tax worksheets.
- Invoice-level tax registers, cumulative eligibility decisions, paid-import evidence, per-posting reconciliation, immutable return versions, opening-credit continuity and external filing receipts.
- Durable statutory submissions with exact payload hashes, pinned adapter/environment identities, exclusive worker leases, uncertainty-first lookup, bounded sends, accepted-invoice credit links and immutable provider outcomes.

## Installation

Use a Composer path repository while this package is unpublished. Give the path repository an explicit development version until the first release:

```json
{
    "repositories": [{
        "type": "path",
        "url": "/absolute/path/to/ledger",
        "options": {"versions": {"norialabs/ledger": "0.1.x-dev"}}
    }],
    "require": {"norialabs/ledger": "0.1.x-dev"}
}
```

Laravel discovers the service provider and migrations. Run `php artisan migrate` using the deployment role. The package uses the application's default database connection, with an empty table prefix. Each deployment must keep the connection's schema search path consistent with the migration's search path. SQLite and MySQL are not supported storage substitutes.

The package ships nine ordered fresh-install migrations. Laravel `Schema::create()` blueprints define all 54 tables, columns, primary/unique keys, ownership foreign keys, ordinary indexes and lifecycle timestamps. `Storage\PostgresSchema` shares identity, timestamp, evidence and SQL-installation conventions. Circular inventory links use Laravel's deferred foreign-key definitions.

| Order | Migration suffix | Tables | Responsibility |
|---|---|---:|---|
| 1 | `create_ledger_core` | 27 | Catalog, journals, settlements, schedules, banking, configuration, reporting evidence, audit and shared lifecycle functions |
| 2 | `create_ledger_inventory` | 5 | Stock, cost layers, returns, transfers and production |
| 3 | `create_ledger_assets` | 2 | Asset register and lifecycle events |
| 4 | `create_ledger_groups` | 3 | Associates and consolidation |
| 5 | `create_ledger_currency` | 4 | Open-item and monetary-account valuation |
| 6 | `create_ledger_finance` | 2 | Protected contracts and finance events |
| 7 | `create_ledger_tax` | 3 | Eligibility decisions and return lifecycle |
| 8 | `create_ledger_statutory` | 2 | Submissions and delivery events |
| 9 | `install_ledger_integrity` | 0 | Cross-domain journal links, settlement guards and register-account exclusivity |

Each domain migration installs its own PostgreSQL checks, RLS policies, triggers, functions and partial/expression indexes. The core additionally installs shared lifecycle functions; the final migration wires cross-domain protections after all tables exist. Ten SQL files retain these PostgreSQL-specific rules. SQL runs through Laravel's connection, including query logging and migration dry runs. All nine migrations form the required baseline; the domains share accounting dependencies.

The unpublished development upgrade chain has been consolidated into this baseline. Recreate an existing development database to adopt it; the baseline does not retrofit old installations or backfill historical records. Laravel records each completed migration and rolls back in reverse dependency order. The integrity migration removes only its cross-domain wiring and functions; domain migrations remove their own functions and tables, with shared lifecycle functions removed last. Unrelated tables/functions are retained. Subsequent schema changes belong in incremental Laravel migrations; shared PostgreSQL helpers are available to those migrations.

Bind `Noria\Ledger\Access\Authorizer` to your application's access service before using the package. Its `allows(Scope $scope, Permission $permission, ?string $bookId = null): bool` method must verify the authenticated actor, client tenant, firm delegation, requested capability, and target book. Tenant-level setup and intercompany coordination have a null book target; operations on individual books also check the corresponding book target. The default implementation denies everything.

The application creates a scope from trusted authentication state:

```php
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Ledger;

$accounting = app(Ledger::class)->for(new Scope($clientTenantId, $actorId, $firmId));
```

A firm manages delegations to client tenants. A firm's own accounts remain in its own tenant. Changing accountants changes access grants; it does not require moving client books. Membership, engagement management, client portals, approval records, and handover interfaces belong to the host SaaS. Access is evaluated on every operation.

## First book

```php
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Money\Currency;

$kes = Currency::iso('KES');
$entity = $accounting->catalog->entity('Example School', 'KE');
$book = $accounting->catalog->book($entity, 'Statutory', $kes);
$period = $accounting->periods->open($book, '2026', '2026-01-01', '2026-12-31');
$bank = $accounting->catalog->account($book, '1000', 'Bank', AccountType::Asset, cashFlow: 'cash');
$receivables = $accounting->catalog->account($book, '1100', 'Receivables', AccountType::Asset, control: true, cashFlow: 'operating');
$tuition = $accounting->catalog->account($book, '4000', 'Tuition', AccountType::Income, cashFlow: 'operating');
$guardian = $accounting->catalog->party($entity, 'guardian-42', 'Guardian');
```

Each account belongs to one book. Group accounts cannot receive postings. Income, expense, and equity accounts use the book's functional currency. Asset/liability accounts can use a registered foreign currency. Control accounts require a party and generate open items automatically.

Account codes, types, normal sides, currency, hierarchy, control status, and cash-flow classifications are immutable. Names and active status can change with audit history. Required dimensions apply to new business postings; reversals and fiscal closing preserve the historical dimensions they correct or close. Dimension filters do not automatically create separately balanced segment books.

## Versioned accounting configuration

Country, industry, and product policies can ship as ordinary PHP packages containing `Configuration\Pack` definitions. Install a particular version explicitly:

```php
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Tax\TaxRule;

$configuration = $accounting->configurations->install($book, new Pack(
    'example.accounting', '2026.1',
    accounts: [
        ['code' => '2200', 'name' => 'Output tax', 'type' => 'liability'],
        ['code' => '5999', 'name' => 'Explicit rounding', 'type' => 'expense'],
    ],
    roles: ['bank' => '1000', 'receivable' => '1100', 'revenue' => '4000', 'tax.output' => '2200', 'rounding' => '5999'],
    taxRules: [new TaxRule('EXAMPLE', '0.125', '2026-01-01', null, 'example-policy:v1')],
    country: 'KE', validFrom: '2026-01-01',
));
$bank = $accounting->configurations->account($book, $configuration, 'bank');
$evidence = $accounting->configurations->get($book, $configuration);
```

`EXAMPLE` is an illustrative input, not a statutory rate or eligibility decision. A pack can also contain `statements` and exact JSON `settings`. Account definitions support parent codes, group/control status, normal side, currency code, cash-flow mapping, and required dimension names. Parents are resolved in dependency order. Existing account classifications must match; existing labels and active status are preserved. Unknown parents, cycles, incompatible account roles, and jurisdiction mismatches roll back the entire installation.

The same book/name/version with identical content returns the original configuration. Changed content needs a new version. Role mappings and JSONB definitions are append-only and database hash-checked. `definition_hash` covers the PostgreSQL-normalized JSON representation returned as `definition_json`; `content_hash` covers the package's canonical content representation and is the stable configuration reference recorded on treatment/tax journals. Installing another version does not replace earlier mappings. Consumers pin configuration IDs; there is no implicit latest-version selection. Pack validity applies when a command selects that pack. Account restrictions and authorization still apply to new work under any version.

## Invoice and receipt

```php
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Documents\PaymentRequest;
use Noria\Ledger\Money\Money;

$invoice = $accounting->documents->issue(new DocumentRequest(
    $book, 'submit-fees-42', '2026-01-01', new Source('school.fees', 'FEES-42'),
    DocumentKind::SalesInvoice, $receivables,
    [new Obligation($guardian, Money::of('1200.00', $kes), '2026-01-31')],
    [new Charge($tuition, Money::of('1200.00', $kes), 'Term tuition')],
    beneficiaryReference: 'student-42',
));

$receipt = $accounting->documents->payment(new PaymentRequest(
    $book, 'record-receipt-42', '2026-01-15', new Source('payment.receipt', 'RCPT-42'),
    $bank, $receivables, $guardian, Money::of('500.00', $kes),
    payerReference: 'payer-reference-42',
));
```

Obligations identify debtors and due dates. A document can split the total among multiple debtors and installments. The beneficiary reference is separate. A receipt's payer reference is separate again. Store clinical, student, and operational records in the host application, and link them through opaque references.

`PurchaseBill`, `SalesCredit`, and `PurchaseCredit` use the corresponding control directions. Refunds and supplier disbursements use `receipt: false` with the appropriate control account. An unallocated credit on receivables is a credit balance; refundable deposits and other advances can instead use a liability control account. Applying money across different control accounts requires an explicit balanced reclassification journal and allocations within each account.

`Source` is a permanent business deduplication key within a book. The request key is a separate retry key. An identical retry returns the original record, including after the period closes. Reusing a request key with different content fails. Reusing a source under a different request key also fails; corrections use new source references and traceable correction entries.

The document helper rejects differences caused by independently rounded foreign obligations and charges. Use an explicit `JournalRequest` with an identified rounding posting for those cases. It never silently absorbs a difference into revenue or a receivable.

## Allocation and correction

```php
use Noria\Ledger\Accounting\AllocationRequest;

$snapshot = $accounting->reports->snapshot($book, '2026-01-31');
$items = $accounting->reports->openItems($snapshot, $guardian);
$debit = collect($items)->first(fn ($item) => $item->journalId === $invoice->id);
$credit = collect($items)->first(fn ($item) => $item->journalId === $receipt->id);

$allocation = $accounting->settlements->allocate(new AllocationRequest(
    $book, 'allocate-receipt-42', $debit->id, $credit->id,
    Money::of('500.00', $kes), '2026-01-15', 'Allocate tuition receipt',
));
```

Allocation requires the same book, control account, party, currency, and dimensions, and opposite posting sides. Original and functional amounts cannot be over-allocated. A subsequent allocation or release cannot be dated before an existing allocation event for either item. This preserves valid outstanding balances at historical dates.

Functional carrying values are allocated proportionally from the remaining amount, using half-even rounding, with the last allocation consuming the exact remainder. A release restores the precise original allocation. This also works when earlier allocations rounded to zero functional minor units.

Foreign settlement accepts explicit gain/loss account IDs and records a balanced realized-FX journal. That operation requires posting permission as well as allocation permission. Releasing it reverses the FX journal. Active unrealized adjustments on either item are first reversed atomically, requiring revaluation and reversal permissions. Revalue the remaining unpaid amount at the next reporting date. Untracked control-account valuations are rejected at transaction commit.

Release active allocations and bank matches before reversing their source journal. `journals->reverse()` preserves the original, posts the exact opposite, and automatically clears the original/reversal open items. Reversals cannot precede the original accounting date. A reversal cannot itself be reversed; record a new corrective event if necessary.

### Write-offs and credit-loss allowances

```php
$writeOff = $accounting->settlements->writeOff($book, 'write-off-inv-42', $item->id, Money::of('300', $kes), $badDebts, '2026-06-30', 'Uncollectable after legal advice');
$accounting->settlements->reinstate($book, $writeOff, 'reinstate-inv-42', '2026-08-01', 'Debtor resumed payment');

$accounting->settlements->provide($book, 'ecl-2026-06', $receivables, $allowance, $creditLosses, '2026-06-30', [
    'current' => '0.01', '1-30' => '0.03', '31-60' => '0.10', '61-90' => '0.25', '91+' => '0.60',
], 'Simplified-approach provision matrix v3');
```

`writeOff()` settles all or part of an open item: it posts the opposite control-account line for the same party, currency and dimensions against the chosen expense, income or allowance account, then allocates it to the item. Foreign items are written off at the item's original rate; any difference from the remaining carrying value goes through the supplied FX gain/loss accounts like any other settlement. Debit items are written off; credit items (unapplied receipts, supplier credits) are written back. `reinstate()` releases the allocation and reverses the journal on a later date. Cash later recovered on a written-off debt is an ordinary receipt to a recovery account.

`provide()` measures a receivable allowance from the aging of debit open items on one control account at a date, multiplies each bucket's functional exposure by its rate, rounds the total half-even, and posts only the difference from the allowance account's balance against the credit-loss expense account. The allowance account is a functional-currency asset account with a credit normal side. PostgreSQL recomputes the requirement from the stored matrix and exposure, checks the previous balance and the journal, and rejects journals that claim a `write_off` or `allowance` source without their recorded event. Choosing matrix rates, forward-looking adjustments and individually assessed balances remain accounting policy.

Validation can raise `LedgerException`, Brick arithmetic/precision exceptions, or database exceptions for storage constraints and deferred commit checks. Host HTTP adapters should translate them into their own error contract. Every failed command rolls back its journal, projections, audit records, and book sequence together.

## Reading and maintaining the catalog

```php
$accounts = $accounting->catalog->accounts($book);
$bank = $accounting->catalog->accountByCode($book, '1000');
$guardians = $accounting->catalog->parties($entity, search: 'Wanjiru', limit: 50);
$periods = $accounting->periods->list($book);
$journals = $accounting->journals->search($book, from: '2026-01-01', partyId: $guardian, limit: 100);
$lines = $accounting->journals->lines($book, $journals[0]->id);
$statement = $accounting->reports->partyStatement($snapshot, $receivables, $guardian, '2026-01-01');
```

Every ledger table forces PostgreSQL row-level security on the session's tenant, and most tables reject ordinary updates and deletes. Read through these methods rather than Eloquent models or direct queries: outside a ledger command the tenant setting is absent and every table reads as empty. Results are immutable value objects (`Entity`, `Book`, `Account`, `Party`, `Period`, `Journal`, `JournalLine`, `PartyStatement`).

Lists exclude soft-deleted records unless `includeDeleted` is set. `books()` omits books the authorizer does not let the actor read. `parties()` pages by `afterReference`; `journals->search()` pages by `afterSequence` in either direction and filters by date range, account, party, source type, description/source text and reversal state. `findBySource()` resolves a business source and `reversalOf()` finds the journal that reversed another. A party statement covers one control account and party in that account's transaction currency, with opening, running and closing balances plus functional balances, bounded by the snapshot's knowledge cutoff.

Entities, books, accounts and parties can be renamed; `moveAccount()` re-parents an account beneath another group of the same type or to the top level, and the database rejects cycles. Codes, types, normal sides, currencies, control/group status and cash-flow classes stay immutable; create a new account and retire the old one when classification must change. Every change is audited.

## Reports and exports

```php
$snapshot = $accounting->reports->snapshot($book, '2026-01-31');
$trialBalance = $accounting->reports->trialBalance($snapshot, '2026-01-01');
$profit = $accounting->reports->incomeStatement($snapshot, '2026-01-01');
$position = $accounting->reports->balanceSheet($snapshot);
$aging = $accounting->reports->aging($snapshot, $guardian);
$difference = $accounting->reports->controlReconciliation($snapshot, $receivables);
```

Persist the snapshot's book ID, effective date, and through-sequence together. All are JSON serializable; sequences and monetary amounts are strings. A fresh snapshot includes subsequent backdated entries; the saved snapshot retains its numerical results. Catalog labels are current labels, so archive rendered reports when historical naming also matters.

General-ledger exports use `generalLedger($snapshot, limit: 500, afterSequence: $sequence, afterLine: $line)` until no rows remain. Ordering is by recorded book sequence and posting line. `snapshots()` locks a sorted set of books to capture consistent cutoffs for a group export. Authorization is checked for every book.

Trial-balance opening and closing balances use debit-positive signs. Income-statement revenue is credit-positive. Aging is grouped by currency and has debit-positive/credit-negative amounts, including unallocated credits. Currency totals are never added together without explicit conversion. Balance-sheet `difference` and control-account reconciliation should be zero for a complete unfiltered book.

`cashFlow()` reports opening/closing cash, operating, investing, financing, exchange effects, and unclassified journals. It classifies a simple two-line cash journal using its counter-account mapping. Compound entries need explicit `metadata['cash_flow']` minor-unit strings, whose sum the database checks against the cash movement. `isFullyClassified()` must be true before presenting a complete classified statement. A cash-flow statement is not a cash-basis income statement.

### Comparative statement definitions

```php
use Noria\Ledger\Reporting\StatementColumn;
use Noria\Ledger\Reporting\StatementDefinition;

$layout = new StatementDefinition('Revenue statement', '2026.1', [
    'revenue' => ['label' => 'Revenue', 'accounts' => ['4000'], 'sign' => 'credit', 'include_closing' => false],
    'total' => ['label' => 'Total revenue', 'terms' => ['revenue' => '1']],
], coverageTypes: ['income']);
$columns = [
    new StatementColumn('current', 'January 2026', $snapshot, '2026-01-01'),
    new StatementColumn('previous', 'December 2025', $accounting->reports->snapshot($book, '2025-12-31'), '2025-12-01'),
];
$result = $accounting->reports->statement($layout, $columns);
$reportId = $accounting->reports->saveStatement($book, 'revenue-statement-2026-01', $layout, $columns);
$evidence = $accounting->reports->statementEvidence($book, $reportId);
```

Account selectors accept posting or group codes and deduplicate overlapping descendants within a row. Rows choose `opening`, `movement`, `closing`, `debits`, or `credits`, a debit/credit display sign, whether to include closing entries, and optional note text. Formula terms reference other rows with exact signed integer coefficients; forward references work and cycles fail. Columns share a book but can use different dates, cutoffs, and dimension filters. `coverageTypes` rejects omitted accounts with opening balances or period activity in the selected types. It does not certify the economic correctness of the layout or prevent intentional reuse of an account across different rows.

Definitions and results carry an explicit row order, preserved through canonical JSON, JSONB storage, and configuration round trips. Render saved results using `row_order`. Saved reports retain all inputs and immutable JSONB results. Evidence returns the PostgreSQL-normalized `result_json` string and its SHA-256 `result_hash`; re-encoding JSON can change that byte hash. Reading evidence rechecks current book access. `saveConfiguredStatement($book, $key, $configurationId, $name, $columns)` additionally pins the configuration ID and hashes, verifies the exact configured layout, and checks its coverage dates. Reviewed statutory submission layouts, automated disclosures, ratios, budgets, alternative data sources, and PDF/Excel rendering remain extensions.

## Exchange rates

```php
use Noria\Ledger\Money\RateType;

$accounting->rates->record($usd, $kes, RateType::Spot, '2026-01-15', '129.4512', 'CBK mean');
$rate = $accounting->rates->find($usd, $kes, RateType::Spot, '2026-01-17', maxAgeDays: 3);
new Posting($usdBank, Side::Debit, Money::of('100', $usd), $rate);
```

Rates are tenant-wide, typed (`spot`, `closing`, `average`, `historical`) and dated. `find()` returns the latest rate on or before the date as an `ExchangeRate` whose source is `ledger-rate:<id>`, and fails when none exists or the latest is older than `maxAgeDays`. Recording the same rate again replays it; a different value for the same date requires `correct()` with a reason, which appends a new revision. `history()` lists current revisions or every revision.

A posting that cites a stored rate is checked by PostgreSQL: currency pair, rate and date must match the stored row, and a superseded revision cannot be used for new postings. Reversals keep the original evidence. Explicit `ExchangeRate` objects with any other source remain accepted, so hosts can still pass approved rates directly. Rate-provider adapters, inverse and cross-rate derivation are host concerns.

## Money, tax, and schedules

Amounts enter as plain decimal strings. `Money::of()` rejects excess fractional precision; `Money::minor()` accepts integer strings. Storage supports 38-digit signed minor-unit magnitudes and currency scales from 0 through 12. Arithmetic and serialization do not use floats. Aggregations also fail on overflow rather than clipping a total.

`Currency::iso()` uses Brick Money's ISO definitions. Explicit `new Currency($code, $scale)` supports custom units, while recognized ISO scales cannot be redefined. The migration freezes ISO reference scales in the database. Review currency-definition changes before upgrading dependencies against an existing database.

`ExchangeRate` records source/target currencies, the exact rate, its effective date, provenance, and explicit rounding. Rates support at most 20 integer and 18 fractional digits. PHP and PostgreSQL independently calculate functional amounts. Tiny foreign movements can retain positive original amounts while rounding to zero functional units; they remain visible in the original currency.

`TaxCalculator` supports fractional quantities, discounts, inclusive/exclusive taxes, ordered compound taxes, effective dates, and policy versions. It exposes any rounding residual for explicit posting. Tax rates are fractions, such as `0.16`, and must be approved inputs. `Tax\Kenya::vatPack()` packages KRA-published standard/zero VAT and withholding-VAT rates with explicit organization policy dates and chart roles. Eligibility, full statutory returns and an eTIMS connector remain separate work.

`documents->issueTaxed()` connects these calculations to immutable documents using a pinned configuration and explicit account roles:

```php
use Noria\Ledger\Documents\TaxedDocumentRequest;
use Noria\Ledger\Documents\TaxLine;

$taxedInvoice = $accounting->documents->issueTaxed(new TaxedDocumentRequest(
    $book, 'example-tax-document', '2026-01-01', new Source('example.tax', 'EXAMPLE-1'),
    DocumentKind::SalesInvoice, $configuration, 'receivable',
    [new Obligation($guardian, Money::of('168.75', $kes), '2026-01-31')],
    [new TaxLine('revenue', '100', '1.5', 'Illustrative taxable charge', ['EXAMPLE'])],
    ['EXAMPLE' => 'tax.output'], roundingRole: 'rounding',
));
```

Obligations must total the calculated gross exactly. Each line selects ordered tax codes from the configuration; `taxRoles` maps those codes to approved posting roles. Sales/purchase credits invert the appropriate directions. Nonzero tax rounding and independently quantized FX differences require an explicit functional income/expense rounding role. The journal retains input quantities/prices/discounts, rule versions, per-line results, configuration hash, and FX rounding. An identical retry returns the original even after period closure. Built-in `ledger.tax` version `1` treatments handle withholding and reverse charge with explicit eligibility evidence, recoverability and cash classification. Full return-box mapping and statutory delivery require reviewed jurisdiction policies.

`Schedule::equal()` and `Schedule::actualDaysByMonth()` conserve every minor unit through Brick Money's largest-remainder allocation. The latter uses inclusive actual service days and calendar-month boundaries. Persist generic recognition schedules with `schedules->create()`, execute due rows with `postDue()`, and cancel unposted work with `cancel()`. Plans are immutable, and each run has a unique journal. Asset depreciation uses the asset lifecycle below; recognition eligibility remains a host policy.

### Recurring journals and accruals

```php
use Noria\Ledger\Scheduling\Recurrence;

$rent = $accounting->schedules->recurring($book, 'office-rent', 'Office rent', [
    new Posting($rentExpense, Side::Debit, Money::of('50000', $kes)),
    new Posting($bank, Side::Credit, Money::of('50000', $kes)),
], Recurrence::monthly('2026-01-31', occurrences: 12));
$posted = $accounting->schedules->postRecurring($book, '2026-03-31');

$accrual = $accounting->schedules->accrue($book, 'jan-audit-fee', '2026-01-31', 'Accrued audit fee', [
    new Posting($auditExpense, Side::Debit, Money::of('80000', $kes)),
    new Posting($accruals, Side::Credit, Money::of('80000', $kes)),
]);
```

A recurring journal is an immutable template plus a `day`, `week`, `month` or `year` recurrence with an optional end date or occurrence count. Monthly and yearly dates keep the starting day and clamp to the month end, so a series starting on 31 January posts on the last day of each month. `postRecurring()` posts every due occurrence once, with source `recurring:<id>` and the occurrence number; it is safe to run repeatedly. Foreign-currency lines take the stored rate of the template's rate type on each occurrence date, and party lines can receive a due date a fixed number of days after the occurrence.

With `autoReverse`, each occurrence is reversed on the first day after the accounting period that contains it, when `postRecurring()` reaches that date. `accrue()` is a one-occurrence auto-reversing template posted immediately. `cancelRecurring()` stops occurrences on or after its date and requires later occurrences to be reversed first. PostgreSQL rejects runs that differ from the template, reversals on the wrong date, and journals that claim a recurring source without a recorded occurrence.


`Schedule::weighted($amount, $dates, $weights)` also accepts exact decimal-string weights, including production quantities and magnitudes beyond native integer precision. `Schedule::decliningBalance($carrying, $residual, $ratePerSlot, $dates, switchToStraightLine: true)` calculates bounded charges, optionally switches to the larger straight-line charge, and consumes the exact remaining depreciable value in the final slot. The rate is per supplied slot, not an inferred annual rate; the final balancing charge is explicit in the generated plan. All generator dates must strictly increase. Reviewed method selection, production estimates, and useful-life conventions remain inputs.

### Tax register and return lifecycle

Use `taxes->register()` for paginated invoice/treatment evidence and `decide()` to record cumulative input eligibility (`claim`, `defer`, `deny`). `preview()` computes the configured return and exposes unmapped codes, unresolved input reviews and unexplained tax-account postings. `reconcile()` classifies manual tax postings under explicitly configured purposes. `prepare()` saves immutable figures and cutoffs; `finalize()` requires complete reconciliation and current evidence. `file()` records an external receipt against the approved version, while amendments use `prepare(previousId: ...)`. [Tax lifecycle contracts](docs/subledgers.md#tax-register-and-return-lifecycle) describe partial claims, opening credits, import payment proofs and accounting boundaries.

### Durable statutory delivery

Use `statutory->enqueue(new Statutory\SubmissionRequest(...))` to pin a source, exact payload bytes, provider route, adapter version, environment and external retry identity. `dispatch($book, $submission, $adapter)` claims committed work, invokes a version-matching `Statutory\Adapter` outside database transactions, then records its outcome. Unknown acceptance goes through lookup before another send. The service supports invoice/credit-note and finalized-return evidence; concrete KRA adapters, official iTax rendering and authority verification remain to be supplied and tested. [Delivery contracts](docs/subledgers.md#durable-statutory-delivery) define the executable lifecycle and adapter semantics.

## Valuation and group reporting

`Finance\FinancialCalculator` generates effective-interest carrying-value plans, lease present values/payment rollforwards, probability-weighted discounted credit losses, and exact revenue allocations. Built-in `ledger.finance` version `1` remains available for explicit journal preparation. For protected lifecycle accounting, use `finance->open()`, `period()`, `plan()`, `modify()`, `settle()`, `measureLoss()`, `recognize()`, `writeOff()`, `recover()`, `reverse()` and `cancel()`. These require `Permission::Finance` and independently enforce plan versions, original-rate remeasurement, actual-payment allocations, stage-dependent interest, settlement closure, account ownership and correction order in PostgreSQL. [Finance contracts and boundaries](docs/subledgers.md#protected-finance-contracts) describe the implemented scope and remaining fee-bearing/floating-rate changes, billing and policy work.

Use `revaluations->revalue()` for outstanding foreign control items and `clear()` for exact reversals. No-change valuations still retain rate evidence and retry identity. Settlement clears the whole item's active unrealized adjustments before allocating historical carrying amounts and posting realized FX. Partial settlement leaves the unpaid amount at its original remaining carrying value until the next explicit revaluation.

Use `monetaryAccounts->register()` to explicitly declare eligible foreign non-control asset/liability accounts. `revalue()` preserves the signed foreign balance while adjusting functional carrying value, including zero-source remainders. Cash adjustments are classified as exchange effects. `reverse()` corrects the latest active valuation for the same dimension bucket. Recorded valuations prevent later postings from silently backdating before that bucket's valuation history.

`inventory` owns stock valuation pools and immutable receipt/issue/value-adjustment history. FIFO consumes remaining cost layers; moving average divides total remaining cost by quantity without storing a rounded unit price. The final issue consumes the exact remaining cost. Negative quantities, negative stock values, and movements before an existing pool event are rejected. Correct existing movements in reverse dependency order.

`returnIssue()` receives against an original issue's remaining quantity and cost, rather than today's valuation. Returns can enter another compatible pool while restoring the original expense dimensions. `transfer()` links both sides of a same-SKU warehouse movement at exact source cost through a non-cash balance-sheet clearing account. `reverseTransfer()` corrects both movements atomically; individual transfer movements cannot be reversed independently. `produce()` consumes multiple component pools into a finished-good pool at exact component cost plus an explicit `conversionCost` allocation from recognized WIP; `reverseProduction()` restores every original leg and returns conversion costs to WIP. Zero-cost output retains its physical quantities.

`returnReceipt()` links a supplier return to an original purchase receipt. It removes the remaining original FIFO layer or current moving-average carrying cost, credits the original receipt-price amount through an explicitly selected clearing account, and posts the difference to an explicit income/expense variance account. It preserves original-price cents through partial returns without silently repricing remaining stock. A supplier credit document remains a separate payable/tax event.

`assets` owns capitalization and its financial lifecycle, including imported opening depreciation/impairment. Depreciation follows explicit versioned plans. Estimate changes, impairment, and disposal first recognize due depreciation and apply the asset's selected partial-period convention: actual days, completed periods, or the full current period. Plans must conserve carrying value after residual and impairment. Corrections restore the original financial effects and prior plan.

`assets->addCost()` capitalizes approved improvements with a replacement residual/plan. `disposePart()` removes an explicit proportion of current gross cost, accumulated depreciation, and impairment and recognizes the part's gain/loss. `revalue()` accepts signed fair-value changes, allocating losses against per-asset surplus before expense and recovering prior profit-or-loss charges before creating surplus. `recover()` caps impairment reversals at explicit recoverable-value and depreciated no-loss carrying inputs. Disposal can transfer released surplus directly to retained earnings. Financial effects, attribution budgets and plans reverse exactly; cash effects are classified as investing.

`consolidation` saves the combined accounts of explicitly selected entities plus an elimination book. It requires compatible mappings, translation inputs and separate translation/rounding equity accounts. NCI requires distinct NCI/retained-earnings accounts and includes deficit balances and period profit/loss attribution. Investment elimination pins acquisition snapshots and consideration. `Consolidation\FairValue` supplies incremental acquisition fair values, tax bases, deferred tax and subsequent movements. Supplying measured acquisition NCI elects full goodwill; otherwise NCI is proportionate. Goodwill survives subsequent earnings and source-year closing. Historical equity accepts exact translated balances or explicit rates. `get()` and `evidence()` recheck every source book's current access.

`associates` posts the equity method in the investor's own book, with explicit significant-influence evidence and `Permission::Investments`. `acquire()` records cost and reviewed fair-value net assets; `profit()`, `oci()` and `dividend()` apply exact ownership shares. Losses stop at the investment balance unless `obligate()` records a reviewed loss-obligation limit. `impair()`/`recover()` measure the whole investment including embedded goodwill. Suspended losses, support liabilities, source evidence, dimensions, snapshots and exact corrections are independently checked in PostgreSQL.

[Valuation and consolidation contracts](docs/subledgers.md) provide examples, accounting conventions, and boundaries. Appraisals, changing control, goodwill impairment in full consolidation, ranked long-term net-investment interests, component-specific asset identities/transfers, CGU allocations, and statutory localization remain separate capabilities.

## Controls and customization

Use `Authorizer` for authenticated permissions and book access. Use `PostingPolicy` for additional request-level checks such as independent approval, account restrictions, or source validation. Tag implementations with `PostingPolicy::class` in Laravel's container. Policy versions are stored on successful journals. Policies can reject requests but cannot disable the database's invariants. They execute inside retriable transactions and must not send notifications or perform external side effects.

Use `Accounting\PostingTreatment` to generate product-specific journals. Implement `name()`, `version()`, and `prepare(Scope $scope, TreatmentRequest $request, array $accounts): JournalRequest`, and tag the implementation with `PostingTreatment::class`. Call `treatments->post(new TreatmentRequest($book, $key, $date, $source, $name, $version, $configuration, $inputs))`. The engine resolves pinned account roles, retains exact inputs and configuration evidence, checks the returned book/key/date/source, and applies the ordinary posting policies and database invariants. Treatments cannot impersonate a reversal or closing event. Duplicate name/version registrations fail. Identical retries return the original without rerunning the generator, including when that implementation is no longer registered. Preparation is transaction-local and must be deterministic and side-effect-free.

Scoped services such as `Documents`, `Reports`, and `Assets` can be extended with readonly subclasses bound through Laravel's container, for example `$app->bind(Documents::class, SchoolDocuments::class)`. Session construction supplies its own `db`, `journals`, and other scoped dependencies to each resolution. Additional constructor dependencies can be injected normally. This avoids a process-global tenant binding in long-lived workers. Standalone consumers can pass a container to `Ledger` and pass treatments explicitly through its `postingTreatments` argument. Database-owned valuation algorithms and integrity rules require reviewed schema changes; service replacement does not redefine them.

### Durable integration feed

`events->cutoff($book)` captures a committed-prefix boundary under the book lock. Read `events->page($book, afterSequence: $cursor, limit: 500, throughSequence: $cutoff)` and advance the cursor using each returned `sequence`. The feed is derived atomically from audit history, ordered separately within each book, and protected against direct insertion, editing, deletion, and truncation. Its ordering index references existing audit payloads rather than duplicating financial JSON. Failed work and identical retries add no events.

Events expose the source table/operation, before/after JSON, actor, firm, audit ID, and recorded time. Their sequence is distinct from financial snapshot sequences and global audit IDs. Book-scoped catalog changes and financial/evidence events are included; tenant-level entities, currencies, parties, and batch headers remain in audit history. Consumers persist a checkpoint per book, deduplicate by book/event sequence, and commit their own processing/checkpoint atomically. Delivery is replayable; provider calls, worker scheduling, delivery acknowledgments, and exactly-once external effects belong to the host.

Reads on a connection inside an enclosing transaction also see that transaction's own pending events. External delivery must follow the outermost commit.

Book mutations serialize on the book row. Cross-book batches lock books in UUID order and commit all member journals or none. This is an explicit correctness baseline, not a measured enterprise-throughput claim. Publish application notifications after the outermost transaction commits. Financial commands perform no provider calls. Statutory workers invoke host adapters only after their durable claim commits; worker scheduling and webhook authentication remain host responsibilities.

PostgreSQL enforces tenant RLS, composite ownership constraints, exact balancing, source/key uniqueness, open periods, generated projections, append-only history, and settlement limits. Use a separate deployment owner and a runtime role with no superuser, bypass-RLS, table ownership, schema creation, or trigger-management privileges. Grant only the DML needed on ledger tables and SELECT on the currency-reference table. RLS and triggers do not protect against a database administrator changing the schema or disabling controls.

### Storage conventions

Every package table starts with `id`, and `id` is its sole primary key, including relationship/link tables. There are no composite-primary-key exceptions. The audit table uses an ordered `bigint GENERATED ALWAYS AS IDENTITY`; the other 53 tables use UUID identities with PostgreSQL `uuidv7()` defaults. PHP-generated identities use Laravel's `Str::uuid7()`. Explicit v4/imported UUIDs remain accepted. Tenant/book ownership tuples, natural codes, journal line numbers, relationship pairs and feed sequences remain unique alternate keys; composite foreign keys continue to enforce ownership. Financial ordering uses book sequences and accounting dates, not UUID order.

Every ledger table has database-generated `created_at timestamptz`. All timestamps explicitly retain six fractional digits. Mutable entities, books, accounts, parties, dimension values, and periods also have database-managed `updated_at`. Book sequence-only updates preserve the business modification timestamp. Immutable financial/evidence rows retain `recorded_at` alongside creation time; they do not acquire a misleading mutable timestamp. Creation timestamps cannot be changed.

Entities, books, accounts, parties, and dimension values have nullable `deleted_at timestamptz`. Use `catalog->deleteEntity()/restoreEntity()`, `deleteBook()/restoreBook()`, `deleteAccount()/restoreAccount()`, `deleteParty()/restoreParty()`, and `deleteDimensionValue()/restoreDimensionValue()`. Account methods take book/account IDs; party methods take entity/party IDs; dimension methods take entity/dimension/value. These require configuration permission and record audit history. Delete/restore retries are idempotent. Entities require their live books to be deleted first, and group accounts require live children to be deleted first. Restore parents before children.

Soft deletion retires catalog identities without hiding their financial history. Codes/references remain reserved. Ordinary new postings and valuation activity reject retired references, including zero-value stock movements; exact reversals can retain their historical accounts, parties, and dimensions. Retired books remain readable and allow traceable reversals/closing, while new ordinary activity requires restoration. Periods use closing/reopening; journals, stock/asset events, schedules, bank evidence, reports, and audit history retain their specific correction/cancellation controls rather than soft deletion. Financial queries deliberately include retired catalog references instead of applying a global soft-delete filter.

All structured JSON columns, including configuration definitions and saved statement results, use `jsonb`. Evidence hashes cover the normalized PostgreSQL JSON representation; canonical configuration content hashes remain stable across storage normalization. Every varchar limit is a power of two, including 8/16/32/64/128/256/1024. Fixed-width country codes and hashes remain `char(2)` and `char(64)`. PHP limits match the widened name/description/currency-code bounds. These lengths are a package convention, not a PostgreSQL storage optimization.

Laravel blueprints declare identities, primary/alternate keys, JSONB, string bounds and lifecycle columns directly. Unconstrained PostgreSQL `numeric` columns use Laravel's `rawColumn()` where database checks must reject excess precision instead of silently rounding it. Shared lifecycle helpers attach metadata triggers only to each migration's declared tables. Each migration runs transactionally, with no legacy conversions, history backfills or trigger/RLS disabling; the complete install spans nine migration transactions. Rollback supports clean recreation in a disposable environment.

## Development

Tests require a disposable database named `noria_ledger_test`, accessed through the non-superuser `noria_ledger_test` role. `tests/setup-postgres.sql` creates them using an administrative connection. Test migrations recreate that database's schema. Local defaults use the `/tmp` PostgreSQL socket; set `LEDGER_TEST_HOST` and `LEDGER_TEST_PORT` for TCP.

```sh
psql -d postgres -f tests/setup-postgres.sql
composer install
./vendor/bin/pint
./vendor/bin/phpstan analyse --no-progress
./vendor/bin/pest
```

Integration tests fail if PostgreSQL is missing; they do not silently skip. Concurrent tests use independent PHP processes and connections and observe both processes waiting on the same book lock before releasing them.

## References

Design references, rather than copied ERP implementations:

- [ERPNext immutable ledger](https://docs.frappe.io/erpnext/immutable-ledger-in-erpnext): retained originals and compensating corrections.
- [ERPNext payment ledger](https://docs.frappe.io/erpnext/payment_ledger): allocation/outstanding history separate from the general ledger.
- [ERPNext finance books](https://docs.frappe.io/erpnext/finance-book) and [dimensions](https://docs.frappe.io/erpnext/accounting-dimensions): accounting treatments separate from reporting segments.
- [ERPNext exchange revaluation](https://docs.frappe.io/erpnext/exchange-rate-revaluation), [FIFO/moving average](https://docs.frappe.io/erpnext/calculation-of-valuation-rate-in-fifo-and-moving-average), and [asset depreciation](https://docs.frappe.io/erpnext/asset-depreciation): reference workflows for the dedicated valuation modules.
- [ERPNext stock entries](https://docs.frappe.io/erpnext/stock-entry) and [linked return valuation](https://docs.frappe.io/erpnext/sales-returns-and-credit-notes-problems): reference behavior for transfers and returns linked to original cost.
- [ERPNext purchase returns](https://docs.frappe.io/erpnext/purchase-return): physical returns, original receipt references, and separate supplier-credit accounting. This package explicitly separates original-price credits from current carrying cost through variance postings.
- [Frappe extension hooks](https://docs.frappe.io/framework/user/en/python-api/hooks): composable host extensions; implemented here through scoped Laravel bindings and versioned treatment/policy registrations.
- [ERPNext financial statements](https://github.com/frappe/erpnext/blob/develop/erpnext/accounts/report/financial_statements.py) and [OCA MIS Builder](https://github.com/OCA/mis-builder/tree/18.0): reusable templates, period/dimension columns, account hierarchy rollups, and formulas. Our implementation uses exact integer arithmetic and an explicit restricted formula graph.
- [Formance conventions](https://docs.formance.com/reference/api/conventions): separate business references, retry protection, effective time, and recorded time.
- [Brick Money](https://github.com/brick/money) and [Brick Math](https://github.com/brick/math): reused exact arithmetic, ISO definitions, and allocation algorithms.
- [PostgreSQL trigger semantics](https://www.postgresql.org/docs/18/sql-createtrigger.html): storage checks, derived records, and deferred integrity checks.
