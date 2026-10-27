<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\Journal;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Accounting\Source;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Reporting\Snapshot;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Canonical;
use NoriaLabs\Ledger\Support\Row;
use stdClass;

final readonly class Revaluations
{
    public function __construct(private ScopedDatabase $db, private Journals $journals, private Reports $reports) {}

    public function revalue(string $bookId, string $itemId, string $key, string $effectiveOn, ExchangeRate $rate, string $gainAccountId, string $lossAccountId, string $reason): ?Journal
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1000);

        return $this->db->run(Permission::Revalue, function () use ($bookId, $itemId, $key, $effectiveOn, $rate, $gainAccountId, $lossAccountId, $reason): ?Journal {
            $book = $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$itemId, $effectiveOn, $rate, $gainAccountId, $lossAccountId, $reason]);
            $existing = $this->db->table('ledger_revaluations')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Revaluation key was already used with different content.');

                return $this->journal($bookId, Row::nullable($existing, 'journal_id'));
            }

            $item = null;

            foreach ($this->reports->openItems(new Snapshot($bookId, $effectiveOn, Row::text($book, 'next_sequence'))) as $candidate) {
                if ($candidate->id === $itemId) {
                    $item = $candidate;
                    break;
                }
            }

            Assert::that($item !== null, 'Revaluation requires an outstanding open item.');

            if ($item === null) {
                return null;
            }

            Assert::that($rate->to == $item->functionalOutstanding->currency && $rate->from == $item->outstanding->currency && $rate->from != $rate->to && $rate->asOf <= $effectiveOn, 'Revaluation requires an explicit foreign-to-functional rate dated on or before the valuation.');
            $delta = $rate->convert($item->outstanding)->minus($item->functionalOutstanding);
            $rawDelta = $delta->minor->multipliedBy($item->side->sign());
            $id = (string) Str::uuid();
            $journal = null;

            if (! $rawDelta->isZero()) {
                $gain = $rawDelta->isPositive();
                $counterId = $gain ? $gainAccountId : $lossAccountId;
                $counter = $this->db->find('ledger_accounts', $counterId, $bookId);
                Assert::that(Row::text($counter, 'type') === ($gain ? 'income' : 'expense'), 'Revaluation requires the correct unrealized gain or loss account.');
                $posting = $this->db->table('ledger_postings')->where('book_id', $bookId)->where('journal_id', $item->journalId)->where('line_no', $item->lineNo)->first();
                $dimensions = [];

                if ($posting instanceof stdClass) {
                    foreach (Row::json($posting, 'dimensions') as $dimension => $value) {
                        if (is_string($dimension) && is_string($value)) {
                            $dimensions[$dimension] = $value;
                        }
                    }
                }

                $value = Money::minor((string) $rawDelta->abs(), $rate->to);
                $journal = $this->journals->post(new JournalRequest($bookId, 'revalue:'.hash('sha256', $key), $effectiveOn, $reason, [
                    new Posting($item->accountId, $gain ? Side::Debit : Side::Credit, Money::minor('0', $rate->from), $rate, $item->partyId, dimensions: $dimensions, valuation: $value),
                    new Posting($counterId, $gain ? Side::Credit : Side::Debit, $value, dimensions: $dimensions),
                ], ['revaluation_id' => $id, 'policy' => 'open-item-revaluation:v1'], source: new Source('revaluation', $id)));
            }

            $this->db->insert('ledger_revaluations', ['id' => $id, 'book_id' => $bookId, 'item_id' => $itemId, 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'effective_on' => $effectiveOn, 'delta_minor' => (string) $delta->minor,
                'rate' => $rate->rate, 'rate_as_of' => $rate->asOf, 'rate_source' => $rate->source, 'rounding' => $rate->rounding->value, 'journal_id' => $journal?->id, 'reason' => $reason]);

            return $journal;
        }, $bookId);
    }

    /** @return list<Journal> */
    public function clear(string $bookId, string $itemId, string $key, string $effectiveOn, string $reason): array
    {
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($reason, 1000);

        return $this->db->run(Permission::Revalue, function () use ($bookId, $itemId, $key, $effectiveOn, $reason): array {
            $this->db->lockBook($bookId);
            $this->db->find('ledger_open_items', $itemId, $bookId);
            $fingerprint = Canonical::hash([$itemId, $effectiveOn, $reason]);
            $existing = $this->db->table('ledger_revaluation_clearings')->where('book_id', $bookId)->where('item_id', $itemId)->where('idempotency_key', $key)->first();
            $result = [];

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Revaluation clearing key was already used with different content.');

                foreach (Row::json($existing, 'journal_ids') as $id) {
                    Assert::that(is_string($id), 'Invalid stored revaluation journal identity.');

                    if (is_string($id)) {
                        $result[] = Journal::fromRow($this->db->find('ledger_journals', $id, $bookId));
                    }
                }

                return $result;
            }

            foreach ($this->active($bookId, $itemId)->orderByDesc('sequence')->get() as $original) {
                $journalId = Row::text($original, 'journal_id');
                $originalId = Row::text($original, 'id');
                $reversalKey = 'unrevalue:'.hash('sha256', $key.':'.$originalId);
                $journal = $this->journals->reverse($bookId, $journalId, $reversalKey, $effectiveOn, $reason);
                $this->db->insert('ledger_revaluations', ['id' => (string) Str::uuid(), 'book_id' => $bookId, 'item_id' => $itemId,
                    'idempotency_key' => $reversalKey, 'fingerprint' => Canonical::hash([$originalId, $effectiveOn, $reason]), 'effective_on' => $effectiveOn,
                    'delta_minor' => (string) BigInteger::of(Row::text($original, 'delta_minor'))->negated(), 'rate' => Row::text($original, 'rate'),
                    'rate_as_of' => Row::text($original, 'rate_as_of'), 'rate_source' => Row::text($original, 'rate_source'),
                    'rounding' => Row::text($original, 'rounding'), 'journal_id' => $journal->id, 'reason' => $reason, 'reverses_id' => $originalId]);
                $result[] = $journal;
            }

            $this->db->insert('ledger_revaluation_clearings', ['id' => (string) Str::uuid(), 'book_id' => $bookId, 'item_id' => $itemId,
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $effectiveOn, 'reason' => $reason,
                'journal_ids' => Canonical::json(array_map(fn (Journal $journal): string => $journal->id, $result))]);

            return $result;
        }, $bookId);
    }

    public function clearForSettlement(string $bookId, string $itemId, string $key, string $effectiveOn): void
    {
        $this->db->run(Permission::Allocate, function () use ($bookId, $itemId, $key, $effectiveOn): void {
            if ($this->active($bookId, $itemId)->exists()) {
                $this->clear($bookId, $itemId, 'settlement:'.hash('sha256', $key), $effectiveOn, 'Reverse unrealized FX before settlement');
            }
        }, $bookId);
    }

    private function active(string $bookId, string $itemId): Builder
    {
        return $this->db->table('ledger_revaluations')->where('book_id', $bookId)->where('item_id', $itemId)->whereNull('reverses_id')->where('delta_minor', '<>', '0')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('ledger_revaluations AS reversed')->whereColumn('reversed.tenant_id', 'ledger_revaluations.tenant_id')
                    ->whereColumn('reversed.book_id', 'ledger_revaluations.book_id')->whereColumn('reversed.reverses_id', 'ledger_revaluations.id');
            });
    }

    private function journal(string $bookId, ?string $id): ?Journal
    {
        return $id === null ? null : Journal::fromRow($this->db->find('ledger_journals', $id, $bookId));
    }
}
