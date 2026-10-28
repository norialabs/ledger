<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Taxes
{
    public function __construct(private ScopedDatabase $db, private Configurations $configurations) {}

    /** @return list<stdClass> */
    public function register(Snapshot $snapshot, string $startsOn = '0001-01-01', int $limit = 500, string $afterSequence = '0', int $afterLine = 0, string $afterCode = ''): array
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn && $limit >= 1 && $limit <= 10000 && $afterLine >= 0
            && preg_match('/^[0-9]{1,19}$/D', $afterSequence) === 1 && BigInteger::of($afterSequence)->isLessThanOrEqualTo('9223372036854775807'), 'Invalid tax register page.');
        Assert::that(strlen($afterCode) <= 64, 'Tax register code cursor is too long.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $startsOn, $limit, $afterSequence, $afterLine, $afterCode): array {
            $this->db->snapshotBook($snapshot);
            $rows = $this->db->connection->select('SELECT * FROM ledger_tax_register(?::uuid, ?::uuid, ?::date, ?::bigint) WHERE effective_on >= ?::date AND (sequence, line_no, tax_code) > (?::bigint, ?::integer, ?) ORDER BY sequence, line_no, tax_code LIMIT ?::integer',
                [$this->db->scope->tenantId, $snapshot->bookId, $snapshot->effectiveOn, $snapshot->throughSequence, $startsOn, $afterSequence, $afterLine, $afterCode, $limit]);
            $result = [];
            foreach ($rows as $row) {
                Assert::that($row instanceof stdClass, 'Invalid tax register row.');
                $result[] = $row;
            }

            return $result;
        }, $snapshot->bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function decide(string $bookId, string $key, string $configurationId, string $scheme, string $effectiveOn, string $journalId, int $line, string $taxCode,
        string $kind, string $fraction, string $reason, array $evidence = []): string
    {
        Assert::that(in_array($kind, ['claim', 'defer', 'deny'], true), 'Unknown tax claim decision.');
        Assert::decimal($fraction);

        return $this->decision($bookId, $key, $configurationId, $scheme, $effectiveOn, $journalId, $line, $taxCode, $kind, $fraction, null, $reason, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function reconcile(string $bookId, string $key, string $configurationId, string $scheme, string $effectiveOn, string $journalId, int $postingLine,
        string $classification, string $reason, array $evidence = []): string
    {
        Assert::text($classification, 64);

        return $this->decision($bookId, $key, $configurationId, $scheme, $effectiveOn, $journalId, $postingLine, '', 'reconcile', '0', $classification, $reason, $evidence);
    }

    /** @return list<stdClass> */
    public function decisions(Snapshot $snapshot, string $scheme, ?string $journalId = null, int $limit = 500, string $afterSequence = '0'): array
    {
        Assert::text($scheme, 128);
        Assert::that($limit >= 1 && $limit <= 10000 && preg_match('/^[0-9]{1,19}$/D', $afterSequence) === 1
            && BigInteger::of($afterSequence)->isLessThanOrEqualTo('9223372036854775807'), 'Invalid tax decision page.');
        if ($journalId !== null) {
            Assert::uuid($journalId);
        }

        return $this->db->run(Permission::Read, function () use ($snapshot, $scheme, $journalId, $limit, $afterSequence): array {
            $this->db->snapshotBook($snapshot);
            $query = $this->db->table('ledger_tax_decisions')->where('book_id', $snapshot->bookId)->where('scheme', $scheme)
                ->where('effective_on', '<=', $snapshot->effectiveOn)->where('sequence', '<=', $snapshot->throughSequence)->where('sequence', '>', $afterSequence);
            if ($journalId !== null) {
                $query->where('journal_id', $journalId);
            }

            return array_values($query->orderBy('sequence')->limit($limit)->get()->all());
        }, $snapshot->bookId);
    }

    /** @return array<string, mixed> */
    public function preview(Snapshot $snapshot, string $configurationId, string $scheme, string $startsOn, string $openingCredit = '0'): array
    {
        Assert::date($startsOn);
        Assert::text($scheme, 128);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Tax return starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $configurationId, $scheme, $startsOn, $openingCredit): array {
            $book = $this->db->snapshotBook($snapshot);
            $this->configurations->get($snapshot->bookId, $configurationId, $startsOn);
            $this->configurations->get($snapshot->bookId, $configurationId, $snapshot->effectiveOn);
            $credit = Money::of($openingCredit, $this->db->currency(Row::text($book, 'currency_code')));
            Assert::that(! $credit->minor->isNegative(), 'Opening tax credit cannot be negative.');

            return $this->result($snapshot, $configurationId, $scheme, $startsOn, (string) $credit->minor);
        }, $snapshot->bookId);
    }

    public function prepare(Snapshot $snapshot, string $key, string $configurationId, string $scheme, string $startsOn, ?string $previousId = null,
        ?string $priorPeriodId = null, string $openingCredit = '0', ?string $openingReference = null): string
    {
        Assert::text($key, 128);
        Assert::text($scheme, 128);
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Tax return starts after it ends.');
        if ($openingReference !== null) {
            Assert::text($openingReference, 256);
        }

        return $this->db->run(Permission::Tax, function () use ($snapshot, $key, $configurationId, $scheme, $startsOn, $previousId, $priorPeriodId, $openingCredit, $openingReference): string {
            $this->db->lockBook($snapshot->bookId);
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $credit = Money::of($openingCredit, $currency);
            Assert::that(! $credit->minor->isNegative(), 'Opening tax credit cannot be negative.');
            if ($priorPeriodId !== null) {
                Assert::that($credit->minor->isZero() && $openingReference === null, 'A preceding return supplies its own opening-credit evidence.');
                $prior = $this->db->find('ledger_tax_returns', $priorPeriodId, $snapshot->bookId);
                $credit = Money::minor(Row::text((object) Row::json($prior, 'result_json'), 'credit_carried_minor'), $currency);
            }
            $fingerprint = Canonical::hash([$snapshot, $configurationId, $scheme, $startsOn, $previousId, $priorPeriodId, $credit, $openingReference]);
            $replay = $this->replay('ledger_tax_returns', $snapshot->bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $this->configurations->get($snapshot->bookId, $configurationId, $startsOn);
            $this->configurations->get($snapshot->bookId, $configurationId, $snapshot->effectiveOn);
            $result = $this->resultJson($snapshot, $configurationId, $scheme, $startsOn, (string) $credit->minor);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_tax_returns', ['id' => $id, 'book_id' => $snapshot->bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'configuration_id' => $configurationId, 'scheme' => $scheme, 'starts_on' => $startsOn, 'effective_on' => $snapshot->effectiveOn,
                'through_sequence' => $snapshot->throughSequence, 'previous_id' => $previousId, 'prior_period_id' => $priorPeriodId,
                'opening_credit_minor' => (string) $credit->minor, 'opening_reference' => $openingReference, 'result_json' => $result]);

            return $id;
        }, $snapshot->bookId);
    }

    /** @param array<string, mixed> $evidence */
    public function finalize(string $bookId, string $returnId, string $key, string $effectiveOn, string $reason, array $evidence = []): string
    {
        return $this->event($bookId, $returnId, $key, $effectiveOn, 'finalize', $reason, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    public function file(string $bookId, string $returnId, string $key, string $effectiveOn, string $receiptReference, string $reason, array $evidence = []): string
    {
        Assert::text($receiptReference, 256);

        return $this->event($bookId, $returnId, $key, $effectiveOn, 'file', $reason, [...$evidence, 'receipt_reference' => $receiptReference]);
    }

    public function void(string $bookId, string $returnId, string $key, string $effectiveOn, string $reason): string
    {
        return $this->event($bookId, $returnId, $key, $effectiveOn, 'void', $reason, []);
    }

    /** @return array{definition: stdClass, result_json: string, result_hash: string, result: array<string, mixed>, status: string, events: list<stdClass>} */
    public function evidence(string $bookId, string $returnId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $returnId): array {
            $definition = $this->db->find('ledger_tax_returns', $returnId, $bookId);
            $json = Row::text($definition, 'result_json');
            $hash = Row::text($definition, 'result_hash');
            Assert::that(hash_equals($hash, hash('sha256', $json)), 'Tax return evidence hash does not match.');
            $events = array_values($this->db->table('ledger_tax_return_events')->where('book_id', $bookId)->where('return_id', $returnId)->orderBy('sequence')->get()->all());
            $last = $events === [] ? null : $events[array_key_last($events)];
            $status = $last === null ? 'prepared' : match (Row::text($last, 'kind')) {
                'finalize' => 'finalized', 'file' => 'filed', 'void' => 'voided', default => throw new \LogicException('Unknown tax return event.'),
            };

            return ['definition' => $definition, 'result_json' => $json, 'result_hash' => $hash, 'result' => Row::jsonObject($definition, 'result_json'), 'status' => $status, 'events' => $events];
        }, $bookId);
    }

    /** @param array<string, mixed> $evidence */
    private function decision(string $bookId, string $key, string $configurationId, string $scheme, string $effectiveOn, string $journalId, int $line, string $code,
        string $kind, string $fraction, ?string $classification, string $reason, array $evidence): string
    {
        Assert::text($key, 128);
        Assert::text($scheme, 128);
        Assert::date($effectiveOn);
        Assert::uuid($journalId);
        Assert::text($reason, 1024);
        Assert::that($line > 0 && strlen($code) <= 64, 'Invalid tax decision source identity.');

        return $this->db->run(Permission::Tax, function () use ($bookId, $key, $configurationId, $scheme, $effectiveOn, $journalId, $line, $code, $kind, $fraction, $classification, $reason, $evidence): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$configurationId, $scheme, $effectiveOn, $journalId, $line, $code, $kind, $fraction, $classification, $reason, $evidence]);
            $replay = $this->replay('ledger_tax_decisions', $bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $this->configurations->get($bookId, $configurationId, $effectiveOn);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_tax_decisions', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'configuration_id' => $configurationId, 'scheme' => $scheme, 'effective_on' => $effectiveOn, 'journal_id' => $journalId, 'line_no' => $line,
                'tax_code' => $code, 'kind' => $kind, 'fraction' => $fraction, 'classification' => $classification, 'reason' => $reason,
                'evidence' => $evidence === [] ? '{}' : Canonical::json($evidence)]);

            return $id;
        }, $bookId);
    }

    /** @param array<string, mixed> $evidence */
    private function event(string $bookId, string $returnId, string $key, string $effectiveOn, string $kind, string $reason, array $evidence): string
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Tax, function () use ($bookId, $returnId, $key, $effectiveOn, $kind, $reason, $evidence): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$returnId, $effectiveOn, $kind, $reason, $evidence]);
            $replay = $this->replay('ledger_tax_return_events', $bookId, $key, $fingerprint);
            if ($replay !== null) {
                return $replay;
            }
            $this->db->find('ledger_tax_returns', $returnId, $bookId);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_tax_return_events', ['id' => $id, 'book_id' => $bookId, 'return_id' => $returnId, 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'effective_on' => $effectiveOn, 'kind' => $kind, 'reason' => $reason, 'evidence' => $evidence === [] ? '{}' : Canonical::json($evidence)]);

            return $id;
        }, $bookId);
    }

    /** @return array<string, mixed> */
    private function result(Snapshot $snapshot, string $configurationId, string $scheme, string $startsOn, string $openingMinor): array
    {
        return Row::jsonObject((object) ['result' => $this->resultJson($snapshot, $configurationId, $scheme, $startsOn, $openingMinor)], 'result');
    }

    private function resultJson(Snapshot $snapshot, string $configurationId, string $scheme, string $startsOn, string $openingMinor): string
    {
        $row = $this->db->connection->selectOne('SELECT ledger_tax_return_result(?::uuid, ?::uuid, ?::uuid, ?, ?::date, ?::date, ?::bigint, ?::numeric)::text AS result',
            [$this->db->scope->tenantId, $snapshot->bookId, $configurationId, $scheme, $startsOn, $snapshot->effectiveOn, $snapshot->throughSequence, $openingMinor]);
        Assert::that($row instanceof stdClass, 'Tax return result is missing.');

        return Row::text($row, 'result');
    }

    private function replay(string $table, string $bookId, string $key, string $fingerprint): ?string
    {
        $row = $this->db->table($table)->where('book_id', $bookId)->where('idempotency_key', $key)->first();
        if (! $row instanceof stdClass) {
            return null;
        }
        Assert::that(Row::text($row, 'fingerprint') === $fingerprint, 'Tax retry key already contains different inputs.');

        return Row::text($row, 'id');
    }
}
