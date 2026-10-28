<?php

declare(strict_types=1);

namespace Noria\Ledger\Imports;

use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMXPath;
use Noria\Ledger\Exceptions\LedgerException;
use Spatie\SimpleExcel\SimpleExcelReader;

final class Sources
{
    public const MAX_ROWS = 100000;

    /** @param array<string, list<string>> $aliases
     * @param  array<string, mixed>  $options
     * @return array{rows: list<array<string, mixed>>, headers: list<string>}
     */
    public static function table(string $path, ImportFormat $format, array $aliases, array $options = []): array
    {
        self::readable($path);
        if ($format === ImportFormat::Json) {
            return self::json($path);
        }
        $reader = SimpleExcelReader::create($path, $format === ImportFormat::Csv || ($format === ImportFormat::Mpesa && self::isCsv($path)) ? 'csv' : 'xlsx')->noHeaderRow();
        $delimiter = $options['delimiter'] ?? null;
        if (is_string($delimiter) && $delimiter !== '') {
            $reader->useDelimiter($delimiter);
        }
        $sheet = $options['sheet'] ?? null;
        if (is_string($sheet) && $sheet !== '') {
            $reader->fromSheetName($sheet);
        }
        $known = [];
        foreach ($aliases as $canonical => $names) {
            foreach ([$canonical, ...$names] as $name) {
                $known[self::label($name)] = true;
            }
        }
        $headers = null;
        $rows = [];
        foreach ($reader->getRows() as $values) {
            $cells = array_values(array_map(self::cell(...), is_array($values) ? $values : []));
            if ($headers === null) {
                $labels = array_map(fn (mixed $value): string => is_string($value) ? self::label($value) : '', $cells);
                if (count(array_filter($labels, fn (string $label): bool => isset($known[$label]))) >= 2) {
                    $headers = array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', $cells);
                }

                continue;
            }
            if (array_filter($cells, fn (mixed $value): bool => $value !== null && $value !== '') === []) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $row[$header] = $cells[$index] ?? null;
                }
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw new LedgerException('Imports are limited to '.self::MAX_ROWS.' rows per file.');
            }
        }
        $reader->close();
        if ($headers === null) {
            throw new LedgerException('No header row with recognizable columns was found.');
        }

        return ['rows' => $rows, 'headers' => array_values(array_filter($headers, fn (string $header): bool => $header !== ''))];
    }

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>} */
    public static function statement(string $path, ImportFormat $format): array
    {
        self::readable($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new LedgerException('Cannot read the import file.');
        }

        return match ($format) {
            ImportFormat::Mt940 => self::mt940($content),
            ImportFormat::Camt053 => self::camt053($content),
            default => throw new LedgerException('Unsupported structured statement format.'),
        };
    }

    public static function label(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    public static function date(mixed $value, ?string $format = null): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach ($format !== null ? [$format] : ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd.m.Y', 'j M Y', 'd M Y', 'd-M-Y', 'Ymd'] as $candidate) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$candidate, $value);
            if ($parsed !== false && $parsed->format($candidate) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    public static function amount(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = is_float($value) ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') : (string) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $text = str_replace([',', ' ', "\u{00A0}"], '', trim($value));
        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        $text = preg_replace('/^[A-Za-z]{3}|[A-Za-z]{3}$|[()]/', '', $text) ?? '';
        if (preg_match('/^[+-]?\d+(\.\d+)?$/D', $text) !== 1) {
            return null;
        }
        $text = ltrim($text, '+');

        return $negative ? '-'.ltrim($text, '-') : $text;
    }

    private static function cell(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || is_float($value)) {
            return self::amount($value);
        }

        return is_string($value) ? trim($value) : ($value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : null));
    }

    /** @return array{rows: list<array<string, mixed>>, headers: list<string>} */
    private static function json(string $path): array
    {
        $content = file_get_contents($path);
        $data = json_decode($content === false ? '' : $content, true, 64, JSON_THROW_ON_ERROR);
        $rows = is_array($data) && array_is_list($data) ? $data : (is_array($data) && is_array($data['rows'] ?? null) ? $data['rows'] : null);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_ROWS) {
            throw new LedgerException('JSON imports contain a list of row objects, optionally under "rows".');
        }
        $result = [];
        $headers = [];
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new LedgerException('Every JSON import row must be an object.');
            }
            $clean = [];
            foreach ($row as $key => $value) {
                $clean[(string) $key] = is_scalar($value) ? (is_string($value) ? trim($value) : self::cell($value)) : $value;
                $headers[(string) $key] = true;
            }
            $result[] = $clean;
        }

        return ['rows' => $result, 'headers' => array_keys($headers)];
    }

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>} */
    private static function mt940(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (preg_match_all('/^:20:/m', $content) > 1) {
            throw new LedgerException('Split multi-statement MT940 files and import one statement at a time.');
        }
        preg_match_all('/^:(\d{2}[A-Z]?):(.*?)(?=^:\d{2}[A-Z]?:|^-\}|\z)/ms', $content, $fields, PREG_SET_ORDER);
        $summary = ['currency' => null];
        $rows = [];
        foreach ($fields as $field) {
            [$tag, $value] = [$field[1], trim($field[2])];
            if ($tag === '20') {
                $summary['reference'] = $value;
            } elseif ($tag === '25') {
                $summary['account'] = $value;
            } elseif (in_array($tag, ['60F', '60M', '62F', '62M'], true)) {
                if (preg_match('/^([CD])(\d{6})([A-Z]{3})([\d,]+)/', $value, $balance) !== 1) {
                    throw new LedgerException('Malformed MT940 balance field :'.$tag.':');
                }
                $amount = ($balance[1] === 'D' ? '-' : '').str_replace(',', '.', rtrim($balance[4], ','));
                $summary['currency'] = $balance[3];
                $summary[str_starts_with($tag, '60') ? 'opening' : 'closing'] = self::trimAmount($amount);
                $summary[str_starts_with($tag, '60') ? 'starts_on' : 'ends_on'] = self::yymmdd($balance[2]);
            } elseif ($tag === '61') {
                $lines = explode("\n", $value);
                if (preg_match('/^(\d{6})(\d{4})?(R?[CD])([A-Z])?([\d,]+)([A-Z]\w{3})([^\/]*)(?:\/\/(\S*))?/', $lines[0], $entry) !== 1) {
                    throw new LedgerException('Malformed MT940 statement line: '.$lines[0]);
                }
                $credit = in_array($entry[3], ['C', 'RD'], true);
                $amount = ($credit ? '' : '-').str_replace(',', '.', rtrim($entry[5], ','));
                $customer = trim($entry[7]);
                $bank = trim($entry[8] ?? '');
                $date = self::yymmdd($entry[1]);
                $rows[] = ['external_id' => $bank !== '' ? $bank : ($customer !== '' && $customer !== 'NONREF' ? $customer : $date.'-'.(count($rows) + 1)),
                    'value_on' => $date, 'amount' => self::trimAmount($amount), 'description' => trim(implode(' ', array_slice($lines, 1))) ?: $entry[6].' '.$customer,
                    'account_reference' => $customer !== 'NONREF' ? $customer : null];
            } elseif ($tag === '86' && $rows !== []) {
                $rows[array_key_last($rows)]['description'] = trim((string) preg_replace('/\s+/', ' ', $value));
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>} */
    private static function camt053(string $content): array
    {
        $document = new DOMDocument;
        if (! @$document->loadXML($content, LIBXML_NONET)) {
            throw new LedgerException('The CAMT.053 file is not valid XML.');
        }
        $xpath = new DOMXPath($document);
        $statements = self::nodes($xpath, '//*[local-name()="Stmt"]', $document);
        if (count($statements) !== 1) {
            throw new LedgerException('A CAMT.053 import must contain exactly one statement.');
        }
        $statement = $statements[0];
        $summary = ['reference' => self::text($xpath, './*[local-name()="Id"]', $statement),
            'account' => self::text($xpath, './*[local-name()="Acct"]//*[local-name()="IBAN" or local-name()="Id"]', $statement), 'currency' => null];
        foreach (self::nodes($xpath, './*[local-name()="Bal"]', $statement) as $balance) {
            $code = self::text($xpath, './/*[local-name()="Cd"]', $balance);
            $amount = self::nodes($xpath, './*[local-name()="Amt"]', $balance)[0] ?? null;
            if ($amount === null || ! in_array($code, ['OPBD', 'PRCD', 'CLBD'], true)) {
                continue;
            }
            $sign = self::text($xpath, './*[local-name()="CdtDbtInd"]', $balance) === 'DBIT' ? '-' : '';
            $summary['currency'] = $amount instanceof \DOMElement ? $amount->getAttribute('Ccy') : null;
            $summary[$code === 'CLBD' ? 'closing' : 'opening'] = self::trimAmount($sign.trim($amount->textContent));
            $summary[$code === 'CLBD' ? 'ends_on' : 'starts_on'] = self::date(self::text($xpath, './*[local-name()="Dt"]/*', $balance));
        }
        $rows = [];
        foreach (self::nodes($xpath, './*[local-name()="Ntry"]', $statement) as $index => $entry) {
            $sign = self::text($xpath, './*[local-name()="CdtDbtInd"]', $entry) === 'DBIT' ? '-' : '';
            $reference = self::text($xpath, './*[local-name()="AcctSvcrRef"]', $entry) ?? self::text($xpath, './*[local-name()="NtryRef"]', $entry)
                ?? self::text($xpath, './/*[local-name()="EndToEndId"]', $entry);
            $rows[] = ['external_id' => $reference ?? 'entry-'.($index + 1),
                'value_on' => self::date(self::text($xpath, './*[local-name()="ValDt"]/*', $entry) ?? self::text($xpath, './*[local-name()="BookgDt"]/*', $entry)),
                'amount' => self::trimAmount($sign.(self::text($xpath, './*[local-name()="Amt"]', $entry) ?? '0')),
                'description' => self::text($xpath, './/*[local-name()="Ustrd"]', $entry) ?? self::text($xpath, './*[local-name()="AddtlNtryInf"]', $entry) ?? 'Bank entry',
                'counterparty' => self::text($xpath, './/*[local-name()="RltdPties"]//*[local-name()="Nm"]', $entry),
                'account_reference' => self::text($xpath, './/*[local-name()="EndToEndId"]', $entry)];
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @return list<\DOMNode> */
    private static function nodes(DOMXPath $xpath, string $query, \DOMNode $context): array
    {
        $result = $xpath->query($query, $context);
        $nodes = [];
        foreach ($result === false ? [] : $result as $node) {
            if ($node instanceof \DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private static function text(DOMXPath $xpath, string $query, \DOMNode $context): ?string
    {
        $node = self::nodes($xpath, $query, $context)[0] ?? null;

        return $node === null ? null : trim($node->textContent);
    }

    private static function yymmdd(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!ymd', $value);
        if ($date === false) {
            throw new LedgerException('Invalid statement date '.$value);
        }

        return $date->format('Y-m-d');
    }

    private static function trimAmount(string $amount): string
    {
        return self::amount($amount) ?? throw new LedgerException('Invalid statement amount '.$amount);
    }

    private static function readable(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new LedgerException('The import file cannot be read.');
        }
    }

    private static function isCsv(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'txt'], true);
    }
}
