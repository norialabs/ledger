<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Statutory\Adapter;
use Noria\Ledger\Statutory\Outcome;
use Noria\Ledger\Statutory\Submission;
use Noria\Ledger\Statutory\SubmissionRequest;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;
use Throwable;

readonly class Statutory
{
    public function __construct(private ScopedDatabase $db) {}

    public function enqueue(SubmissionRequest $request): string
    {
        return $this->db->run(Permission::Deliver, function () use ($request): string {
            $this->db->lockBook($request->bookId);
            $fingerprint = Canonical::hash($request);
            $existing = $this->replay('ledger_statutory_submissions', $request->bookId, $request->key, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_statutory_submissions', ['id' => $id, 'book_id' => $request->bookId, 'idempotency_key' => $request->key, 'fingerprint' => $fingerprint,
                'kind' => $request->kind, 'journal_id' => $request->kind === 'tax_return' ? null : $request->sourceId,
                'return_id' => $request->kind === 'tax_return' ? $request->sourceId : null, 'original_id' => $request->originalId, 'replaces_id' => $request->replacesId,
                'channel' => $request->channel, 'environment' => $request->environment, 'adapter' => $request->adapter, 'adapter_version' => $request->adapterVersion,
                'request_reference' => $request->requestReference, 'content_type' => $request->contentType, 'payload' => $request->payload, 'max_attempts' => $request->maxAttempts]);

            return $id;
        }, $request->bookId);
    }

    public function claim(string $bookId, string $submissionId, string $key, int $leaseSeconds = 60, ?string $adapter = null, ?string $adapterVersion = null): ?Submission
    {
        $this->outsideTransaction();
        Assert::text($key, 128);
        Assert::that($leaseSeconds >= 1 && $leaseSeconds <= 900, 'Invalid statutory worker lease.');

        return $this->db->run(Permission::Deliver, function () use ($bookId, $submissionId, $key, $leaseSeconds, $adapter, $adapterVersion): ?Submission {
            $this->db->lockBook($bookId);
            $definition = $this->db->find('ledger_statutory_submissions', $submissionId, $bookId);
            Assert::that(($adapter === null || Row::text($definition, 'adapter') === $adapter) && ($adapterVersion === null || Row::text($definition, 'adapter_version') === $adapterVersion), 'Statutory adapter identity differs from the pinned submission.');
            $fingerprint = Canonical::hash([$submissionId, 'claim', $leaseSeconds]);
            $existing = $this->replay('ledger_statutory_events', $bookId, $key, $fingerprint);
            $state = $this->state($bookId, $submissionId);
            if ($existing !== null) {
                return null;
            }
            if (($state['ready'] ?? false) !== true) {
                return null;
            }
            $mode = $state['mode'] ?? null;
            Assert::that(is_string($mode), 'Statutory worker mode is missing.');
            if ($mode === 'send') {
                $sendable = $this->db->connection->selectOne('SELECT ledger_statutory_sendable(?::uuid, ?::uuid, ?::uuid) AS allowed', [$this->db->scope->tenantId, $bookId, $submissionId]);
                if (! $sendable instanceof stdClass || ! Row::boolean($sendable, 'allowed')) {
                    return null;
                }
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_statutory_events', ['id' => $id, 'book_id' => $bookId, 'submission_id' => $submissionId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'kind' => 'claim', 'mode' => $mode, 'lease_seconds' => $leaseSeconds,
                'evidence' => '{}', 'reason' => 'Claim statutory delivery work']);

            return $this->submission($definition, $this->db->find('ledger_statutory_events', $id, $bookId), $this->state($bookId, $submissionId));
        }, $bookId);
    }

    public function resolve(string $bookId, string $submissionId, string $claimId, string $key, Outcome $outcome): string
    {
        $this->outsideTransaction();
        Assert::text($key, 128);
        Assert::uuid($claimId);

        return $this->db->run(Permission::Deliver, function () use ($bookId, $submissionId, $claimId, $key, $outcome): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$submissionId, $claimId, $outcome->status, $outcome->reason, $outcome->evidence, $outcome->receiptReference, $outcome->delaySeconds]);
            $existing = $this->replay('ledger_statutory_events', $bookId, $key, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_statutory_events', ['id' => $id, 'book_id' => $bookId, 'submission_id' => $submissionId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'kind' => $outcome->status, 'claim_id' => $claimId,
                'receipt_reference' => $outcome->receiptReference, 'delay_seconds' => $outcome->delaySeconds,
                'evidence' => $outcome->evidence === [] ? '{}' : Canonical::json($outcome->evidence), 'reason' => $outcome->reason]);

            return $id;
        }, $bookId);
    }

    public function dispatch(string $bookId, string $submissionId, Adapter $adapter, int $leaseSeconds = 60): ?string
    {
        $this->outsideTransaction();
        $claim = $this->claim($bookId, $submissionId, 'dispatch:'.Str::uuid7(), $leaseSeconds, $adapter->name(), $adapter->version());
        if ($claim === null) {
            return null;
        }
        if ($claim->mode === 'send') {
            try {
                $adapter->validate($claim);
            } catch (Throwable $exception) {
                return $this->resolve($bookId, $submissionId, $claim->claimId, 'validation:'.$claim->claimId,
                    new Outcome('rejected', 'Adapter rejected the immutable payload before transmission', ['error_type' => $exception::class]));
            }
        }
        try {
            $outcome = $claim->mode === 'lookup' ? $adapter->lookup($claim) : $adapter->submit($claim);
            Assert::that($claim->mode === 'lookup' ? $outcome->status !== 'retry' : $outcome->status !== 'not_found', 'Adapter outcome is incompatible with its operation.');
        } catch (Throwable $exception) {
            $outcome = new Outcome('uncertain', 'Transport outcome is unknown; reconcile before resending', ['error_type' => $exception::class], delaySeconds: 30);
        }

        return $this->resolve($bookId, $submissionId, $claim->claimId, 'outcome:'.$claim->claimId, $outcome);
    }

    public function cancel(string $bookId, string $submissionId, string $key, string $reason): string
    {
        Assert::text($key, 128);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Deliver, function () use ($bookId, $submissionId, $key, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$submissionId, 'cancelled', $reason]);
            $existing = $this->replay('ledger_statutory_events', $bookId, $key, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_statutory_events', ['id' => $id, 'book_id' => $bookId, 'submission_id' => $submissionId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'kind' => 'cancelled', 'evidence' => '{}', 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @return list<stdClass> */
    public function pending(string $bookId, int $limit = 100): array
    {
        Assert::that($limit >= 1 && $limit <= 1000, 'Invalid statutory work page size.');

        return $this->db->run(Permission::Deliver, function () use ($bookId, $limit): array {
            $this->db->find('ledger_books', $bookId);
            $rows = $this->db->connection->select("SELECT s.id, s.channel, s.environment, state.value FROM ledger_statutory_submissions s CROSS JOIN LATERAL (SELECT ledger_statutory_state(s.tenant_id, s.book_id, s.id) AS value) state WHERE s.tenant_id = ?::uuid AND s.book_id = ?::uuid AND (state.value->>'ready')::boolean AND (state.value->>'mode' = 'lookup' OR ledger_statutory_sendable(s.tenant_id, s.book_id, s.id)) ORDER BY s.sequence LIMIT ?::integer",
                [$this->db->scope->tenantId, $bookId, $limit]);
            $result = [];
            foreach ($rows as $row) {
                if ($row instanceof stdClass) {
                    $result[] = $row;
                }
            }

            return $result;
        }, $bookId);
    }

    /** @return array{definition: stdClass, state: array<string, mixed>, source: array<string, mixed>, events: list<stdClass>} */
    public function evidence(string $bookId, string $submissionId, int $limit = 500, string $afterSequence = '0'): array
    {
        Assert::that($limit >= 1 && $limit <= 10000 && preg_match('/^[0-9]{1,19}$/D', $afterSequence) === 1
            && BigInteger::of($afterSequence)->isLessThanOrEqualTo('9223372036854775807'), 'Invalid statutory history page.');

        return $this->db->run(Permission::Read, function () use ($bookId, $submissionId, $limit, $afterSequence): array {
            $definition = $this->db->find('ledger_statutory_submissions', $submissionId, $bookId);

            return ['definition' => $definition, 'state' => $this->state($bookId, $submissionId), 'source' => $this->source($definition),
                'events' => array_values($this->db->table('ledger_statutory_events')->where('book_id', $bookId)->where('submission_id', $submissionId)
                    ->where('sequence', '>', $afterSequence)->orderBy('sequence')->limit($limit)->get()->all())];
        }, $bookId);
    }

    /** @param array<string, mixed> $state */
    private function submission(stdClass $definition, stdClass $event, array $state): Submission
    {
        $source = $this->source($definition);
        $receipt = null;
        $original = Row::nullable($definition, 'original_id');
        if ($original !== null) {
            $value = $this->state(Row::text($definition, 'book_id'), $original)['receipt_reference'] ?? null;
            $receipt = is_string($value) ? $value : null;
        }
        $attempts = $state['attempts'] ?? null;
        Assert::that(is_int($attempts), 'Statutory attempt count is invalid.');

        return new Submission($this->db->scope->tenantId, Row::text($definition, 'book_id'), Row::text($definition, 'id'), Row::text($event, 'id'), Row::text($event, 'mode'),
            Row::text($definition, 'channel'), Row::text($definition, 'environment'), Row::text($definition, 'adapter'), Row::text($definition, 'adapter_version'),
            Row::text($definition, 'request_reference'), Row::text($definition, 'content_type'), Row::text($definition, 'payload'), Row::text($definition, 'payload_hash'),
            Row::text($definition, 'source_hash'), $source, $receipt, is_int($attempts) ? $attempts : 0);
    }

    /** @return array<string, mixed> */
    private function source(stdClass $definition): array
    {
        Assert::that(hash_equals(Row::text($definition, 'payload_hash'), hash('sha256', Row::text($definition, 'payload'))), 'Statutory payload hash does not match.');
        $row = $this->db->connection->selectOne('SELECT ledger_statutory_source(?::uuid, ?::uuid, ?::uuid, ?::uuid)::text AS source',
            [$this->db->scope->tenantId, Row::text($definition, 'book_id'), Row::nullable($definition, 'journal_id'), Row::nullable($definition, 'return_id')]);
        Assert::that($row instanceof stdClass, 'Statutory source evidence is missing.');
        if (! $row instanceof stdClass) {
            throw new \LogicException('Missing statutory source.');
        }
        Assert::that(hash_equals(Row::text($definition, 'source_hash'), hash('sha256', Row::text($row, 'source'))), 'Statutory source hash does not match.');

        return Row::jsonObject($row, 'source');
    }

    /** @return array<string, mixed> */
    private function state(string $bookId, string $submissionId): array
    {
        $row = $this->db->connection->selectOne('SELECT ledger_statutory_state(?::uuid, ?::uuid, ?::uuid)::text AS state', [$this->db->scope->tenantId, $bookId, $submissionId]);
        Assert::that($row instanceof stdClass, 'Statutory state is missing.');

        return $row instanceof stdClass ? Row::jsonObject($row, 'state') : [];
    }

    private function outsideTransaction(): void
    {
        Assert::that($this->db->connection->transactionLevel() === 0, 'Statutory workers must run after the outermost transaction commits.');
    }

    private function replay(string $table, string $bookId, string $key, string $fingerprint): ?string
    {
        $row = $this->db->table($table)->where('book_id', $bookId)->where('idempotency_key', $key)->first();
        if (! $row instanceof stdClass) {
            return null;
        }
        Assert::that(Row::text($row, 'fingerprint') === $fingerprint, 'Statutory retry key already contains different inputs.');

        return Row::text($row, 'id');
    }
}
