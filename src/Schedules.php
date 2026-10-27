<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\Journal;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Accounting\Source;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Scheduling\ScheduleLine;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Schedules
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    /** @param non-empty-list<ScheduleLine> $lines
     * @param  array<string, string>  $dimensions
     */
    public function create(string $bookId, string $key, string $description, string $debitAccountId, string $creditAccountId,
        array $lines, string $policyVersion, array $dimensions = []): string
    {
        Assert::text($key, 128);
        Assert::text($description, 1000);
        Assert::text($policyVersion, 128);
        Assert::that($lines !== [], 'A schedule needs at least one line.');

        return $this->db->run(Permission::Configure, function () use ($bookId, $key, $description, $debitAccountId, $creditAccountId, $lines, $policyVersion, $dimensions): string {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $total = Money::minor('0', $currency);
            $previous = '';

            foreach ($lines as $line) {
                Assert::that($line->dueOn > $previous, 'Schedule dates must be unique and increasing.');
                $previous = $line->dueOn;
                $total = $total->plus($line->amount);
            }

            Assert::that($total->minor->isPositive(), 'A schedule needs a positive total.');
            $fingerprint = Canonical::hash([$description, $debitAccountId, $creditAccountId, $lines, $policyVersion, $dimensions]);
            $existing = $this->db->table('ledger_schedules')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Schedule key was already used with different content.');

                return Row::text($existing, 'id');
            }

            $id = (string) Str::uuid();
            $this->db->insert('ledger_schedules', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'description' => $description, 'debit_account_id' => $debitAccountId, 'credit_account_id' => $creditAccountId,
                'amount_minor' => (string) $total->minor, 'plan' => Canonical::json($lines), 'policy_version' => $policyVersion,
                'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions)]);

            return $id;
        }, $bookId);
    }

    /** @return list<Journal> */
    public function postDue(string $bookId, string $scheduleId, string $throughDate): array
    {
        Assert::date($throughDate);

        return $this->db->run(Permission::Post, function () use ($bookId, $scheduleId, $throughDate): array {
            $book = $this->db->lockBook($bookId);
            $schedule = $this->db->find('ledger_schedules', $scheduleId, $bookId);
            Assert::that(! $this->db->table('ledger_schedule_cancellations')->where('book_id', $bookId)->where('schedule_id', $scheduleId)->exists(), 'Schedule was cancelled.');
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $dimensions = [];

            foreach (Row::json($schedule, 'dimensions') as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $dimensions[$key] = $value;
                }
            }

            $journals = [];

            foreach (array_values(Row::json($schedule, 'plan')) as $index => $value) {
                Assert::that(is_array($value), 'Invalid stored schedule.');
                $line = (object) $value;
                $date = Row::text($line, 'due_on');
                $amount = Money::minor(Row::text($line, 'amount_minor'), $currency);

                if ($date > $throughDate || $amount->minor->isZero() || $this->db->table('ledger_schedule_runs')->where('book_id', $bookId)->where('schedule_id', $scheduleId)->where('line_no', $index + 1)->exists()) {
                    continue;
                }

                $journal = $this->journals->post(new JournalRequest($bookId, 'schedule:'.$scheduleId.':'.($index + 1), $date, Row::text($schedule, 'description'), [
                    new Posting(Row::text($schedule, 'debit_account_id'), Side::Debit, $amount, dimensions: $dimensions),
                    new Posting(Row::text($schedule, 'credit_account_id'), Side::Credit, $amount, dimensions: $dimensions),
                ], ['schedule_id' => $scheduleId, 'line_no' => $index + 1, 'policy_version' => Row::text($schedule, 'policy_version')],
                    source: new Source('schedule:'.$scheduleId, (string) ($index + 1))));
                $this->db->insert('ledger_schedule_runs', ['id' => (string) Str::uuid(), 'book_id' => $bookId, 'schedule_id' => $scheduleId, 'line_no' => $index + 1, 'journal_id' => $journal->id]);
                $journals[] = $journal;
            }

            return $journals;
        }, $bookId);
    }

    public function cancel(string $bookId, string $scheduleId, string $reason): void
    {
        Assert::text($reason, 1000);
        $this->db->run(Permission::Configure, function () use ($bookId, $scheduleId, $reason): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_schedules', $scheduleId, $bookId);
            $existing = $this->db->table('ledger_schedule_cancellations')->where('book_id', $bookId)->where('schedule_id', $scheduleId)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'reason') === $reason, 'Schedule was cancelled with another reason.');

                return;
            }

            $this->db->insert('ledger_schedule_cancellations', ['id' => (string) Str::uuid(), 'book_id' => $bookId, 'schedule_id' => $scheduleId, 'reason' => $reason]);
        }, $bookId);
    }
}
