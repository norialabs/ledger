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
- Perpetual FIFO and moving-average inventory, exact fractional quantities, remaining-stock value adjustments, original-cost customer returns, atomic warehouse transfers, and dependent movement reversals.
- Asset capitalization, opening balances, depreciation, prospective estimates, impairment losses, disposal, and exact corrections.
- Saved group reports, explicit eliminations, account mapping, currency translation, historical equity, and separate translation/rounding reserves.
- Fiscal closing into retained earnings, period locks, and reasoned reopening.
- Bank-statement validation, partial matching, matching releases, statement corrections, and reconciliation without duplicate cash postings.
- Atomic cross-book posting and reversal batches, including intercompany transfers.
- General ledger, trial balance, income statement, balance sheet, cash-flow classification, and control-account reconciliation.
- Reproducible numerical report snapshots, including after backdated postings and allocation changes.
- Deny-by-default authorization and optional versioned posting policies.

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

Bind `NoriaLabs\Ledger\Access\Authorizer` to your application's access service before using the package. Its `allows(Scope $scope, Permission $permission, ?string $bookId = null): bool` method must verify the authenticated actor, client tenant, firm delegation, requested capability, and target book. Tenant-level setup and intercompany coordination have a null book target; operations on individual books also check the corresponding book target. The default implementation denies everything.

The application creates a scope from trusted authentication state:

```php
use NoriaLabs\Ledger\Access\Scope;
use NoriaLabs\Ledger\Ledger;

$accounting = app(Ledger::class)->for(new Scope($clientTenantId, $actorId, $firmId));
```

A firm manages delegations to client tenants. A firm's own accounts remain in its own tenant. Changing accountants changes access grants; it does not require moving client books. Membership, engagement management, client portals, approval records, and handover interfaces belong to the host SaaS. Access is evaluated on every operation.

## First book

```php
use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Money\Currency;

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

## Invoice and receipt

```php
use NoriaLabs\Ledger\Accounting\Source;
use NoriaLabs\Ledger\Documents\Charge;
use NoriaLabs\Ledger\Documents\DocumentKind;
use NoriaLabs\Ledger\Documents\DocumentRequest;
use NoriaLabs\Ledger\Documents\Obligation;
use NoriaLabs\Ledger\Documents\PaymentRequest;
use NoriaLabs\Ledger\Money\Money;

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
use NoriaLabs\Ledger\Accounting\AllocationRequest;

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

Validation can raise `LedgerException`, Brick arithmetic/precision exceptions, or database exceptions for storage constraints and deferred commit checks. Host HTTP adapters should translate them into their own error contract. Every failed command rolls back its journal, projections, audit records, and book sequence together.

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

## Money, tax, and schedules

Amounts enter as plain decimal strings. `Money::of()` rejects excess fractional precision; `Money::minor()` accepts integer strings. Storage supports 38-digit signed minor-unit magnitudes and currency scales from 0 through 12. Arithmetic and serialization do not use floats. Aggregations also fail on overflow rather than clipping a total.

`Currency::iso()` uses Brick Money's ISO definitions. Explicit `new Currency($code, $scale)` supports custom units, while recognized ISO scales cannot be redefined. The migration freezes ISO reference scales in the database. Review currency-definition changes before upgrading dependencies against an existing database.

`ExchangeRate` records source/target currencies, the exact rate, its effective date, provenance, and explicit rounding. Rates support at most 20 integer and 18 fractional digits. PHP and PostgreSQL independently calculate functional amounts. Tiny foreign movements can retain positive original amounts while rounding to zero functional units; they remain visible in the original currency.

`TaxCalculator` supports fractional quantities, discounts, inclusive/exclusive taxes, ordered compound taxes, effective dates, and policy versions. It exposes any rounding residual for explicit posting. Tax rates are fractions, such as `0.16`, and must be approved inputs. No Kenyan statutory rates, tax eligibility decisions, returns, or eTIMS connector are shipped.

`Schedule::equal()` and `Schedule::actualDaysByMonth()` conserve every minor unit through Brick Money's largest-remainder allocation. The latter uses inclusive actual service days and calendar-month boundaries. Persist generic recognition schedules with `schedules->create()`, execute due rows with `postDue()`, and cancel unposted work with `cancel()`. Plans are immutable, and each run has a unique journal. Asset depreciation uses the asset lifecycle below; recognition eligibility remains a host policy.

## Valuation and group reporting

Use `revaluations->revalue()` for outstanding foreign control items and `clear()` for exact reversals. No-change valuations still retain rate evidence and retry identity. Settlement clears the whole item's active unrealized adjustments before allocating historical carrying amounts and posting realized FX. Partial settlement leaves the unpaid amount at its original remaining carrying value until the next explicit revaluation.

Use `monetaryAccounts->register()` to explicitly declare eligible foreign non-control asset/liability accounts. `revalue()` preserves the signed foreign balance while adjusting functional carrying value, including zero-source remainders. Cash adjustments are classified as exchange effects. `reverse()` corrects the latest active valuation for the same dimension bucket. Recorded valuations prevent later postings from silently backdating before that bucket's valuation history.

`inventory` owns stock valuation pools and immutable receipt/issue/value-adjustment history. FIFO consumes remaining cost layers; moving average divides total remaining cost by quantity without storing a rounded unit price. The final issue consumes the exact remaining cost. Negative quantities, negative stock values, and movements before an existing pool event are rejected. Correct existing movements in reverse dependency order.

`returnIssue()` receives against an original issue's remaining quantity and cost, rather than today's valuation. Returns can enter another compatible pool while restoring the original expense dimensions. `transfer()` links both sides of a same-SKU warehouse movement at exact source cost through a non-cash balance-sheet clearing account. `reverseTransfer()` corrects both movements atomically; individual transfer movements cannot be reversed independently.

`assets` owns capitalization and its financial lifecycle, including imported opening depreciation/impairment. Depreciation follows explicit versioned plans. Estimate changes, impairment, and disposal first recognize due depreciation and apply the asset's selected partial-period convention: actual days, completed periods, or the full current period. Plans must conserve carrying value after residual and impairment. Corrections restore the original financial effects and prior plan.

`consolidation` saves the combined accounts of explicitly selected entities plus an elimination book. It requires compatible account mappings, approved translation inputs, and separate equity accounts for translation and rounding. Historical equity accepts exact translated balances or explicit rates. `get()` returns the saved report; `evidence()` includes its inputs, provenance, and hash-verifiable result JSON. Both recheck access to every source book.

[Valuation and consolidation contracts](docs/subledgers.md) provide examples, accounting conventions, and boundaries. Automatic ownership/NCI/acquisition accounting, supplier stock returns, asset revaluation, and statutory localization remain outside the implemented scope.

## Controls and customization

Use `Authorizer` for authenticated permissions and book access. Use `PostingPolicy` for additional request-level checks such as independent approval, account restrictions, or source validation. Tag implementations with `PostingPolicy::class` in Laravel's container. Policy versions are stored on successful journals. Policies can reject requests but cannot disable the database's invariants. They execute inside retriable transactions and must not send notifications or perform external side effects.

Book mutations serialize on the book row. Cross-book batches lock books in UUID order and commit all member journals or none. This is an explicit correctness baseline, not a measured enterprise-throughput claim. Publish application notifications after the outermost transaction commits. No provider HTTP calls or webhook processing are performed by this package.

PostgreSQL enforces tenant RLS, composite ownership constraints, exact balancing, source/key uniqueness, open periods, generated projections, append-only history, and settlement limits. Use a separate deployment owner and a runtime role with no superuser, bypass-RLS, table ownership, schema creation, or trigger-management privileges. Grant only the DML needed on ledger tables and SELECT on the currency-reference table. RLS and triggers do not protect against a database administrator changing the schema or disabling controls.

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
- [Formance conventions](https://docs.formance.com/reference/api/conventions): separate business references, retry protection, effective time, and recorded time.
- [Brick Money](https://github.com/brick/money) and [Brick Math](https://github.com/brick/math): reused exact arithmetic, ISO definitions, and allocation algorithms.
- [PostgreSQL trigger semantics](https://www.postgresql.org/docs/18/sql-createtrigger.html): storage checks, derived records, and deferred integrity checks.
