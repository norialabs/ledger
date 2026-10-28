<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigDecimal;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Banking\StatementLine;
use Noria\Ledger\Banking\StatementRequest;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Imports\ImportFormat;
use Noria\Ledger\Imports\ImportKind;
use Noria\Ledger\Imports\ImportPreview;
use Noria\Ledger\Imports\Sources;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\RateType;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Row;
use stdClass;
use Throwable;

readonly class Imports
{
    private const FIELDS = [
        'bank_statement' => [
            'external_id' => ['receipt no', 'receipt', 'reference', 'transaction id', 'transaction reference', 'ref', 'bank reference'],
            'value_on' => ['completion time', 'date', 'value date', 'transaction date', 'posting date', 'txn date', 'booking date'],
            'amount' => ['transaction amount', 'net amount'],
            'money_in' => ['paid in', 'credit', 'credits', 'money in', 'deposit', 'deposits'],
            'money_out' => ['withdrawn', 'debit', 'debits', 'money out', 'withdrawal', 'withdrawals'],
            'description' => ['details', 'narration', 'narrative', 'particulars', 'memo'],
            'balance' => ['running balance', 'closing balance'],
            'counterparty' => ['other party info', 'payer', 'party', 'counter party'],
            'account_reference' => ['a c no', 'bill reference', 'bill ref', 'account no', 'account number', 'account reference'],
            'status' => ['transaction status'],
        ],
        'parties' => ['reference' => ['code', 'customer number', 'supplier number', 'account number'], 'name' => ['customer name', 'supplier name', 'full name']],
        'accounts' => ['code' => ['account code', 'number'], 'name' => ['account name'], 'type' => ['account type'], 'parent' => ['parent code', 'parent account'],
            'group' => ['is group'], 'control' => ['is control'], 'normal_side' => ['normal balance'], 'cash_flow' => ['cash flow class'], 'currency' => ['account currency']],
        'journals' => ['journal' => ['journal reference', 'journal no', 'voucher', 'entry'], 'date' => ['entry date', 'posting date'], 'description' => ['narration', 'journal description'],
            'account' => ['account code'], 'debit' => ['dr'], 'credit' => ['cr'], 'currency' => [], 'rate' => ['exchange rate'], 'party' => ['party reference', 'customer', 'supplier'],
            'due_on' => ['due date'], 'dimensions' => [], 'line_description' => ['line description', 'line memo'],
            'reporting_kind' => ['reporting movement'], 'reporting_policy' => ['reporting policy version'], 'reporting_reference' => [], 'oci_category' => []],
        'exchange_rates' => ['base' => ['from', 'base currency'], 'quote' => ['to', 'quote currency'], 'type' => ['rate type'], 'date' => ['effective date', 'as of'], 'rate' => [], 'source' => ['provider']],
    ];

    public function __construct(private ScopedDatabase $db, private Catalog $catalog, private Journals $journals, private Banking $banking, private Rates $rates) {}

    /** @param array<string, mixed> $options */
    public function preview(ImportKind $kind, string $path, ?ImportFormat $format = null, array $options = []): ImportPreview
    {
        $format ??= ImportFormat::fromPath($path);
        if (in_array($format, [ImportFormat::Mt940, ImportFormat::Camt053], true)) {
            if ($kind !== ImportKind::BankStatement) {
                throw new LedgerException('MT940 and CAMT.053 files import bank statements only.');
            }
            $read = Sources::statement($path, $format);

            return $this->validate($kind, $read['rows'], [...array_filter($read['summary'], fn (mixed $value): bool => $value !== null), ...$options], ['external_id', 'value_on', 'amount', 'description']);
        }
        $fields = self::FIELDS[$kind->value];
        $read = Sources::table($path, $format, $fields, $options);
        $map = $this->columns($read['headers'], $fields, $options);
        $rows = [];
        foreach ($read['rows'] as $raw) {
            $row = [];
            foreach ($map as $canonical => $header) {
                $row[$canonical] = $raw[$header] ?? null;
            }
            $rows[] = $row;
        }
        if ($kind === ImportKind::BankStatement) {
            [$rows, $derived] = $this->statementRows($rows, $format === ImportFormat::Mpesa || isset($map['status']), $options);
            $options = [...$derived, ...$options];
        }

        return $this->validate($kind, $rows, $options, $read['headers']);
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @param  list<string>  $headers
     */
    public function validate(ImportKind $kind, array $rows, array $options = [], array $headers = []): ImportPreview
    {
        return $this->db->run(Permission::Read, fn (): ImportPreview => match ($kind) {
            ImportKind::BankStatement => $this->checkStatement($rows, $options, $headers),
            ImportKind::Parties => $this->checkParties($rows, $options, $headers),
            ImportKind::Accounts => $this->checkAccounts($rows, $options, $headers),
            ImportKind::Journals => $this->checkJournals($rows, $options, $headers),
            ImportKind::ExchangeRates => $this->checkRates($rows, $headers),
        }, $this->bookOption($options));
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @return array{kind: string, created: list<string>, existing: int}
     */
    public function submit(ImportKind $kind, array $rows, array $options = []): array
    {
        return $this->db->run(Permission::Read, function () use ($kind, $rows, $options): array {
            $preview = $this->validate($kind, $rows, $options);
            if (! $preview->isValid()) {
                throw new LedgerException('The import has validation errors; preview and correct it before submitting.');
            }
            $created = [];
            $existing = 0;
            foreach ($preview->rows as $row) {
                if (($row['status'] ?? null) === 'existing') {
                    $existing++;
                }
            }
            if ($kind === ImportKind::BankStatement) {
                $created[] = $this->submitStatement($preview);
            } elseif ($kind === ImportKind::Parties) {
                foreach ($preview->rows as $row) {
                    if (($row['status'] ?? null) === 'new') {
                        $created[] = $this->catalog->party($this->text($options, 'entity_id'), $this->string($row, 'reference'), $this->string($row, 'name'));
                    }
                }
            } elseif ($kind === ImportKind::Accounts) {
                $created = $this->submitAccounts($preview, $this->text($options, 'book_id'));
            } elseif ($kind === ImportKind::Journals) {
                $created = $this->submitJournals($preview, $this->text($options, 'book_id'));
            } else {
                foreach ($preview->rows as $row) {
                    if (($row['status'] ?? null) === 'new') {
                        $created[] = $this->rates->record(Currency::iso($this->string($row, 'base')), Currency::iso($this->string($row, 'quote')),
                            RateType::from($this->string($row, 'type')), $this->string($row, 'date'), $this->string($row, 'rate'), $this->string($row, 'source'))->id;
                    }
                }
            }

            return ['kind' => $kind->value, 'created' => $created, 'existing' => $existing];
        }, $this->bookOption($options));
    }

    /** @param list<string> $headers
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function columns(array $headers, array $fields, array $options): array
    {
        $explicit = is_array($options['columns'] ?? null) ? $options['columns'] : [];
        $byLabel = [];
        foreach ($headers as $header) {
            $byLabel[Sources::label($header)] ??= $header;
        }
        $map = [];
        foreach ($fields as $canonical => $aliases) {
            $chosen = $explicit[$canonical] ?? null;
            if (is_string($chosen)) {
                if (! in_array($chosen, $headers, true)) {
                    throw new LedgerException('Mapped column "'.$chosen.'" is not in the file.');
                }
                $map[$canonical] = $chosen;

                continue;
            }
            foreach ([$canonical, ...$aliases] as $alias) {
                if (isset($byLabel[Sources::label($alias)])) {
                    $map[$canonical] = $byLabel[Sources::label($alias)];

                    break;
                }
            }
        }

        return $map;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @return array{list<array<string, mixed>>, array<string, mixed>}
     */
    private function statementRows(array $rows, bool $statusFiltered, array $options): array
    {
        $format = is_string($options['date_format'] ?? null) ? $options['date_format'] : null;
        $result = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if ($statusFiltered && is_string($row['status'] ?? null) && strtolower($row['status']) !== 'completed') {
                $skipped++;

                continue;
            }
            $amount = Sources::amount($row['amount'] ?? null);
            if ($amount === null) {
                $in = Sources::amount($row['money_in'] ?? null);
                $out = Sources::amount($row['money_out'] ?? null);
                $amount = $in !== null && BigDecimal::of($in)->isGreaterThan(0) ? $in : ($out === null ? null : (string) BigDecimal::of($out)->abs()->negated());
            }
            $description = trim(implode(' - ', array_filter([is_string($row['description'] ?? null) ? $row['description'] : null, is_string($row['counterparty'] ?? null) ? $row['counterparty'] : null])));
            $result[] = ['external_id' => $row['external_id'] ?? null, 'value_on' => Sources::date($row['value_on'] ?? null, $format) ?? $row['value_on'] ?? null,
                'amount' => $amount, 'description' => $description === '' ? 'Statement movement' : $description,
                'counterparty' => $this->present($row['counterparty'] ?? null), 'account_reference' => $this->present($row['account_reference'] ?? null), 'balance' => Sources::amount($row['balance'] ?? null)];
        }
        $derived = ['skipped' => $skipped];
        if ($result !== [] && array_filter($result, fn (array $row): bool => $row['balance'] === null || $row['amount'] === null) === []) {
            foreach ([$result, array_reverse($result)] as $ordered) {
                if ($this->chained($ordered)) {
                    $result = $ordered;
                    $derived['opening'] = (string) BigDecimal::of($this->string($ordered[0], 'balance'))->minus($this->string($ordered[0], 'amount'));
                    $derived['closing'] = $this->string($ordered[array_key_last($ordered)], 'balance');

                    break;
                }
            }
        }

        return [$result, $derived];
    }

    /** @param list<array<string, mixed>> $rows */
    private function chained(array $rows): bool
    {
        for ($index = 1; $index < count($rows); $index++) {
            if (! BigDecimal::of($this->string($rows[$index - 1], 'balance'))->plus($this->string($rows[$index], 'amount'))->isEqualTo($this->string($rows[$index], 'balance'))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @param  list<string>  $headers
     */
    private function checkStatement(array $rows, array $options, array $headers): ImportPreview
    {
        /** @var array<int|string, list<string>> $errors */
        $errors = [];
        $book = $this->db->find('ledger_books', $this->text($options, 'book_id'));
        $account = $this->db->find('ledger_accounts', $this->text($options, 'account_id'), Row::text($book, 'id'));
        $currency = $this->db->currency(Row::nullable($account, 'currency_code') ?? Row::text($book, 'currency_code'));
        if (Row::nullable($account, 'cash_flow') !== 'cash' || Row::boolean($account, 'is_group') || Row::boolean($account, 'is_control')) {
            $errors['_'][] = 'Statements import into a cash or bank posting account.';
        }
        $seen = [];
        $total = BigDecimal::zero();
        $dates = [];
        $clean = [];
        foreach ($rows as $index => $row) {
            $id = is_scalar($row['external_id'] ?? null) ? trim((string) $row['external_id']) : '';
            $date = Sources::date($row['value_on'] ?? null);
            $amount = Sources::amount($row['amount'] ?? null);
            $description = is_string($row['description'] ?? null) && trim($row['description']) !== '' ? trim($row['description']) : 'Statement movement';
            $id === '' || strlen($id) > 128 ? $errors[$index][] = 'A unique reference of up to 128 characters is required.' : null;
            isset($seen[$id]) && $id !== '' ? $errors[$index][] = 'Reference '.$id.' repeats in this file.' : null;
            $date === null ? $errors[$index][] = 'Invalid date.' : $dates[] = $date;
            if ($amount === null || BigDecimal::of($amount)->isZero()) {
                $errors[$index][] = 'A non-zero amount is required.';
            } elseif (BigDecimal::of($amount)->getScale() > $currency->scale) {
                $errors[$index][] = 'Amount has more decimals than '.$currency->code.' allows.';
            } else {
                $total = $total->plus($amount);
            }
            $seen[$id] = true;
            $clean[] = [...$row, 'external_id' => $id, 'value_on' => $date ?? $row['value_on'] ?? null, 'amount' => $amount, 'description' => substr($description, 0, 1024)];
        }
        if ($seen !== []) {
            $imported = $this->db->table('ledger_bank_lines')->where('book_id', Row::text($book, 'id'))->where('account_id', Row::text($account, 'id'))
                ->whereIn('external_id', array_keys($seen))->whereNotIn('statement_id', $this->db->table('ledger_bank_voids')->where('book_id', Row::text($book, 'id'))->select('statement_id'))
                ->pluck('external_id')->all();
            foreach ($clean as $index => $row) {
                if (in_array($row['external_id'], $imported, true)) {
                    $errors[$index][] = 'Reference '.$this->string($row, 'external_id').' was already imported for this account.';
                }
            }
        }
        $opening = Sources::amount($options['opening'] ?? null);
        $closing = Sources::amount($options['closing'] ?? null);
        $opening === null ? $errors['_'][] = 'Provide the opening balance; this file has no running balance to derive it.' : null;
        if ($opening !== null && $closing !== null && ! BigDecimal::of($opening)->plus($total)->isEqualTo($closing)) {
            $errors['_'][] = 'Opening '.$opening.' plus movements '.$total.' does not equal closing '.$closing.'.';
        }
        sort($dates);
        $summary = ['book_id' => Row::text($book, 'id'), 'account_id' => Row::text($account, 'id'), 'currency' => $currency->code,
            'reference' => is_string($options['reference'] ?? null) && $options['reference'] !== '' ? $options['reference'] : null,
            'opening' => $opening, 'closing' => $closing ?? ($opening === null ? null : (string) BigDecimal::of($opening)->plus($total)),
            'starts_on' => Sources::date($options['starts_on'] ?? null) ?? ($dates[0] ?? null), 'ends_on' => Sources::date($options['ends_on'] ?? null) ?? ($dates === [] ? null : $dates[count($dates) - 1]),
            'count' => count($clean), 'money_in' => (string) $this->sum($clean, true), 'money_out' => (string) $this->sum($clean, false),
            'skipped' => is_int($options['skipped'] ?? null) ? $options['skipped'] : 0];
        $summary['reference'] === null ? $errors['_'][] = 'Provide a statement reference.' : null;
        foreach ($dates as $date) {
            if (($summary['starts_on'] !== null && $date < $summary['starts_on']) || ($summary['ends_on'] !== null && $date > $summary['ends_on'])) {
                $errors['_'][] = 'Movements fall outside the statement period.';

                break;
            }
        }

        return new ImportPreview(ImportKind::BankStatement, $clean, $this->unique($errors), $summary, $headers);
    }

    private function submitStatement(ImportPreview $preview): string
    {
        $summary = $preview->summary;
        $currency = $this->db->currency($this->string($summary, 'currency'));
        $lines = array_map(fn (array $row): StatementLine => new StatementLine($this->string($row, 'external_id'), $this->string($row, 'value_on'),
            Money::of($this->string($row, 'amount'), $currency), $this->string($row, 'description')), $preview->rows);

        return $this->banking->import(new StatementRequest($this->string($summary, 'book_id'), $this->string($summary, 'account_id'), $this->string($summary, 'reference'),
            $this->string($summary, 'starts_on'), $this->string($summary, 'ends_on'), Money::of($this->string($summary, 'opening'), $currency),
            Money::of($this->string($summary, 'closing'), $currency), $lines));
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @param  list<string>  $headers
     */
    private function checkParties(array $rows, array $options, array $headers): ImportPreview
    {
        $entity = $this->text($options, 'entity_id');
        $this->db->find('ledger_entities', $entity);
        $existing = $this->db->table('ledger_parties')->where('entity_id', $entity)->whereIn('reference', array_values(array_filter(array_map(fn (array $row): ?string => is_scalar($row['reference'] ?? null) ? trim((string) $row['reference']) : null, $rows))))->get()->keyBy('reference');
        /** @var array<int|string, list<string>> $errors */
        $errors = [];
        $seen = [];
        $clean = [];
        foreach ($rows as $index => $row) {
            $reference = is_scalar($row['reference'] ?? null) ? trim((string) $row['reference']) : '';
            $name = is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '';
            $status = 'new';
            ($reference === '' || strlen($reference) > 128) ? $errors[$index][] = 'A reference of up to 128 characters is required.' : null;
            ($name === '' || strlen($name) > 256) ? $errors[$index][] = 'A name of up to 256 characters is required.' : null;
            isset($seen[$reference]) ? $errors[$index][] = 'Reference '.$reference.' repeats in this file.' : null;
            $current = $existing->get($reference);
            if ($current instanceof stdClass) {
                $status = 'existing';
                Row::text($current, 'name') !== $name ? $errors[$index][] = 'Reference '.$reference.' already belongs to '.Row::text($current, 'name').'; rename it instead.' : null;
                Row::nullable($current, 'deleted_at') !== null ? $errors[$index][] = 'Reference '.$reference.' is retired; restore it first.' : null;
            }
            $seen[$reference] = true;
            $clean[] = ['reference' => $reference, 'name' => $name, 'status' => $status];
        }

        return new ImportPreview(ImportKind::Parties, $clean, $this->unique($errors), $this->counts($clean), $headers);
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @param  list<string>  $headers
     */
    private function checkAccounts(array $rows, array $options, array $headers): ImportPreview
    {
        $book = $this->text($options, 'book_id');
        $this->db->find('ledger_books', $book);
        $existing = $this->db->table('ledger_accounts')->where('book_id', $book)->get()->keyBy('code');
        $codes = [];
        foreach ($rows as $row) {
            if (is_scalar($row['code'] ?? null)) {
                $codes[trim((string) $row['code'])] = $row;
            }
        }
        /** @var array<int|string, list<string>> $errors */
        $errors = [];
        $seen = [];
        $clean = [];
        foreach ($rows as $index => $row) {
            $code = is_scalar($row['code'] ?? null) ? trim((string) $row['code']) : '';
            $name = is_scalar($row['name'] ?? null) ? trim((string) $row['name']) : '';
            $type = AccountType::tryFrom(strtolower(is_scalar($row['type'] ?? null) ? trim((string) $row['type']) : ''));
            $parent = is_scalar($row['parent'] ?? null) && trim((string) $row['parent']) !== '' ? trim((string) $row['parent']) : null;
            $side = is_scalar($row['normal_side'] ?? null) && trim((string) $row['normal_side']) !== '' ? Side::tryFrom(strtolower(trim((string) $row['normal_side']))) : null;
            $cashFlow = is_scalar($row['cash_flow'] ?? null) && trim((string) $row['cash_flow']) !== '' ? strtolower(trim((string) $row['cash_flow'])) : null;
            $currency = is_scalar($row['currency'] ?? null) && trim((string) $row['currency']) !== '' ? strtoupper(trim((string) $row['currency'])) : null;
            $item = ['code' => $code, 'name' => $name, 'type' => $type?->value, 'parent' => $parent, 'group' => $this->flag($row['group'] ?? null),
                'control' => $this->flag($row['control'] ?? null), 'normal_side' => ($side ?? $type?->normalSide())?->value, 'cash_flow' => $cashFlow, 'currency' => $currency, 'status' => 'new'];
            ($code === '' || strlen($code) > 64) ? $errors[$index][] = 'An account code of up to 64 characters is required.' : null;
            ($name === '' || strlen($name) > 256) ? $errors[$index][] = 'An account name of up to 256 characters is required.' : null;
            $type === null ? $errors[$index][] = 'Type must be asset, liability, equity, income or expense.' : null;
            (is_scalar($row['normal_side'] ?? null) && trim((string) $row['normal_side']) !== '' && $side === null) ? $errors[$index][] = 'Normal side must be debit or credit.' : null;
            ($cashFlow !== null && ! in_array($cashFlow, ['cash', 'operating', 'investing', 'financing'], true)) ? $errors[$index][] = 'Cash-flow class must be cash, operating, investing or financing.' : null;
            if ($currency !== null) {
                try {
                    Currency::iso($currency);
                } catch (Throwable) {
                    $errors[$index][] = 'Unknown currency '.$currency.'.';
                }
            }
            isset($seen[$code]) ? $errors[$index][] = 'Account '.$code.' repeats in this file.' : null;
            if ($parent !== null && ! isset($codes[$parent]) && ! $existing->has($parent)) {
                $errors[$index][] = 'Parent '.$parent.' is neither in the file nor in the chart.';
            }
            $current = $existing->get($code);
            if ($current instanceof stdClass && $type !== null) {
                $item['status'] = 'existing';
                if (Row::text($current, 'type') !== $type->value || Row::boolean($current, 'is_group') !== $item['group'] || Row::boolean($current, 'is_control') !== $item['control']) {
                    $errors[$index][] = 'Account '.$code.' already exists with a different classification.';
                }
            }
            $seen[$code] = true;
            $clean[] = $item;
        }

        return new ImportPreview(ImportKind::Accounts, $clean, $this->unique($errors), $this->counts($clean), $headers);
    }

    /** @return list<string> */
    private function submitAccounts(ImportPreview $preview, string $book): array
    {
        $ids = [];
        foreach ($this->db->table('ledger_accounts')->where('book_id', $book)->get() as $account) {
            $ids[Row::text($account, 'code')] = Row::text($account, 'id');
        }
        $pending = array_values(array_filter($preview->rows, fn (array $row): bool => ($row['status'] ?? null) === 'new'));
        $created = [];
        while ($pending !== []) {
            $next = [];
            foreach ($pending as $row) {
                $parent = is_string($row['parent'] ?? null) ? $row['parent'] : null;
                if ($parent !== null && ! isset($ids[$parent])) {
                    $next[] = $row;

                    continue;
                }
                $currency = is_string($row['currency'] ?? null) ? Currency::iso($row['currency']) : null;
                $cashFlow = is_string($row['cash_flow'] ?? null) ? $row['cash_flow'] : null;
                $id = $this->catalog->account($book, $this->string($row, 'code'), $this->string($row, 'name'), AccountType::from($this->string($row, 'type')), $currency,
                    $parent === null ? null : $ids[$parent], $row['group'] === true, $row['control'] === true, Side::from($this->string($row, 'normal_side')), $cashFlow);
                $ids[$this->string($row, 'code')] = $id;
                $created[] = $id;
            }
            if (count($next) === count($pending)) {
                throw new LedgerException('Account parents form a cycle.');
            }
            $pending = $next;
        }

        return $created;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  array<string, mixed>  $options
     * @param  list<string>  $headers
     */
    private function checkJournals(array $rows, array $options, array $headers): ImportPreview
    {
        $bookId = $this->text($options, 'book_id');
        $book = $this->db->find('ledger_books', $bookId);
        $functional = $this->db->currency(Row::text($book, 'currency_code'));
        $accounts = $this->db->table('ledger_accounts')->where('book_id', $bookId)->get()->keyBy('code');
        $parties = $this->db->table('ledger_parties')->where('entity_id', Row::text($book, 'entity_id'))->whereNull('deleted_at')->pluck('id', 'reference');
        $imported = $this->db->table('ledger_journals')->where('book_id', $bookId)->where('source_type', 'import')->pluck('source_reference')->all();
        /** @var array<int|string, list<string>> $errors */
        $errors = [];
        $clean = [];
        $groups = [];
        foreach ($rows as $index => $row) {
            $journal = is_scalar($row['journal'] ?? null) ? trim((string) $row['journal']) : '';
            $date = Sources::date($row['date'] ?? null);
            $code = is_scalar($row['account'] ?? null) ? trim((string) $row['account']) : '';
            $debit = Sources::amount($row['debit'] ?? null);
            $credit = Sources::amount($row['credit'] ?? null);
            $account = $accounts->get($code);
            $currencyCode = is_scalar($row['currency'] ?? null) && trim((string) $row['currency']) !== '' ? strtoupper(trim((string) $row['currency']))
                : ($account instanceof stdClass ? (Row::nullable($account, 'currency_code') ?? $functional->code) : $functional->code);
            $party = is_scalar($row['party'] ?? null) && trim((string) $row['party']) !== '' ? trim((string) $row['party']) : null;
            $dimensions = $this->dimensions($row['dimensions'] ?? null);
            ($journal === '' || strlen($journal) > 100) ? $errors[$index][] = 'A journal reference of up to 100 characters groups the lines.' : null;
            in_array($journal, $imported, true) ? $errors[$index][] = 'Journal '.$journal.' was already imported.' : null;
            $date === null ? $errors[$index][] = 'Invalid date.' : null;
            ! $account instanceof stdClass ? $errors[$index][] = 'Account '.$code.' is not in the chart.' : (Row::boolean($account, 'is_group') ? $errors[$index][] = 'Account '.$code.' is a group account.' : null);
            $positive = fn (?string $value): bool => $value !== null && BigDecimal::of($value)->isPositive();
            ($positive($debit) === $positive($credit)) ? $errors[$index][] = 'Each line needs exactly one positive debit or credit.' : null;
            ($party !== null && ! $parties->has($party)) ? $errors[$index][] = 'Party '.$party.' is not in this entity.' : null;
            ($account instanceof stdClass && Row::boolean($account, 'is_control') && $party === null) ? $errors[$index][] = 'Control account '.$code.' needs a party.' : null;
            $dimensions === null ? $errors[$index][] = 'Dimensions use name=value pairs separated by semicolons.' : null;
            $dueOn = Sources::date($row['due_on'] ?? null);
            $rate = $currencyCode === $functional->code ? '1' : Sources::amount($row['rate'] ?? null);
            ($rate === null || BigDecimal::of($rate)->isNegativeOrZero()) ? $errors[$index][] = 'Foreign-currency lines need a positive exchange rate.' : null;
            [$movement, $movementError] = $this->reportingMovement($row);
            $movementError !== null ? $errors[$index][] = $movementError : null;
            $item = ['journal' => $journal, 'date' => $date ?? $row['date'] ?? null, 'description' => is_scalar($row['description'] ?? null) && trim((string) $row['description']) !== '' ? trim((string) $row['description']) : 'Imported journal '.$journal,
                'account' => $code, 'debit' => $positive($debit) ? $debit : null, 'credit' => $positive($credit) ? $credit : null, 'currency' => $currencyCode, 'rate' => $rate,
                'party' => $party, 'due_on' => $dueOn, 'dimensions' => $dimensions ?? [], 'line_description' => is_scalar($row['line_description'] ?? null) ? trim((string) $row['line_description']) : '',
                'reporting_kind' => $movement?->kind, 'reporting_policy' => $movement?->policyVersion, 'reporting_reference' => $movement?->reference, 'oci_category' => $movement?->ociCategory];
            $clean[] = $item;
            $groups[$journal][] = $index;
        }
        foreach ($groups as $journal => $indexes) {
            $first = $indexes[0];
            $dates = array_unique(array_map(fn (int $index): string => $this->string($clean[$index], 'date'), $indexes));
            count($dates) !== 1 ? $errors[$first][] = 'All lines of journal '.$journal.' must share one date.' : null;
            count($indexes) < 2 ? $errors[$first][] = 'Journal '.$journal.' needs at least two lines.' : null;
            if (array_intersect($indexes, array_keys($errors)) !== []) {
                continue;
            }
            $balance = BigDecimal::zero();
            foreach ($indexes as $index) {
                $line = $clean[$index];
                $currency = $this->db->currency($this->string($line, 'currency'));
                $amount = Money::of($line['debit'] !== null ? $this->string($line, 'debit') : $this->string($line, 'credit'), $currency);
                $value = $currency == $functional ? $amount : (new ExchangeRate($currency, $functional, $this->string($line, 'rate'), $this->string($line, 'date'), 'import', Rounding::HalfEven))->convert($amount);
                $balance = $line['debit'] !== null ? $balance->plus($value->decimal()) : $balance->minus($value->decimal());
            }
            ! $balance->isZero() ? $errors[$first][] = 'Journal '.$journal.' is out of balance by '.$balance.' '.$functional->code.'.' : null;
        }
        $summary = ['journals' => count($groups), 'lines' => count($clean), 'book_id' => $bookId];

        return new ImportPreview(ImportKind::Journals, $clean, $this->unique($errors), $summary, $headers);
    }

    /** @return list<string> */
    private function submitJournals(ImportPreview $preview, string $bookId): array
    {
        $accounts = $this->db->table('ledger_accounts')->where('book_id', $bookId)->pluck('id', 'code');
        $book = $this->db->find('ledger_books', $bookId);
        $parties = $this->db->table('ledger_parties')->where('entity_id', Row::text($book, 'entity_id'))->pluck('id', 'reference');
        $functional = $this->db->currency(Row::text($book, 'currency_code'));
        $groups = [];
        foreach ($preview->rows as $row) {
            $groups[$this->string($row, 'journal')][] = $row;
        }
        uasort($groups, fn (array $a, array $b): int => strcmp($this->string($a[0], 'date'), $this->string($b[0], 'date')));
        $created = [];
        foreach ($groups as $journal => $lines) {
            $postings = [];
            foreach ($lines as $line) {
                $currency = $this->db->currency($this->string($line, 'currency'));
                $amount = Money::of($line['debit'] !== null ? $this->string($line, 'debit') : $this->string($line, 'credit'), $currency);
                $party = is_string($line['party'] ?? null) ? $parties->get($line['party']) : null;
                $dimensions = [];
                foreach (is_array($line['dimensions'] ?? null) ? $line['dimensions'] : [] as $name => $label) {
                    if (is_string($name) && is_scalar($label)) {
                        $dimensions[$name] = (string) $label;
                    }
                }
                $accountId = $accounts->get($this->string($line, 'account'));
                $postings[] = new Posting(is_string($accountId) ? $accountId : '', $line['debit'] !== null ? Side::Debit : Side::Credit, $amount,
                    $currency == $functional ? null : new ExchangeRate($currency, $functional, $this->string($line, 'rate'), $this->string($line, 'date'), 'import', Rounding::HalfEven),
                    is_string($party) ? $party : null, is_string($line['due_on'] ?? null) ? $line['due_on'] : null, $dimensions, is_string($line['line_description'] ?? null) ? $line['line_description'] : '',
                    reportingMovement: $this->reportingMovement($line)[0]);
            }
            $created[] = $this->journals->post(new JournalRequest($bookId, 'import:'.hash('sha256', (string) $journal), $this->string($lines[0], 'date'),
                $this->string($lines[0], 'description'), $postings, source: new Source('import', (string) $journal)))->id;
        }

        return $created;
    }

    /** @param list<array<string, mixed>> $rows
     * @param  list<string>  $headers
     */
    private function checkRates(array $rows, array $headers): ImportPreview
    {
        /** @var array<int|string, list<string>> $errors */
        $errors = [];
        $clean = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $base = strtoupper(is_scalar($row['base'] ?? null) ? trim((string) $row['base']) : '');
            $quote = strtoupper(is_scalar($row['quote'] ?? null) ? trim((string) $row['quote']) : '');
            $type = RateType::tryFrom(strtolower(is_scalar($row['type'] ?? null) && trim((string) $row['type']) !== '' ? trim((string) $row['type']) : 'spot'));
            $date = Sources::date($row['date'] ?? null);
            $rate = Sources::amount($row['rate'] ?? null);
            $source = is_scalar($row['source'] ?? null) && trim((string) $row['source']) !== '' ? trim((string) $row['source']) : 'import';
            $status = 'new';
            foreach ([$base, $quote] as $code) {
                try {
                    Currency::iso($code);
                } catch (Throwable) {
                    $errors[$index][] = 'Unknown currency '.$code.'.';
                }
            }
            $base === $quote ? $errors[$index][] = 'Base and quote currencies must differ.' : null;
            $type === null ? $errors[$index][] = 'Rate type must be spot, closing, average or historical.' : null;
            $date === null ? $errors[$index][] = 'Invalid date.' : null;
            ($rate === null || BigDecimal::of($rate)->isNegativeOrZero()) ? $errors[$index][] = 'A positive rate is required.' : null;
            $key = $base.$quote.$type?->value.$date;
            isset($seen[$key]) ? $errors[$index][] = 'This rate repeats in the file.' : null;
            $seen[$key] = true;
            if ($type !== null && $date !== null && $rate !== null && ! isset($errors[$index])) {
                $current = $this->db->table('ledger_exchange_rates')->where('base_code', $base)->where('quote_code', $quote)->where('rate_type', $type->value)
                    ->where('effective_on', $date)->orderByDesc('revision')->first();
                if ($current instanceof stdClass) {
                    $status = 'existing';
                    ! BigDecimal::of(Row::text($current, 'rate'))->isEqualTo($rate) || Row::text($current, 'source') !== $source
                        ? $errors[$index][] = 'A different '.$type->value.' rate exists for '.$date.'; correct it explicitly.' : null;
                }
            }
            $clean[] = ['base' => $base, 'quote' => $quote, 'type' => $type?->value, 'date' => $date, 'rate' => $rate, 'source' => $source, 'status' => $status];
        }

        return new ImportPreview(ImportKind::ExchangeRates, $clean, $this->unique($errors), $this->counts($clean), $headers);
    }

    /** @param array<string, mixed> $row
     * @return array{?ReportingMovement, ?string}
     */
    private function reportingMovement(array $row): array
    {
        $kind = $this->present($row['reporting_kind'] ?? null);
        $policy = $this->present($row['reporting_policy'] ?? null);
        $reference = $this->present($row['reporting_reference'] ?? null);
        $category = $this->present($row['oci_category'] ?? null);
        if ($kind === null && $policy === null && $reference === null && $category === null) {
            return [null, null];
        }
        if (in_array($kind, ['recycling', 'basis_adjustment', 'earnings_transfer'], true)) {
            return [null, 'Reporting movement '.$kind.' cannot be imported; use recycleOci() or the year-end close.'];
        }
        try {
            return [new ReportingMovement($kind ?? '', $policy ?? '', $reference ?? '', $category), null];
        } catch (LedgerException) {
            return [null, 'A reporting movement needs a supported kind, a policy version, a reference and an OCI category only for OCI kinds.'];
        }
    }

    /** @return array<string, string>|null */
    private function dimensions(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $name => $label) {
                if (! is_string($name) || ! is_scalar($label)) {
                    return null;
                }
                $result[$name] = trim((string) $label);
            }

            return $result;
        }
        if (! is_string($value)) {
            return null;
        }
        $result = [];
        foreach (array_filter(array_map('trim', explode(';', $value))) as $pair) {
            if (! str_contains($pair, '=')) {
                return null;
            }
            [$name, $label] = array_map('trim', explode('=', $pair, 2));
            $result[$name] = $label;
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sum(array $rows, bool $positive): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($rows as $row) {
            $amount = $row['amount'] ?? null;
            if (is_string($amount) && ($positive ? BigDecimal::of($amount)->isPositive() : BigDecimal::of($amount)->isNegative())) {
                $total = $total->plus($amount);
            }
        }

        return $total;
    }

    private function present(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function flag(mixed $value): bool
    {
        return in_array(is_scalar($value) ? strtolower(trim((string) $value)) : '', ['1', 'true', 'yes', 'y'], true);
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function counts(array $rows): array
    {
        $counts = ['new' => 0, 'existing' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] ?? 'new';
            $counts[is_string($status) && isset($counts[$status]) ? $status : 'new']++;
        }

        return $counts;
    }

    /** @param array<int|string, list<string>> $errors
     * @return array<int|string, list<string>>
     */
    private function unique(array $errors): array
    {
        return array_map(fn (array $messages): array => array_values(array_unique($messages)), $errors);
    }

    /** @param array<string, mixed> $options */
    private function bookOption(array $options): ?string
    {
        return is_string($options['book_id'] ?? null) ? $options['book_id'] : null;
    }

    /** @param array<string, mixed> $options */
    private function text(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new LedgerException('The import option '.$key.' is required.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
