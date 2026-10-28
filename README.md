# Noria Ledger

An accounting engine for Laravel 13, PHP 8.3+, and PostgreSQL 18+. Independent of `norialabs/platform` and `norialabs/payments`.

Status: executable and tested, not yet published. It does not claim universal IFRS, tax or industry compliance.

| Document | Contents |
|---|---|
| This README | Installation and usage of every module |
| [docs/design.md](docs/design.md) | Ownership model, enforced invariants, schema layout, extension points |
| [docs/subledgers.md](docs/subledgers.md) | Accounting conventions of the valuation, group, finance and tax modules |
| [docs/accounting-review.md](docs/accounting-review.md) | Worked cases awaiting independent accountant review |
| [docs/pending-code.md](docs/pending-code.md) | Engine work still to build, in build order |
| [docs/pending-non-code.md](docs/pending-non-code.md) | Reviews, decisions, external inputs and scale evidence that code cannot close |

## Capabilities

- **General ledger:** tenants, legal entities, books, hierarchical charts, parties, dimensions and periods; balanced append-only journals with exact reversals, retry keys, batches across books, recurring journals, accruals and maker-checker drafts.
- **Receivables and payables:** invoices, bills, credit notes, split obligations, instalments, receipts, partial allocations, aging, party statements, write-offs and provision-matrix allowances.
- **Currency:** exact Brick money, stored typed rates with posting evidence, realized and unrealized FX on open items and monetary accounts.
- **Banking and imports:** statements, matching and reconciliation; preview-and-correct imports from M-Pesa, CSV, Excel, JSON, MT940 and CAMT.053.
- **Inventory:** perpetual FIFO, moving average and specific identification with lots, expiry and serials; returns, transfers, landed costs, write-downs and manufacturing.
- **Fixed assets:** capitalization, depreciation, additions, revaluation, impairment and recovery, CGU allocation, transfers, partial and full disposal, roll-forward.
- **Finance:** loans, leases and advance-funded revenue with effective interest, ECL stages, modifications, prepayments, write-offs, lease remeasurement and termination.
- **Group:** consolidation with translation, eliminations, NCI, goodwill and its impairment, intercompany matching; equity-method associates.
- **Tax:** tax-aware documents, withholding and reverse charge, tax registers, claims, immutable returns; Kenya VAT pack, VAT3 section totals and the KRA eTIMS OSCU adapter.
- **Reporting and close:** trial balance, income statement, balance sheet, cash flow, cash-basis income, declarative comparative statements, budgets, closing checklist, year-end close and restatements.
- **Integration:** read-only Eloquent models, facade, config, artisan commands, starter chart, scoped service overrides, durable event feed and statutory delivery queue.

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

The package ships nine ordered fresh-install migrations. Laravel `Schema::create()` blueprints define all 59 tables, columns, primary/unique keys, ownership foreign keys, ordinary indexes and lifecycle timestamps. `Storage\PostgresSchema` shares identity, timestamp, evidence and SQL-installation conventions. Circular inventory links use Laravel's deferred foreign-key definitions.

| Order | Migration suffix | Tables | Responsibility |
|---|---|---:|---|
| 1 | `create_ledger_core` | 37 | Catalog, exchange rates, journals and drafts, settlements, write-offs and allowances, schedules and recurring journals, closing and restatements, banking, budgets, configuration, reporting evidence, audit and shared lifecycle functions |
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

## Quick start

```bash
composer require norialabs/ledger
php artisan vendor:publish --tag=ledger-config
php artisan migrate
php artisan ledger:install --entity="Acme Ltd" --year=2026
```

`config/ledger.php` holds the package settings:

| Key | Purpose |
|---|---|
| `authorizer` | Class implementing `Access\Authorizer`; the default `DenyAccess` refuses everything. A binding made in your own service provider takes precedence. |
| `scope_resolver` | Class implementing `Access\ScopeResolver`, returning the current request's `Scope` for `Ledger::current()`. |
| `posting_policies`, `posting_treatments` | Class names added to any tagged `PostingPolicy` and `PostingTreatment` implementations. |
| `console_actor` | Actor recorded for artisan commands; your authorizer must allow it. |
| `models` | Map of model keys to your own subclasses, for example `['account' => App\Models\LedgerAccount::class]`; every relationship then returns your class. |
| `defaults.currency`, `defaults.country` | Defaults for `ledger:install`. |

```php
use Noria\Ledger\Facades\Ledger;

$accounting = Ledger::current();                       // scope from your ScopeResolver
$accounting = Ledger::for(new Scope($tenantId, $userId)); // explicit scope
```

`ledger:install` creates a legal entity, a book in the default or `--currency` currency, monthly periods for `--years` fiscal years from `--year` and `--start` (MM-DD), and installs `Configuration\StarterChart`: a grouped chart (assets, liabilities, equity, income, expenses) with cash-flow classes, receivable/payable control accounts, VAT and withholding accounts, fixed-asset and credit-loss accounts, an income statement and statement of financial position, and roles such as `cash`, `receivable`, `payable`, `revenue`, `tax.output`, `tax.input`, `retained_earnings`, `rounding`, `fx.gain` and `fx.loss`. Resolve accounts by role with `configurations->account($book, $configuration, 'receivable')`. Without `--tenant` it generates a tenant UUID and prints it; pass `--without-chart` to install your own chart. A country pack such as `Tax\Kenya::vatPack()` installs on top of the starter chart by mapping its roles to starter codes (`1300`, `2100`, `1310`, `2110`, `1320`, `2120`).

Schedule due work per tenant, for example `Schedule::command('ledger:post-due --tenant='.$tenantId)->dailyAt('01:00')`. It posts due recurring journals and their auto-reversals, schedule lines and asset depreciation for every book the console actor can read, or for `--book` values only. `ledger:checklist --tenant= --book= --from= --to=` prints the closing checklist and exits with a failure code until every failing check is resolved, so it can gate a closing job.

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

Account codes, types, normal sides, currency, control status, and cash-flow classifications are immutable. Names, parents and active status can change with audit history. Required dimensions apply to new business postings; reversals and fiscal closing preserve the historical dimensions they correct or close. Dimension filters do not automatically create separately balanced segment books.

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

### Billing runs

```php
$run = $accounting->journals->postRun($book, (function () use ($book, $accountsToBill) {
    foreach ($accountsToBill as $bill) {
        yield new JournalRequest($book, 'bill-'.$bill->id, '2026-01-31', 'January water bill', [
            new Posting($receivables, Side::Debit, $bill->amount, partyId: $bill->customer, dueOn: '2026-02-15'),
            new Posting($waterSales, Side::Credit, $bill->amount),
        ], source: new Source('billgrid.bill', $bill->id));
    }
})());
$journalId = $run->journalIds['bill-'.$firstBill->id];
```

`postRun()` posts any iterable of ordinary journals for one book in one transaction under one book lock. The run is all or nothing. If PostgreSQL rejects one document, nothing posts and the exception names that document's key. Each document keeps its own key and source. A retry replays posted documents without new sequences, so a retry that adds documents posts only the new ones. `JournalRun` returns each key's journal ID, the posted and replayed counts, and the contiguous range `(afterSequence, throughSequence]` the new journals occupy. Documents are inserted 1,000 at a time and the run reserves that chunk's sequence numbers once in `ledger_sequence_reservations`. The database accepts an explicit journal sequence only inside a reservation made by the same transaction. Reports, the event feed and corrections see a run's journals as ordinary journals. Closing journals, reversals and approved drafts are not accepted in a run.

PostgreSQL needs planner statistics on the ledger tables before the first large run. On tables that have never been analyzed, foreign-key checks inside the posting triggers can choose an index that scans the whole book, making a single large run quadratic. Run `ANALYZE` after installing and loading opening data; autovacuum keeps statistics current afterwards.

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

Every ledger table forces PostgreSQL row-level security on the current ledger tenant. Commands set it themselves. For reads you can use these methods, which also apply your authorizer per book and return immutable value objects (`Entity`, `Book`, `Account`, `Party`, `Period`, `Journal`, `JournalLine`, `PartyStatement`), or the Eloquent models below.

Lists exclude soft-deleted records unless `includeDeleted` is set. `books()` omits books the authorizer does not let the actor read. `parties()` pages by `afterReference`; `journals->search()` pages by `afterSequence` in either direction and filters by date range, account, party, source type, description/source text and reversal state. `findBySource()` resolves a business source and `reversalOf()` finds the journal that reversed another. A party statement covers one control account and party in that account's transaction currency, with opening, running and closing balances plus functional balances, bounded by the snapshot's knowledge cutoff.

Entities, books, accounts and parties can be renamed; `moveAccount()` re-parents an account beneath another group of the same type or to the top level, and the database rejects cycles. Codes, types, normal sides, currencies, control/group status and cash-flow classes stay immutable; create a new account and retire the old one when classification must change. Every change is audited.

## Closing and restatement

```php
$closure = $accounting->yearEnd->close($book, 'close-2026', '2026-01-01', '2026-12-31', $retainedEarnings, 'Approved annual close');

$accounting->yearEnd->restate($book, 'restate-2026-missed-invoice', $closure, '2027-02-01', [
    new Posting($repairs, Side::Debit, Money::of('20000', $kes)),
    new Posting($accruals, Side::Credit, Money::of('20000', $kes)),
], $retainedEarnings, 'restatement-policy:2027.1', 'Unrecorded 2026 supplier invoice');

$restated2026 = $accounting->reports->incomeStatement($snapshot2026, '2026-01-01', restated: true);
```

`checklist($book, $startsOn, $endsOn)` reports each check as `pass`, `fail` or `warning` with up to 100 item references. Failures: uncovered period days, unclosed earnings before the range, undecided drafts, due recurring occurrences or auto-reversals, due schedule lines, due depreciation, unreconciled control accounts and an unbalanced balance sheet. Warnings: foreign open items or registered monetary accounts without a valuation on the end date, unmatched bank lines, cash accounts without a statement covering the end date, and unclassified cash-flow journals. `ready` is true when nothing fails. `close(..., requireChecklist: true)` refuses to close until it is. `schedules->dueRecurring()` and `dueSchedules()` preview the same due work.

`close()` transfers income and expense by account and dimension into retained earnings and closes the year's periods. A prior-period error found after closing is corrected with `restate()`, which takes the entry as it should have been recorded in the closed year. Balance-sheet lines post on the given date in an open period; income and expense lines are not posted but attributed to the closed year, and their net goes to retained earnings by dimension. Current-year profit is unaffected. It requires `ledger.reopen` and `ledger.post`.

Pass `restated: true` to `trialBalance()`, `incomeStatement()` or `balanceSheet()` for comparatives as restated: each restatement known to the snapshot is moved to its closed year's end, and in pre-closing views its retained-earnings lines are replaced by the attributed income and expense. Without the flag, reports show the books as recorded. PostgreSQL checks that the closure is still active, the journal is dated after it, the journal contains no income or expense lines, and retained earnings equal the attribution in every dimension.

## Eloquent models

`Noria\Ledger\Models` has a model for every ledger table: `Entity`, `Book`, `Account`, `Party`, `Period`, `Journal`, `Posting`, `OpenItem`, `Allocation`, `Asset`, `AssetEvent`, `InventoryPool`, `InventoryMove`, `FinanceContract`, `TaxReturn`, `BankStatement`, `Budget` and the rest. They carry typed properties, casts (JSON, booleans, immutable dates) and relationships such as `$book->accounts`, `$account->parent`/`children`, `$journal->lines`, `$journal->reversal`, `$posting->account`/`party`, `$openItem->debitAllocations`, `$asset->events` and `$pool->moves`. `money('amount_minor')` returns an exact `Money` in the row's currency, or the book currency when the table has none.

Models read inside a ledger context. Add the `ledger.scope` middleware to routes after configuring `scope_resolver` (or binding `Access\ScopeResolver`); it sets the tenant, actor and firm on the connection for the request and clears them afterwards, so `Ledger::current()` and the models share one scope. In jobs and commands use `app(LedgerContext::class)->run($scope, fn () => ...)`. Without a context, every ledger table reads as empty. Session-level settings need a session-pooled connection: behind PgBouncer in transaction mode, wrap each unit of work in `run()` inside a transaction instead.

Models are read-only by default: saving or deleting throws and points to the ledger services, which keep multi-table commands atomic and checked. To customize, extend a model and register it in `ledger.models`; set `protected static bool $writable = true;` on your subclass to allow the column changes PostgreSQL permits (for example renaming an account or party); history, classification and derived rows stay protected by the database whatever the model allows. Row-level security isolates tenants, not books: apply your own policies when one tenant's users may only see some books, or read through the services, which ask your authorizer for every book.

## Imports

```php
use Noria\Ledger\Imports\ImportFormat;
use Noria\Ledger\Imports\ImportKind;

$preview = $accounting->imports->preview(ImportKind::BankStatement, $path, ImportFormat::Mpesa, [
    'book_id' => $book, 'account_id' => $mpesaAccount, 'reference' => 'MPESA-2026-01',
]);
// show $preview->rows, $preview->errors and $preview->summary; let the user edit rows
$checked = $accounting->imports->validate(ImportKind::BankStatement, $editedRows, [...$options, ...$preview->summary]);
$result = $accounting->imports->submit(ImportKind::BankStatement, $editedRows, [...$options, ...$preview->summary]);
```

Every import is preview, edit, validate and submit. `preview()` reads a file into normalized, editable rows with per-row errors (keyed by row index, `_` for the whole file) and a summary; nothing is written. `validate()` rechecks edited rows, and `submit()` validates again and commits all rows in one transaction or none. The service is stateless: keep the preview in your session, cache or a draft table while the user edits, and pass the summary back with the options. `ImportPreview` is JSON serializable for front ends.

| Kind | Options | Columns (case-insensitive; common aliases recognized) |
|---|---|---|
| `bank_statement` | `book_id`, `account_id`, `reference`, `opening`, `closing`, `starts_on`, `ends_on`, `date_format` | `external_id`, `value_on`, `amount` or `money_in`/`money_out`, `description`, `balance`, `counterparty`, `account_reference` |
| `parties` | `entity_id` | `reference`, `name` |
| `accounts` | `book_id` | `code`, `name`, `type`, `parent`, `group`, `control`, `normal_side`, `cash_flow`, `currency` |
| `journals` | `book_id` | `journal`, `date`, `description`, `account`, `debit`, `credit`, `currency`, `rate`, `party`, `due_on`, `dimensions` (`zone=north;scheme=a`), `line_description`, `reporting_kind`, `reporting_policy`, `reporting_reference`, `oci_category` |
| `exchange_rates` | none | `base`, `quote`, `type`, `date`, `rate`, `source` |

Formats: `csv` and `xlsx` (streamed with `spatie/simple-excel`, header row detected below any preamble, `columns` maps fields to your headers, `delimiter` and `sheet` are optional), `json` (a list of row objects, optionally under `rows`), `mpesa` (the M-Pesa business statement export as CSV or Excel: failed transactions are skipped, newest-first exports are reordered, and opening and closing balances come from the running balance), `mt940` and `camt053` (one statement per file, with its own reference and balances). The format is guessed from the file extension when omitted. Imports are limited to 100,000 rows per file, and statements to 10,000 movements.

Validation covers dates, amounts and currency precision, duplicates within the file and against earlier imports, statement balance continuity, chart parents and classification conflicts, party and account existence, control-account parties, foreign-currency rates and balanced journals. Existing identical parties, accounts and rates are reported as `existing` and skipped on submit; imported journals use the file's journal reference as their source, so a journal cannot be imported twice. `php artisan ledger:import parties customers.csv --tenant=... --option=entity_id=...` previews from the terminal; add `--commit` to submit.

### Matching and allocation helpers

`banking->suggestMatches($snapshot, $statementId)` lists ledger postings on the statement's account with exactly the line's remaining amount, in the right direction and within a date window, scored by reference text and date distance. `autoMatch()` applies only unambiguous suggestions above a minimum score and leaves ties to a reviewer. `postLine($book, $bankLineId, $counterAccount, $reason, $party, $dimensions, $cashFlow, $reportingMovement)` posts an unrecorded statement line (bank charges, interest, an unrecorded receipt) to a counter account and matches it, once per line. A counter posting to equity, such as a share subscription, takes a `ReportingMovement` so classified reports can attribute it. `settlements->allocateOldest($book, $itemId, $date, $reason)` allocates a receipt or payment to the same party's oldest open items (by due date) on the same account, currency and dimensions, leaving any excess as an open credit.

## Reports and exports

```php
$snapshot = $accounting->reports->snapshot($book, '2026-01-31');
$trialBalance = $accounting->reports->trialBalance($snapshot, '2026-01-01');
$profit = $accounting->reports->incomeStatement($snapshot, '2026-01-01');
$position = $accounting->reports->balanceSheet($snapshot);
$aging = $accounting->reports->aging($snapshot, $guardian);
$difference = $accounting->reports->controlReconciliation($snapshot, $receivables);
```

### Receivables at scale

```php
$page = $accounting->reports->openItems($snapshot, accountId: $receivables, limit: 500);
$next = $accounting->reports->openItems($snapshot, accountId: $receivables, limit: 500, after: end($page)->cursor());
$byZone = $accounting->reports->aging($snapshot, accountId: $receivables, dimensions: ['zone' => 'north']);
$debtors = $accounting->reports->partyBalances($snapshot, $receivables, limit: 500, afterPartyId: $lastPartyId);
$statement = $accounting->reports->partyStatement($snapshot, $receivables, $customer, '2026-01-01', limit: 200, after: $previous->next);
```

`openItems()` returns one page (default 1,000, at most 10,000) in effective-date, sequence and line order. `OpenItem::cursor()` continues it, and `eachOpenItem()` iterates every page for exports. `openItem()` reads one item, settled or not. `aging()` and `exposure()` (debit-side functional exposure by bucket, used by `provide()`) are computed in SQL, optionally by party, account and dimensions, and return one total per currency and bucket. `partyBalances()` pages one account's parties by party ID, omitting zero balances unless `includeZero` is set. A party statement pages its lines: `opening` and `closing` always cover the whole statement, every line carries its running balance and `cursor`, and `next` continues the statement. Open items carry their party, date and sequence, so pages are read from an index rather than by sorting the book.

Persist the snapshot's book ID, effective date, and through-sequence together. All are JSON serializable; sequences and monetary amounts are strings. A fresh snapshot includes subsequent backdated entries; the saved snapshot retains its numerical results. Catalog labels are current labels, so archive rendered reports when historical naming also matters.

General-ledger exports use `generalLedger($snapshot, limit: 500, afterSequence: $sequence, afterLine: $line)` until no rows remain. Each readonly `Reporting\LedgerEntry` contains the posting `id`, `tenantId`, `createdAt`, typed `journal` header and typed `line`. Monetary values are `line->amount` and `line->functional`; the next cursor is `journal->sequence` and `line->lineNo`. Header and posting descriptions remain distinct. Ordering is by recorded book sequence and posting line. `snapshots()` locks a sorted set of books to capture consistent cutoffs for a group export. Authorization is checked for every book.

Trial-balance opening and closing balances use debit-positive signs. Income-statement revenue is credit-positive. Aging is grouped by currency and has debit-positive/credit-negative amounts, including unallocated credits. Currency totals are never added together without explicit conversion. Balance-sheet `difference` and control-account reconciliation should be zero for a complete unfiltered book.

`cashFlow()` reports opening/closing cash, operating, investing, financing, exchange effects, and unclassified journals. It classifies a simple two-line cash journal using its counter-account mapping. Compound entries need explicit `metadata['cash_flow']` minor-unit strings, whose sum the database checks against the cash movement. `isFullyClassified()` must be true before presenting a complete classified statement. A cash-flow statement is not a cash-basis income statement; use `cashBasisIncomeStatement($snapshot, $startsOn)` for that.

`cashBasisIncomeStatement()` recognizes a journal's income and expense lines in proportion to how much of it has been settled in cash or through allocation: a cash sale or paid expense on its date, an invoice or bill as receipts, payments, credit notes or write-offs are allocated to it, and a part-paid sale partly on its date and the rest on allocation. Recognition is cumulative and rounded half-even per line, so a fully settled journal recognizes exactly its recorded amounts. Realized FX on settlement is recognized on its date; accruals, depreciation, unrealized revaluations, unsettled invoices and fiscal closing entries are excluded. It returns income, expenses, profit and debit-positive amounts by account, bounded by the snapshot's date and knowledge cutoff.

### Public result contracts

Register rows and evidence definitions/events are readonly objects with the same conventions as `Journal` and `JournalLine`: camelCase properties, `Money` for amounts, decoded arrays for stored JSON and a `fromRow()` factory. Stored report JSON is exposed as precise PHPStan array shapes. Read fields directly:

```php
$bankLine = $accounting->banking->lines($book, $statementId)[0];
$received = $bankLine->amount->decimal();
$tax = $accounting->taxes->preview($snapshot, $configurationId, 'vat', '2026-01-01');
$inputClaimedMinor = $tax['totals']['input_claimed'];
$firstEntryBase = $tax['entries'][0]->base;
$history = $accounting->finance->evidence($snapshot, $contractId, limit: 100);
$nextSequence = $history['events'][0]->sequence;
```

| Methods | Public result |
|---|---|
| `consolidation->get()`, `evidence()` | `Consolidation\ConsolidatedReport::Result` and `Inputs` array shapes: balances, amounts, NCI, goodwill, attribution, acquisition evidence and snapshots. Members replayed under a legacy aggregation identity omit `ownership_fraction`; investment fair values and impairments appear only when supplied. |
| `taxes->preview()`, `evidence()` | `Tax\TaxReturn::Result` shape: `TaxEntry` entries, claims, totals, bases, reconciliations, unresolved sources and credits. Evidence adds a readonly `TaxReturn` definition and `TaxReturnEvent` rows. |
| `taxes->register()`, `decisions()` | Lists of readonly `Tax\TaxEntry` and `Tax\TaxDecision`. Register pagination uses `sequence`, `lineNo` and `taxCode`. |
| `banking->lines()` | A list of readonly `Banking\BankLine` with a signed `amount` in the bank account's currency. |
| `finance->plan()`, `evidence()` | `Finance\Contract::Plan` shape with payment dates/amounts, plan identity, rate, next line and carrying measurement; evidence has a readonly `Contract` and `ContractEvent` rows. `rate_period_months` is null when the contract did not supply one. |
| `associates->evidence()` | A readonly `Associates\Associate` definition and paged `AssociateEvent` rows with source amounts, deltas, correction links and retained evidence. |
| `statutory->pending()`, `evidence()` | Readonly `Statutory\PendingSubmission` rows with a shaped `state`; evidence has a readonly `SubmissionDefinition`, `SubmissionEvent` rows, shaped state, and a `Statutory\Submission::Source` discriminated by `kind` (`invoice`, `credit_note` or `tax_return`). |
| `events->page()` | A list of readonly `Support\AuditEvent` with decoded `before` and `after` row images. |

Array shapes keep the snake-case keys of the stored JSON; their minor-unit amounts are strings. Host-supplied metadata and evidence remain extensible maps. Pagination, effective dates, knowledge cutoffs and authorization keep their existing semantics.

Evidence reads verify hashes against the original PostgreSQL-normalized bytes. Tax evidence exposes `result_json`/`result_hash`; consolidation adds `inputs_json` beside `result_json`/`result_hash`; statutory evidence adds `source_json` alongside the definition's `sourceHash` and original payload/hash. Hydration does not update saved JSON or recompute historical evidence. Re-encoding a shaped result is not a substitute for hashing the original string.

### Budgets

```php
use Noria\Ledger\Reporting\BudgetLine;

$budget = $accounting->budgets->create($book, 'Operating', '2026.1', [
    new BudgetLine($salaries, '2026-01-01', '2026-01-31', Money::of('1200000', $kes), ['campus' => 'east']),
    new BudgetLine($tuition, '2026-01-01', '2026-01-31', Money::of('-3500000', $kes)),
]);
$rows = $accounting->reports->budgetVariance($snapshot, $budget, '2026-01-01', includeUnbudgeted: true);
```

A budget is an immutable named version of signed, debit-positive amounts per posting account, date range and optional dimensions; revenue budgets are therefore negative. Re-creating the same version with the same lines replays it, and a change needs a new version. Lines for the same account and dimensions cannot overlap. `budgetVariance()` sums lines inside the report range, rejects lines that straddle its boundaries, and compares them with actual movement excluding fiscal closing entries, filtered by each line's dimensions. Each row returns budget, actual and `variance = actual - budget`, all debit-positive. `includeUnbudgeted` adds income and expense accounts with activity and no budget lines.

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
$currentRevenue = $result->rows['revenue']['values']['current']->decimal();
$reportId = $accounting->reports->saveStatement($book, 'revenue-statement-2026-01', $layout, $columns);
$evidence = $accounting->reports->statementEvidence($book, $reportId);
$savedRevenue = $evidence['result']->rows['revenue']['values']['current']->decimal();
```

Account selectors accept posting or group codes and deduplicate overlapping descendants within a row. Rows choose `opening`, `movement`, `closing`, `debits`, or `credits`, a debit/credit display sign, whether to include closing entries, and optional note text. Formula terms reference other rows with exact signed integer coefficients; forward references work and cycles fail. Columns share a book but can use different dates, cutoffs, and dimension filters. `coverageTypes` rejects omitted accounts with opening balances or period activity in the selected types. It does not certify the economic correctness of the layout or prevent intentional reuse of an account across different rows.

`statement()` and evidence's `result` return readonly `Reporting\Statement` objects. They expose `definition`, typed `columns`, a `Currency`, `rowOrder`, rows with `Money` values, and optional pinned `configuration`. Definitions and results carry an explicit row order, preserved through canonical JSON, JSONB storage, and configuration round trips. Render using `rowOrder` (serialized as `row_order`). Saved reports retain all inputs and immutable JSONB results. Evidence returns the original PostgreSQL-normalized `result_json` string and its SHA-256 `result_hash`; hydration validates the existing JSON contract without updating it. Re-encoding the typed result is not a replacement for the original byte hash. Reading evidence rechecks current book access. `saveConfiguredStatement($book, $key, $configurationId, $name, $columns)` additionally pins the configuration ID and hashes, verifies the exact configured layout, and checks its coverage dates.

### Classified reporting policies

```php
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Reporting\ReportingMapping;

$mapping = new ReportingMapping(
    'Classified performance', '2027.1', 'Trading; approved policy reference FIN-2027-01',
    accounts: [
        '4000' => ['profit_or_loss' => 'operating'],
        '4100' => ['profit_or_loss' => 'investing'],
        '5000' => ['profit_or_loss' => 'operating'],
        '5100' => ['profit_or_loss' => 'financing'],
        '5200' => ['profit_or_loss' => 'income_taxes'],
        '3100' => ['oci' => 'non_recyclable', 'equity_component' => 'revaluation'],
    ],
    financingSubtotalRequired: true,
    equityComponents: ['revaluation' => 'Revaluation reserve'],
);
$configuration = $accounting->configurations->install($book, new Pack(
    'reporting', '2027.1', validFrom: '2027-01-01', reportingMapping: $mapping,
));
$layout = $accounting->configurations->statement($book, $configuration, $mapping->name);
$result = $accounting->reports->statement($layout, $columns);
$profit = $result->rows['profit']['values']['current'];
$reportId = $accounting->reports->saveConfiguredStatement(
    $book, 'classified-2027', $configuration, $mapping->name, $columns,
);
```

Every selected code must identify a posting account in the book; mappings do not inherit group descendants. Profit-or-loss mappings require income/expense accounts and use `operating`, `investing`, `financing`, `income_taxes` or `discontinued_operations`. OCI mappings use `recyclable` or `non_recyclable` and are separate from profit. Non-recyclable OCI can sit in income/expense or equity accounts; recyclable OCI requires an equity reserve, because recycling releases an original reserve posting and year-end closes income/expense accounts into retained earnings. PostgreSQL rejects recyclable OCI postings and mappings on other account types. Equity-component mappings require equity accounts; an equity account classified as OCI also requires an explicit component. Unmapped active income/expense accounts fail coverage. Existing account types and cash-flow metadata are not changed.

Generated rows include the five categories, `operating_profit`, `profit_before_income_taxes`, `profit_from_continuing_operations`, `profit`, `oci_recyclable` and `oci_non_recyclable`. Category income is credit-positive and expenses reduce the total. Fiscal closing entries are excluded. `financingSubtotalRequired` is an explicit reviewed decision: `true` adds `profit_before_financing_and_income_taxes`; `false` omits it for the IFRS 18 paragraphs 73–74 exception. The host assesses the underlying main-business-activity and liability-classification policy; the engine does not infer it from an entity label.

OCI rows use immutable posting-level source attribution. A reserve can contain both OCI and direct-equity transfers: only current OCI recognition and recycling to profit or loss affect its OCI category. Direct equity transfers, basis adjustments, contributions, distributions and opening balances are excluded. The source OCI category must agree with the selected mapping; unclassified equity/OCI movements and missing active equity components fail explicitly. `incomeStatement()` retains its generic account-type calculation; use the mapped statement for classified profit and OCI exclusion. Comprehensive-income totals and a component-by-component equity reconciliation are item 3 of the pending work.

`ReportingMapping::definition()` also works with `statement()` and `saveStatement()` directly. The definition retains the complete mapping version and business-activity decision; configuration-backed saves additionally enforce the pack's effective dates and pin its ID/hashes. Revised mappings require a new configuration version. Original saved JSON and hashes remain readable, including statements and packs created before reporting mappings were introduced.

Source attribution is supplied through `Posting::reportingMovement`:

```php
use Noria\Ledger\Reporting\ReportingMovement;

$recognition = $accounting->journals->post(new JournalRequest(
    $book, 'reviewed-oci-gain', '2027-01-15', 'Reviewed OCI gain', [
        new Posting($reserve, Side::Credit, Money::of('100', $kes),
            reportingMovement: new ReportingMovement('oci', 'approved-policy:2027.1', 'valuation-review:123', 'recyclable')),
        new Posting($financialAsset, Side::Debit, Money::of('100', $kes)),
    ],
));
$recycling = $accounting->journals->recycleOci(
    $book, 'reviewed-oci-disposal', $recognition->id, 1, '2027-02-01',
    Money::of('40', $kes), $disposalIncome, 'approved-policy:2027.1', 'disposal-review:456',
);
```

The example produces profit 40 and net OCI 60 over both dates. A debit OCI loss produces the opposite signs. `policyVersion` and `reference` are required evidence, not inferred approvals. Movement kinds are:

| Kind | Meaning |
|---|---|
| `oci` | Current OCI recognition with explicit `recyclable` or `non_recyclable` category |
| `oci_opening` | Reviewed opening OCI carried in equity; establishes a source lot without current OCI income |
| `recycling` | Reclassification from an original recyclable equity posting to profit or loss |
| `basis_adjustment` | Direct removal from an original recyclable equity posting into an asset/liability basis; excluded from current OCI and profit |
| `contribution`, `distribution` | Owner transactions in equity |
| `transfer` | Conserving transfer between equity accounts in the same dimension bucket |
| `opening_balance` | Opening equity carried in from a previous system or imported asset surplus; not a restatement |
| `opening_adjustment` | Retrospective restatement of opening equity, recorded by `YearEnd::restate()` |
| `earnings_transfer` | A fiscal closing transfer, recorded by `YearEnd` |

Recycling and basis adjustments identify an original `oci`/`oci_opening` journal and line. Both sides carry the same evidence, and the database requires one equal reserve/counter-account pair per attribution. Recycling uses an income/expense counter account; a basis adjustment uses an asset/liability counter account. Original and release postings use the book currency and original dimensions. Combined releases cannot exceed the original lot. Dates follow that lot's usage history, including corrections, so backdating cannot overdraw an earlier cutoff. Reverse releases before the original source; exact reversals retain attribution and restore capacity. Non-recyclable OCI cannot be released into profit or loss. For multiple original lots, post separate attributed pairs in one journal; each pair retains its own source journal/line.

`Assets` records non-recyclable OCI for surplus valuation movements, direct-equity attribution for disposal transfers and `opening_balance` for imported surplus. PostgreSQL checks those markers against the asset event. Associate event `evidence['reporting_movements']` accepts a map of account IDs to `ReportingMovement::jsonSerialize()` values, preserving explicit source-category/recycling decisions alongside the reviewed event. Its database checks reconcile journal attribution to that evidence, and associate-source recycling requires the corresponding protected disposal event. Drafts, recurring journals, posting runs and reversals preserve supplied attribution. `banking->postLine()` takes a movement for its counter posting, and journal imports accept `reporting_kind`, `reporting_policy`, `reporting_reference` and `oci_category` columns; recycling, basis adjustments and earnings transfers cannot be imported because they need a source posting or a closing journal. `YearEnd::close()` records `earnings_transfer` under its `CLOSING_PROCEDURE` version. `restate()` takes the host's reviewed policy version, records it as `opening_adjustment` on equity lines that carry no attribution, and keeps attribution the host supplied.

`reports->reportingMovements($snapshot, $mapping, $startsOn, $dimensions, limit: 1000, afterSequence: '0', afterLine: 0)` pages source evidence in journal-sequence/line order, excluding fiscal closings. Each row exposes journal/date/sequence, account ID/code/type, mapped equity component, typed `ReportingMovement` and signed credit-positive functional `Money`. Continue after the last row's `sequence` and `line_no`. Classified statement OCI totals are aggregated in SQL rather than materializing all source events.

Historical saved reports retain their original bytes and hashes. Live classified reports do not guess attribution for legacy journals. Reconcile an explicit opening and record reviewed `oci_opening` lots for carried reserves, using conserving opening/reclassification journals where balances already exist; retain the previous cutoffs and start the attributed reporting range at that transition. Opening lots are not new OCI income. Corrections to earlier source events use the existing exact reversal/repost workflow and period rules. The host supplies the reviewed transition evidence and policy; no in-place history backfill is performed.

Management-defined performance measures are optional reviewed definitions in `performanceMeasures`:

```php
$measures = [
    'adjusted' => [
        'label' => 'Adjusted operating profit',
        'subtotal' => 'operating_profit',
        'review_reference' => 'approved-definition:2027-01',
        'adjustments' => [
            'exceptional' => ['label' => 'Exceptional costs added back', 'accounts' => ['5000'], 'coefficient' => '-1'],
        ],
    ],
];
$effects = ['current' => ['adjusted' => ['exceptional' => [
    'tax' => Money::of('-9', $kes), 'nci' => Money::of('-2', $kes),
]]]];
```

Pass `$measures` as the mapping's `performanceMeasures` argument and `$effects` as the `performanceMeasureEffects` argument to `statement()`, `saveStatement()` or `saveConfiguredStatement()`. Every adjustment in every column requires both tax and NCI effects in the functional currency, including explicit zeros. Effects are signed changes attributable to that adjustment and are retained separately from the pre-tax measure. No tax rate or ownership percentage is inferred. An adjustment selects unique mapped profit-or-loss accounts, starts from credit-positive movement and applies coefficient `1` or `-1`; the example adds back a debit expense. Rows `mpm.adjusted.exceptional` and `mpm.adjusted` expose the reconciliation and resulting measure. The nearest comparable subtotal is explicitly selected from the presented `operating_profit`, `profit_before_financing_and_income_taxes` (when required), `profit_before_income_taxes`, `profit_from_continuing_operations` or `profit` (IFRS 18 paragraphs 118(e)–(f) and 123(c)). Paragraph 118's gross-profit and pre-depreciation subtotals are not generated by the mapping and cannot be selected. Saved effects hydrate as `Money` through `Statement::performanceMeasureEffects`, and changing them under an existing save key fails. Public-communication eligibility, the appropriateness of adjustments, tax/NCI methodology and explanatory disclosures are reviewed host inputs.

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

`TaxCalculator` supports fractional quantities, discounts, inclusive/exclusive taxes, ordered compound taxes, effective dates, and policy versions. It exposes any rounding residual for explicit posting. Tax rates are fractions, such as `0.16`, and must be approved inputs. `Tax\Kenya::vatPack()` packages KRA-published standard/zero VAT and withholding-VAT rates with explicit organization policy dates and chart roles. `Tax\Kenya::vat3Sections()` totals the tax register into VAT3 sections B–F split by counterparty PIN, and `Tax\Kenya\Etims*` implements the KRA eTIMS OSCU v2.0 API (see below).

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

Obligations must total the calculated gross exactly. Each line selects ordered tax codes from the configuration; `taxRoles` maps those codes to approved posting roles. Sales/purchase credits invert the appropriate directions. Nonzero tax rounding and independently quantized FX differences require an explicit functional income/expense rounding role. The journal retains input quantities/prices/discounts, rule versions, per-line results, configuration hash, and FX rounding. An identical retry returns the original even after period closure. Built-in `ledger.tax` version `1` treatments handle withholding and reverse charge with explicit eligibility evidence, recoverability and cash classification.

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

Use `statutory->enqueue(new Statutory\SubmissionRequest(...))` to pin a source, exact payload bytes, provider route, adapter version, environment and external retry identity. `dispatch($book, $submission, $adapter)` claims committed work, invokes a version-matching `Statutory\Adapter` outside database transactions, then records its outcome. Unknown acceptance goes through lookup before another send. The service supports invoice/credit-note and finalized-return evidence. [Delivery contracts](docs/subledgers.md#durable-statutory-delivery) define the executable lifecycle and adapter semantics.

### Kenya eTIMS (OSCU)

```php
use Illuminate\Http\Client\Factory;
use Noria\Ledger\Statutory\SubmissionRequest;
use Noria\Ledger\Tax\Kenya\{EtimsAdapter, EtimsClient, EtimsDevice, EtimsSale};

$device = new EtimsDevice('P051234567A', '00', $communicationKey, 'sandbox');
$client = new EtimsClient(app(Factory::class), $device);

$sale = EtimsSale::invoice(42, 'INV-42', '2026-01-15 10:15:00', [
    ['code' => 'KE1NTXU0000001', 'class_code' => '5020230000', 'name' => 'Consulting services', 'package_unit' => 'NT',
        'quantity_unit' => 'U', 'quantity' => '1', 'price' => '11600', 'tax_type' => 'B'],
], customerPin: 'A123456789B', paymentType: '06');

$id = $accounting->statutory->enqueue(new SubmissionRequest($book, 'etims-42', 'invoice', $invoiceJournal,
    'ke.etims.P051234567A-00', 'kra.etims.oscu', 'oscu-2.0', 'sandbox', 'etims-42', $sale->json($device)));
$accounting->statutory->dispatch($book, $id, new EtimsAdapter($client));
```

`EtimsDevice` holds the PIN, two-digit branch, communication key and environment (`sandbox` → `etims-api-sbx.kra.go.ke`, `production` → `etims-api.kra.go.ke`). `EtimsClient::initialize($serial)` returns the device information, including the `cmcKey` to store; the other methods cover code lists, item classifications, customer lookup, notices, branches, items, branch customers/users, imported items, sales, purchases, stock movements and stock master. `EtimsSale::invoice()` and `creditNote()` build `saveTrnsSalesOsdc` payloads: prices are tax-inclusive, tax is `taxable × rate / (100 + rate)` per line, and types A–E default to 0/16/0/0/8 % (pass `taxRates` when they change). Credit notes need the original invoice number and reason code `01`–`06`, and are queued as `credit_note` submissions against the accepted invoice.

`EtimsAdapter` maps result `000` to accepted with receipt reference `PIN-branch-invcNo-curRcptNo` and keeps `rcptSign`, `intrlData`, `sdcDateTime` and receipt counters as evidence. Validation codes (891–899, 900–912, 921–922, 991–995 except 994) reject, `990` retries, and `994` (duplicate invoice number) or any other result is uncertain. Because OSCU has no sales read-back, lookup re-posts the same bytes and treats `994` as confirmation; invoice numbers must therefore be unique and never reused per branch. Item codes, classifications and units come from `codes()`/`itemClassifications()` and `saveItem()`; the package does not register items for you.

`Tax\Kenya::vat3Sections($taxes->register(...), $partyPins, ['KE-VAT-STANDARD' => 'B', 'KE-VAT-ZERO' => 'D', '' => 'E'])` returns base, tax and document counts for sections B (general rate), C (other rate), D (zero rated), E (exempt) and F (purchases), each split by whether a party has a PIN in `$partyPins`. KRA does not publish the iTax CSV column layouts outside the downloaded VAT3 workbook, so the package produces the section figures, not upload files.

## Valuation and group reporting

`Finance\FinancialCalculator` generates effective-interest carrying-value plans, lease present values/payment rollforwards, probability-weighted discounted credit losses, and exact revenue allocations. Built-in `ledger.finance` version `1` remains available for explicit journal preparation. For protected lifecycle accounting, use `finance->open()`, `period()`, `plan()`, `modify()`, `settle()`, `measureLoss()`, `recognize()`, `writeOff()`, `recover()`, `reverse()` and `cancel()`. These require `Permission::Finance` and independently enforce plan versions, original-rate remeasurement, actual-payment allocations, stage-dependent interest, settlement closure, account ownership and correction order in PostgreSQL. [Finance contracts and boundaries](docs/subledgers.md#protected-finance-contracts) describe the implemented scope and remaining fee-bearing/floating-rate changes, billing and policy work.

`prepay($book, $contract, $key, $date, $cash, $interestPaid, $revisedPayments, $gain, $loss, $basis, $reason)` records a partial prepayment at a carrying date: the remaining contractual payments are re-planned and measured at the original effective rate, the cash is classified as principal and interest, and any prepayment fee or discount is the difference posted to the gain or loss account. Liability prepayments include the cash in the ten-percent derecognition test. `partialWriteOff($book, $contract, $key, $date, $amount, $reason)` writes off part of a loan asset's gross carrying amount, using the loss allowance first and charging the remainder to credit-loss expense; afterwards every period must record actual cash, and `writeOff()` removes the rest. `remeasureLease($book, $contract, $key, $date, $revisedPayments, $gain, $loss, $rightOfUseCarrying, $basis, $reason)` remeasures a lease liability for revised payments at the unchanged discount rate and adjusts the right-of-use asset; a reduction larger than the stated right-of-use carrying amount (capped at the asset account's balance) goes to the gain account. `settle()` on a lease terminates it early: it derecognizes the liability, the right-of-use asset's gross cost and its accumulated depreciation (the `lease.accumulated` role), both taken from the ledger in the contract's dimensions, posts the termination cash as financing, and records the difference as a gain or loss. Revised discount rates for changes in lease term are not yet modelled. `accrueInterest($book, $contract, $key, $date, $accruedAccount, $reason)` accrues the next slot's interest (net of the allowance for credit-impaired loan assets) in proportion to actual days elapsed since the last carrying date, as an auto-reversing accrual against the configured `interest` role; it never changes the effective-interest carrying value, and the full slot interest posts when the period is recorded.

Use `revaluations->revalue()` for outstanding foreign control items and `clear()` for exact reversals. No-change valuations still retain rate evidence and retry identity. Settlement clears the whole item's active unrealized adjustments before allocating historical carrying amounts and posting realized FX. Partial settlement leaves the unpaid amount at its original remaining carrying value until the next explicit revaluation.

Use `monetaryAccounts->register()` to explicitly declare eligible foreign non-control asset/liability accounts. `revalue()` preserves the signed foreign balance while adjusting functional carrying value, including zero-source remainders. Cash adjustments are classified as exchange effects. `reverse()` corrects the latest active valuation for the same dimension bucket. Recorded valuations prevent later postings from silently backdating before that bucket's valuation history.

`postRealized($request, $gain, $loss)` posts a journal and realizes exchange on each registered monetary account it draws down: the outflow removes its proportional share of the carrying value (half-even), and the difference from the functional amount the outflow actually posted is a realized gain or loss, classified as a cash exchange effect for cash accounts. The remaining balance keeps its original carrying rate, and a balance drained to zero leaves no functional remainder. `realize($book, $journalId, $account, $gain, $loss)` does the same for an already posted journal and returns `null` for inflows. PostgreSQL recomputes the share from the balance before the outflow, requires realization before any later activity on the account bucket, and rejects reversing the outflow while its realization is active.

`inventory` owns stock valuation pools and immutable receipt/issue/value-adjustment history. FIFO consumes remaining cost layers; moving average divides total remaining cost by quantity without storing a rounded unit price. The final issue consumes the exact remaining cost. Negative quantities, negative stock values, and movements before an existing pool event are rejected. Correct existing movements in reverse dependency order.

Specific-identification pools (`ValuationMethod::Specific`) cost each issue from the lots it names: `receive(..., lot: 'A-2026', expiresOn: '2026-06-30')` creates a lot layer and `issue(..., lots: ['A-2026' => new Quantity('10')])` consumes that lot's layers oldest first, with exact proportional cost; the lot quantities must add up to the issue. Unselected issues, unknown or exhausted lots, receipts without a lot, and lots in FIFO or moving-average pools are rejected. A customer return restores the single lot it was issued from, and reversals keep the original lot. `pool(..., serialized: true)` makes each lot a serial number that enters stock one unit at a time and only while it is not already in stock. `lots($snapshot, $pool)` lists remaining quantity and cost by lot, earliest expiry first, at the snapshot's date and knowledge cutoff. `transfer(..., lot: 'A-2026')` moves one lot between pools; a specific-identification destination keeps the lot and its expiry, which PostgreSQL checks against the issued lot, while a FIFO or moving-average destination receives it as ordinary cost. Production does not yet consume or create lots.

`returnIssue()` receives against an original issue's remaining quantity and cost, rather than today's valuation. Returns can enter another compatible pool while restoring the original expense dimensions. `transfer()` links both sides of a same-SKU warehouse movement at exact source cost through a non-cash balance-sheet clearing account. `reverseTransfer()` corrects both movements atomically; individual transfer movements cannot be reversed independently. `produce()` consumes multiple component pools into a finished-good pool at exact component cost plus an explicit `conversionCost` allocation from recognized WIP; `reverseProduction()` restores every original leg and returns conversion costs to WIP. Zero-cost output retains its physical quantities.

`returnReceipt()` links a supplier return to an original purchase receipt. It removes the remaining original FIFO layer or current moving-average carrying cost, credits the original receipt-price amount through an explicitly selected clearing account, and posts the difference to an explicit income/expense variance account. It preserves original-price cents through partial returns without silently repricing remaining stock. A supplier credit document remains a separate payable/tax event.

`assets` owns capitalization and its financial lifecycle, including imported opening depreciation/impairment. Depreciation follows explicit versioned plans. Estimate changes, impairment, and disposal first recognize due depreciation and apply the asset's selected partial-period convention: actual days, completed periods, or the full current period. Plans must conserve carrying value after residual and impairment. Corrections restore the original financial effects and prior plan.

`assets->addCost()` capitalizes approved improvements with a replacement residual/plan. `disposePart()` removes an explicit proportion of current gross cost, accumulated depreciation, and impairment and recognizes the part's gain/loss. `revalue()` accepts signed fair-value changes, allocating losses against per-asset surplus before expense and recovering prior profit-or-loss charges before creating surplus. `recover()` caps impairment reversals at explicit recoverable-value and depreciated no-loss carrying inputs. Disposal can transfer released surplus directly to retained earnings. Financial effects, attribution budgets and plans reverse exactly; cash effects are classified as investing.

`assets->rollForward($snapshot, $startsOn, $costAccountId)` produces the property, plant and equipment movement schedule per asset: cost (opening, additions, revaluations, disposals, closing), accumulated depreciation (opening, acquired, charge, revaluation eliminations, disposals, closing) and accumulated impairment (opening, acquired, losses, reversals, revaluations, disposals, closing), plus opening and closing carrying amounts. Accumulated amounts are credit-positive. Movements come from each asset's own event journals, corrections net against the category they correct, and the charge includes stub-period depreciation recognized by disposals and other events. Group rows by `cost_account_id` for class-level disclosure.

`assets->transfer($book, $asset, $key, $date, ['campus' => 'west'], $reason)` moves an asset to other dimensions within the book: due depreciation is posted first, then cost, accumulated depreciation and accumulated impairment move from the current to the new dimensions in one journal, and later events post with the new dimensions. `currentDimensions()` returns them. Reversing the transfer (as the latest event) restores the previous dimensions. Revaluation surplus stays in the dimensions where it was recognized. Moving an asset to another entity's book is a disposal in one book and a capitalization in the other, posted as an atomic batch.

`allocateGroupImpairment($snapshot, $assetIds, $loss, $floors)` allocates a cash-generating unit's impairment loss to its assets pro rata to carrying amount, never taking an asset below its floor (the higher of fair value less costs of disposal, value in use and zero), and reallocates the excess to the other assets until the loss is absorbed. Each round distributes whole minor units by largest remainder, so the allocation sums exactly to the loss. It only calculates: record each asset's share with `impair()` and its revised plan. Goodwill allocated to the unit is impaired first in the group consolidation, not here.

`consolidation` saves the combined accounts of explicitly selected entities plus an elimination book. It requires compatible mappings, translation inputs and separate translation/rounding equity accounts. NCI requires distinct NCI/retained-earnings accounts and includes deficit balances and period profit/loss attribution. Investment elimination pins acquisition snapshots and consideration. `Consolidation\FairValue` supplies incremental acquisition fair values, tax bases, deferred tax and subsequent movements. Supplying measured acquisition NCI elects full goodwill; otherwise NCI is proportionate. Goodwill survives subsequent earnings and source-year closing. `Investment(..., goodwillImpairments: ['2026-06-30' => Money::of('5', $kes)], impairmentAccountId: $goodwillImpairment)` applies reviewed cumulative impairments dated up to the report date: goodwill falls by their total, impairments within the reporting period are an expense, earlier ones reduce retained earnings, and under full goodwill the NCI's ownership share reduces NCI and its profit attribution. Impairments cannot exceed acquisition goodwill, are never reversed, and are not yet supported for foreign subsidiaries. Historical equity accepts exact translated balances or explicit rates. `get()` and `evidence()` recheck every source book's current access. `matchIntercompany($receivableSnapshot, $dueFrom, $party, $payableSnapshot, $dueTo, $party)` compares one book's receivable from another group company with that company's payable, by transaction currency at fixed snapshots, and returns the difference to investigate before eliminating; it needs read access to both books.

`associates` posts the equity method in the investor's own book, with explicit significant-influence evidence and `Permission::Investments`. `acquire()` records cost and reviewed fair-value net assets; `profit()`, `oci()` and `dividend()` apply exact ownership shares. Losses stop at the investment balance unless `obligate()` records a reviewed loss-obligation limit. `impair()`/`recover()` measure the whole investment including embedded goodwill. Suspended losses, support liabilities, source evidence, dimensions, snapshots and exact corrections are independently checked in PostgreSQL.

`associates->dispose($book, $associate, $key, $date, $fraction, $proceeds, $proceedsAccount, $gain, $loss, $reason, $ociTransferAccount)` sells a fraction of the holding: it derecognizes the same fraction of carrying value and impairment, releases that fraction of the investor's OCI share to the given reclassification (income) or retained-earnings (equity) account, and posts proceeds less carrying value as a gain or loss. A partial sale keeps significant influence and later shares use the reduced ownership. Losing influence is a disposal of fraction `1` with the retained stake's fair value and its financial-asset account, which enters the gain or loss. `increase()` buys a further stake below control at reviewed cost and fair-value net assets, recognizing incremental goodwill inside the carrying amount or an incremental bargain in profit; later shares use the higher ownership. Cumulative share rounding restarts at each ownership change, so every regime stays exact. `holdForSale()` stops equity accounting (profit, OCI, dividends and new obligations are rejected) while impairment to fair value less costs to sell and disposal remain available. Disposal requires settled obligations, and a partial sale or increase requires no suspended losses. All three reverse exactly as the latest event.

[Valuation and consolidation contracts](docs/subledgers.md) provide examples, accounting conventions, and boundaries.

## Controls and customization

Use `Authorizer` for authenticated permissions and book access. Use `PostingPolicy` for additional request-level checks such as independent approval, account restrictions, or source validation. Tag implementations with `PostingPolicy::class` in Laravel's container. Policy versions are stored on successful journals. Policies can reject requests but cannot disable the database's invariants. They execute inside retriable transactions and must not send notifications or perform external side effects.

### Draft journals and approval

```php
$draft = $accounting->drafts->prepare(new JournalRequest($book, 'mj-2026-014', '2026-01-31', 'Accrued audit fee', $postings));
$journal = $reviewerAccounting->drafts->approve($book, $draft->id, 'Agreed to engagement letter');
```

`prepare()` stores an immutable `JournalRequest` without posting it and requires `ledger.prepare`. A revision passes the pending draft it supersedes. `approve()` requires `ledger.approve`, not `ledger.post`, and posts exactly the prepared request under the key `draft:<id>`; `reject()` needs the same permission, and the preparer can `withdraw()`. PostgreSQL rejects approval or rejection by the preparing actor, decisions on superseded or already decided drafts, an approved journal whose fingerprint differs from the draft, and `draft:` journals without a recorded approval. To require review for manual entries, grant preparers `ledger.prepare` without `ledger.post`. `list()` filters by `pending`, `superseded`, `approved`, `rejected` or `withdrawn`.

Use `Accounting\PostingTreatment` to generate product-specific journals. Implement `name()`, `version()`, and `prepare(Scope $scope, TreatmentRequest $request, array $accounts): JournalRequest`, and tag the implementation with `PostingTreatment::class`. Call `treatments->post(new TreatmentRequest($book, $key, $date, $source, $name, $version, $configuration, $inputs))`. The engine resolves pinned account roles, retains exact inputs and configuration evidence, checks the returned book/key/date/source, and applies the ordinary posting policies and database invariants. Treatments cannot impersonate a reversal or closing event. Duplicate name/version registrations fail. Identical retries return the original without rerunning the generator, including when that implementation is no longer registered. Preparation is transaction-local and must be deterministic and side-effect-free.

Scoped services such as `Documents`, `Reports`, and `Assets` can be extended with readonly subclasses bound through Laravel's container, for example `$app->bind(Documents::class, SchoolDocuments::class)`. Session construction supplies its own `db`, `journals`, and other scoped dependencies to each resolution. Additional constructor dependencies can be injected normally. This avoids a process-global tenant binding in long-lived workers. Standalone consumers can pass a container to `Ledger` and pass treatments explicitly through its `postingTreatments` argument. Database-owned valuation algorithms and integrity rules require reviewed schema changes; service replacement does not redefine them.

### Durable integration feed

`events->cutoff($book)` captures a committed-prefix boundary under the book lock. Read `events->page($book, afterSequence: $cursor, limit: 500, throughSequence: $cutoff)` and advance the cursor using each returned `sequence`. The feed is derived atomically from audit history, ordered separately within each book, and protected against direct insertion, editing, deletion, and truncation. Its ordering index references existing audit payloads rather than duplicating financial JSON. Failed work and identical retries add no events.

Events expose the source table/operation, before/after JSON, actor, firm, audit ID, and recorded time. Their sequence is distinct from financial snapshot sequences and global audit IDs. Book-scoped catalog changes and financial/evidence events are included; tenant-level entities, currencies, parties, and batch headers remain in audit history. Consumers persist a checkpoint per book, deduplicate by book/event sequence, and commit their own processing/checkpoint atomically. Delivery is replayable; provider calls, worker scheduling, delivery acknowledgments, and exactly-once external effects belong to the host.

Reads on a connection inside an enclosing transaction also see that transaction's own pending events. External delivery must follow the outermost commit.

Book mutations serialize on the book row. Cross-book batches lock books in UUID order and commit all member journals or none. This is an explicit correctness baseline, not a measured enterprise-throughput claim. Publish application notifications after the outermost transaction commits. Financial commands perform no provider calls. Statutory workers invoke host adapters only after their durable claim commits; worker scheduling and webhook authentication remain host responsibilities.

PostgreSQL enforces tenant RLS, composite ownership constraints, exact balancing, source/key uniqueness, open periods, generated projections, append-only history, and settlement limits. Use a separate deployment owner and a runtime role with no superuser, bypass-RLS, table ownership, schema creation, or trigger-management privileges. Grant only the DML needed on ledger tables and SELECT on the currency-reference table. RLS and triggers do not protect against a database administrator changing the schema or disabling controls.

### Storage conventions

Every package table starts with `id`, and `id` is its sole primary key, including relationship/link tables. There are no composite-primary-key exceptions. The audit table uses an ordered `bigint GENERATED ALWAYS AS IDENTITY`; the other 57 tables use UUID identities with PostgreSQL `uuidv7()` defaults. PHP-generated identities use Laravel's `Str::uuid7()`. Explicit v4/imported UUIDs remain accepted. Tenant/book ownership tuples, natural codes, journal line numbers, relationship pairs and feed sequences remain unique alternate keys; composite foreign keys continue to enforce ownership. Financial ordering uses book sequences and accounting dates, not UUID order.

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
composer check
```

`composer check` runs every gate CI runs: `lint` (Pint, Laravel preset from `pint.json`), `analyse` (PHPStan at max over `src`, `database` and `tests`), `types` (100% type coverage) and `test` (the Pest suite with a 90% line-coverage floor, which needs Xdebug or PCOV). `composer install` points `core.hooksPath` at `.githooks`, whose pre-commit hook runs `composer check`; run `composer hooks` to set it again in an existing clone.

Integration tests fail if PostgreSQL is missing; they do not silently skip. Concurrent tests use independent PHP processes and connections and observe both processes waiting on the same book lock before releasing them.

### Scale measurements

`tests/benchmark.php` runs outside the test transaction and uses the same disposable database. Install its complete schema with an integration test first. Each invocation creates committed synthetic tenants/books and leaves them available for investigation until the next test schema reset; do not run it concurrently with Pest or another benchmark. It refuses disabled/replica-only triggers, missing cross-domain integrity, unprotected tenant tables, bypass-RLS roles and relaxed commit durability. It refreshes planner statistics after seeding.

```sh
XDEBUG_MODE=off php tests/benchmark.php --seed=10000 --iterations=100 --workers=4 --books=1 --workload=journal --plans
XDEBUG_MODE=off php tests/benchmark.php --seed=10000 --iterations=100 --workers=4 --books=4 --workload=journal
XDEBUG_MODE=off php tests/benchmark.php --seed=1000 --iterations=100 --workers=4 --books=1 --workload=allocation
XDEBUG_MODE=off php tests/benchmark.php --seed=10000 --iterations=100 --workers=4 --books=1 --workload=oldest
XDEBUG_MODE=off php tests/benchmark.php --seed=1000 --iterations=100 --workers=4 --books=1 --workload=fifo
XDEBUG_MODE=off php tests/benchmark.php --seed=1000 --iterations=100 --workers=4 --books=1 --workload=average
XDEBUG_MODE=off php tests/benchmark.php --seed=1000 --iterations=100 --workers=4 --books=1 --workload=finance
```

```sh
XDEBUG_MODE=off php tests/benchmark.php --scaling --parties=1000 --cycles=5 --factor=10
```

`--scaling` builds two utility books, the second `factor` times larger. Each billing cycle bills every account through `postRun()`, receives payment from 80% of accounts, allocates 10% of receipts oldest-first, and refreshes statistics. It verifies that aging, paged party balances and the control account reconcile, then reports each read's median time and PHP peak memory at both sizes, with their ratio. The ratios are the result: paged and per-party reads should stay flat as the book grows, and whole-book reads should grow no faster than the book. Absolute local timings are not production capacity.

JSON output records environment/revision, seed and final cardinalities, separately committed command latency percentiles, writer throughput, fixed-cutoff reader latency and conservation checks. `--plans` adds actual report SQL/bindings and `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` under the scoped runtime role. Seeding is excluded from writer throughput; reader completion is reported separately. Allocation and oldest count invoice, receipt and allocation as three commands; oldest seeds each settled invoice for one of 100 other parties so party-scoped reads face a large unrelated allocation history. Four-book mode uses separate tenants. Inventory begins with one funded layer; finance runs zero-interest contracts with one daily slot per iteration (at most 600). Processes start together, but the fixed-count reader is not a sustained concurrent-load generator. Queries have a 60-second timeout and failures produce a nonzero exit rather than a partial successful baseline.

See [scale acceptance](docs/pending-non-code.md#scale-acceptance) for measured local results, backup/restore verification and the remaining production-scale release gate. Run recovery drills with an administrative backup role and restore into a separate disposable database; never restore over the source being measured. Backup/restore tooling is PostgreSQL-native and separate from this command harness.

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
