<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Catalog\Period;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\RateType;
use Noria\Ledger\Scheduling\Recurrence;
use Noria\Ledger\Scheduling\ScheduleLine;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Schedules
{
    public function __construct(private ScopedDatabase $db, private Journals $journals, private ?Rates $rates = null) {}

    /** @param non-empty-list<ScheduleLine> $lines
     * @param  array<string, string>  $dimensions
     */
    public function create(string $bookId, string $key, string $description, string $debitAccountId, string $creditAccountId,
        array $lines, string $policyVersion, array $dimensions = []): string
    {
        Assert::text($key, 128);
        Assert::text($description, 1024);
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

            $id = (string) Str::uuid7();
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
                $this->db->insert('ledger_schedule_runs', ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'schedule_id' => $scheduleId, 'line_no' => $index + 1, 'journal_id' => $journal->id]);
                $journals[] = $journal;
            }

            return $journals;
        }, $bookId);
    }

    public function cancel(string $bookId, string $scheduleId, string $reason): void
    {
        Assert::text($reason, 1024);
        $this->db->run(Permission::Configure, function () use ($bookId, $scheduleId, $reason): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_schedules', $scheduleId, $bookId);
            $existing = $this->db->table('ledger_schedule_cancellations')->where('book_id', $bookId)->where('schedule_id', $scheduleId)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'reason') === $reason, 'Schedule was cancelled with another reason.');

                return;
            }

            $this->db->insert('ledger_schedule_cancellations', ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'schedule_id' => $scheduleId, 'reason' => $reason]);
        }, $bookId);
    }

    /** @param non-empty-list<Posting> $postings
     * @param  array<string, mixed>  $metadata
     */
    public function recurring(string $bookId, string $key, string $description, array $postings, Recurrence $recurrence, bool $autoReverse = false,
        RateType $rateType = RateType::Spot, ?int $dueAfterDays = null, array $metadata = []): string
    {
        Assert::text($key, 128);
        Assert::text($description, 1024);
        Assert::that(count($postings) >= 2 && count($postings) <= 10000, 'A recurring journal requires between 2 and 10,000 ordered postings.');
        Assert::that($dueAfterDays === null || ($dueAfterDays >= 0 && $dueAfterDays <= 3660), 'Due offset must be between 0 and 3,660 days.');
        Canonical::json($metadata);
        $lines = [];
        foreach ($postings as $posting) {
            Assert::that($posting->exchangeRate === null && $posting->valuation === null && $posting->dueOn === null,
                'Recurring templates resolve rates and due dates per occurrence; omit them from template postings.');
            $lines[] = ['account_id' => $posting->accountId, 'side' => $posting->side->value, 'amount_minor' => (string) $posting->amount->minor,
                'currency_code' => $posting->amount->currency->code, 'party_id' => $posting->partyId, 'dimensions' => (object) $posting->dimensions,
                'description' => $posting->description];
        }

        return $this->db->run(Permission::Configure, function () use ($bookId, $key, $description, $postings, $lines, $recurrence, $autoReverse, $rateType, $dueAfterDays, $metadata): string {
            $this->db->lockBook($bookId);
            foreach ($postings as $posting) {
                Assert::that($this->db->currency($posting->amount->currency->code) == $posting->amount->currency, 'Posting currency scale differs from the registered scale.');
            }
            $fingerprint = Canonical::hash([$description, $postings, $recurrence, $autoReverse, $rateType->value, $dueAfterDays, $metadata]);
            $existing = $this->db->table('ledger_recurring_journals')->where('book_id', $bookId)->where('idempotency_key', $key)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Recurring journal key was already used with different content.');

                return Row::text($existing, 'id');
            }
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_recurring_journals', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'description' => $description, 'postings' => json_encode($lines, JSON_THROW_ON_ERROR), 'metadata' => $metadata === [] ? '{}' : Canonical::json($metadata),
                'rate_type' => $rateType->value, 'unit' => $recurrence->unit, 'every' => $recurrence->every, 'starts_on' => $recurrence->startsOn,
                'ends_on' => $recurrence->endsOn, 'occurrences' => $recurrence->occurrences, 'due_after_days' => $dueAfterDays, 'auto_reverse' => $autoReverse]);

            return $id;
        }, $bookId);
    }

    /** @param non-empty-list<Posting> $postings
     * @param  array<string, mixed>  $metadata
     */
    public function accrue(string $bookId, string $key, string $effectiveOn, string $description, array $postings, RateType $rateType = RateType::Spot, array $metadata = []): Journal
    {
        return $this->db->run(Permission::Post, function () use ($bookId, $key, $effectiveOn, $description, $postings, $rateType, $metadata): Journal {
            $id = $this->recurring($bookId, $key, $description, $postings, Recurrence::once($effectiveOn), true, $rateType, metadata: $metadata);
            $this->postRecurring($bookId, $effectiveOn, $id);
            $run = $this->db->table('ledger_recurring_runs')->where('book_id', $bookId)->where('recurring_id', $id)->where('kind', 'post')->first();
            Assert::that($run instanceof stdClass, 'Accrual was not posted.');

            return $this->journals->get($bookId, $run instanceof stdClass ? Row::text($run, 'journal_id') : '');
        }, $bookId);
    }

    /** @return list<Journal> */
    public function postRecurring(string $bookId, string $throughDate, ?string $recurringId = null): array
    {
        Assert::date($throughDate);

        return $this->db->run(Permission::Post, function () use ($bookId, $throughDate, $recurringId): array {
            $book = $this->db->lockBook($bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $query = $this->db->table('ledger_recurring_journals')->where('book_id', $bookId)->where('starts_on', '<=', $throughDate)->orderBy('starts_on')->orderBy('id');
            if ($recurringId !== null) {
                $query->where('id', Assert::uuid($recurringId));
            }
            $journals = [];
            foreach ($query->get() as $template) {
                array_push($journals, ...$this->postOccurrences($bookId, $template, $throughDate, $functional));
            }

            return $journals;
        }, $bookId);
    }

    /** @return list<array{recurring_id: string, occurrence: int, kind: string, due_on: string}> */
    public function dueRecurring(string $bookId, string $throughDate): array
    {
        Assert::date($throughDate);

        return $this->db->run(Permission::Read, function () use ($bookId, $throughDate): array {
            $this->db->find('ledger_books', $bookId);
            $due = [];
            foreach ($this->db->table('ledger_recurring_journals')->where('book_id', $bookId)->where('starts_on', '<=', $throughDate)->orderBy('starts_on')->orderBy('id')->get() as $template) {
                [$recurrence, $cancellation, $runs] = $this->recurringState($bookId, $template);
                $id = Row::text($template, 'id');
                for ($occurrence = 0; $recurrence->includes($occurrence); $occurrence++) {
                    $date = $recurrence->dateOf($occurrence);
                    if ($date > $throughDate || (is_string($cancellation) && $date >= $cancellation)) {
                        break;
                    }
                    if (! isset($runs['post'][$occurrence])) {
                        $due[] = ['recurring_id' => $id, 'occurrence' => $occurrence, 'kind' => 'post', 'due_on' => $date];
                    }
                }
                if (! Row::boolean($template, 'auto_reverse')) {
                    continue;
                }
                foreach ($runs['post'] ?? [] as $occurrence => $journalId) {
                    if (isset($runs['reverse'][$occurrence])) {
                        continue;
                    }
                    $reversesOn = (new \DateTimeImmutable($this->periodOf($bookId, $this->journals->get($bookId, $journalId)->effectiveOn)->endsOn))->modify('+1 day')->format('Y-m-d');
                    if ($reversesOn <= $throughDate) {
                        $due[] = ['recurring_id' => $id, 'occurrence' => $occurrence, 'kind' => 'reverse', 'due_on' => $reversesOn];
                    }
                }
            }

            return $due;
        }, $bookId);
    }

    /** @return list<array{schedule_id: string, line_no: int, due_on: string}> */
    public function dueSchedules(string $bookId, string $throughDate): array
    {
        Assert::date($throughDate);

        return $this->db->run(Permission::Read, function () use ($bookId, $throughDate): array {
            $this->db->find('ledger_books', $bookId);
            $rows = $this->db->connection->select(<<<'SQL'
                SELECT s.id AS schedule_id, l.ordinality::integer AS line_no, l.value->>'due_on' AS due_on
                FROM ledger_schedules s CROSS JOIN LATERAL jsonb_array_elements(s.plan) WITH ORDINALITY l
                WHERE s.tenant_id = ? AND s.book_id = ? AND (l.value->>'due_on')::date <= ? AND (l.value->>'amount_minor')::numeric > 0
                  AND NOT EXISTS (SELECT 1 FROM ledger_schedule_cancellations c WHERE c.tenant_id = s.tenant_id AND c.book_id = s.book_id AND c.schedule_id = s.id)
                  AND NOT EXISTS (SELECT 1 FROM ledger_schedule_runs r WHERE r.tenant_id = s.tenant_id AND r.book_id = s.book_id AND r.schedule_id = s.id AND r.line_no = l.ordinality)
                ORDER BY 3, 1, 2
                SQL, [$this->db->scope->tenantId, $bookId, $throughDate]);
            $due = [];
            foreach ($rows as $row) {
                if ($row instanceof stdClass) {
                    $due[] = ['schedule_id' => Row::text($row, 'schedule_id'), 'line_no' => (int) Row::text($row, 'line_no'), 'due_on' => Row::text($row, 'due_on')];
                }
            }

            return $due;
        }, $bookId);
    }

    public function cancelRecurring(string $bookId, string $recurringId, string $effectiveOn, string $reason): void
    {
        Assert::date($effectiveOn);
        Assert::text($reason, 1024);
        $this->db->run(Permission::Configure, function () use ($bookId, $recurringId, $effectiveOn, $reason): void {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_recurring_journals', $recurringId, $bookId);
            $existing = $this->db->table('ledger_recurring_cancellations')->where('book_id', $bookId)->where('recurring_id', $recurringId)->first();
            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'reason') === $reason && Row::text($existing, 'effective_on') === $effectiveOn, 'Recurring journal was cancelled differently.');

                return;
            }
            $this->db->insert('ledger_recurring_cancellations', ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'recurring_id' => $recurringId,
                'effective_on' => $effectiveOn, 'reason' => $reason]);
        }, $bookId);
    }

    /** @return list<Journal> */
    private function postOccurrences(string $bookId, stdClass $template, string $throughDate, Currency $functional): array
    {
        $id = Row::text($template, 'id');
        [$recurrence, $cancellation, $runs] = $this->recurringState($bookId, $template);
        $journals = [];
        for ($occurrence = 0; $recurrence->includes($occurrence); $occurrence++) {
            $date = $recurrence->dateOf($occurrence);
            if ($date > $throughDate || (is_string($cancellation) && $date >= $cancellation)) {
                break;
            }
            if (isset($runs['post'][$occurrence])) {
                continue;
            }
            $journal = $this->journals->post(new JournalRequest($bookId, 'recurring:'.$id.':'.$occurrence, $date, Row::text($template, 'description'),
                $this->occurrencePostings($template, $date, $functional), [...Row::jsonObject($template, 'metadata'), 'recurring_id' => $id, 'occurrence' => $occurrence],
                source: new Source('recurring:'.$id, (string) $occurrence)));
            $this->db->insert('ledger_recurring_runs', ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'recurring_id' => $id, 'occurrence' => $occurrence, 'kind' => 'post', 'journal_id' => $journal->id]);
            $runs['post'][$occurrence] = $journal->id;
            $journals[] = $journal;
        }
        if (! Row::boolean($template, 'auto_reverse')) {
            return $journals;
        }
        ksort($runs['post']);
        foreach ($runs['post'] as $occurrence => $journalId) {
            if (isset($runs['reverse'][$occurrence])) {
                continue;
            }
            $period = $this->periodOf($bookId, $this->journals->get($bookId, $journalId)->effectiveOn);
            $reversesOn = (new \DateTimeImmutable($period->endsOn))->modify('+1 day')->format('Y-m-d');
            if ($reversesOn > $throughDate) {
                continue;
            }
            $reversal = $this->journals->reverse($bookId, $journalId, 'recurring:'.$id.':'.$occurrence.':reverse', $reversesOn, 'Automatic reversal: '.Row::text($template, 'description'));
            $this->db->insert('ledger_recurring_runs', ['id' => (string) Str::uuid7(), 'book_id' => $bookId, 'recurring_id' => $id, 'occurrence' => $occurrence, 'kind' => 'reverse', 'journal_id' => $reversal->id]);
            $journals[] = $reversal;
        }

        return $journals;
    }

    /** @return array{Recurrence, mixed, array<string, array<int, string>>} */
    private function recurringState(string $bookId, stdClass $template): array
    {
        $id = Row::text($template, 'id');
        $occurrences = Row::nullable($template, 'occurrences');
        $recurrence = new Recurrence(Row::text($template, 'unit'), (int) Row::text($template, 'every'), Row::text($template, 'starts_on'),
            Row::nullable($template, 'ends_on'), $occurrences === null ? null : (int) $occurrences);
        $cancellation = $this->db->table('ledger_recurring_cancellations')->where('book_id', $bookId)->where('recurring_id', $id)->value('effective_on');
        $runs = [];
        foreach ($this->db->table('ledger_recurring_runs')->where('book_id', $bookId)->where('recurring_id', $id)->get() as $run) {
            $runs[Row::text($run, 'kind')][(int) Row::text($run, 'occurrence')] = Row::text($run, 'journal_id');
        }

        return [$recurrence, $cancellation, $runs];
    }

    /** @return list<Posting> */
    private function occurrencePostings(stdClass $template, string $date, Currency $functional): array
    {
        $dueAfter = Row::nullable($template, 'due_after_days');
        $postings = [];
        foreach (Row::json($template, 'postings') as $line) {
            Assert::that(is_array($line), 'Invalid stored recurring template.');
            $line = (object) $line;
            $currency = $this->db->currency(Row::text($line, 'currency_code'));
            $rate = null;
            if ($currency != $functional) {
                Assert::that($this->rates !== null, 'Foreign-currency recurring journals require the rates service.');
                $rate = $this->rates?->find($currency, $functional, RateType::from(Row::text($template, 'rate_type')), $date);
            }
            $party = Row::nullable($line, 'party_id');
            $dimensions = [];
            foreach ((array) ($line->dimensions ?? []) as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $dimensions[$name] = $value;
                }
            }
            $postings[] = new Posting(Row::text($line, 'account_id'), Side::from(Row::text($line, 'side')), Money::minor(Row::text($line, 'amount_minor'), $currency), $rate,
                $party, $party !== null && $dueAfter !== null ? (new \DateTimeImmutable($date))->modify('+'.$dueAfter.' days')->format('Y-m-d') : null,
                $dimensions, Row::text($line, 'description'));
        }

        return $postings;
    }

    private function periodOf(string $bookId, string $date): Period
    {
        $row = $this->db->table('ledger_periods')->where('book_id', $bookId)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
        Assert::that($row instanceof stdClass, 'No accounting period covers '.$date.'.');

        return Period::fromRow($row instanceof stdClass ? $row : new stdClass);
    }
}
