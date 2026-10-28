<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Assets\AssetPosition;
use Noria\Ledger\Assets\AssetRequest;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\ReportingMovement;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Scheduling\ScheduleLine;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Assets
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    public function capitalize(AssetRequest $request): string
    {
        return $this->db->run(Permission::Assets, function () use ($request): string {
            $book = $this->db->lockBook($request->bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            Assert::that($currency == $request->cost->currency, 'Asset values must use functional currency.');
            $fingerprint = Canonical::hash($request);
            $existing = $this->db->table('ledger_assets')->where('book_id', $request->bookId)->where('idempotency_key', $request->key)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Asset key was already used with different content.');

                return Row::text($existing, 'id');
            }

            $id = (string) Str::uuid7();
            $rows = [['account_id' => $request->costAccountId, 'side' => 'debit', 'amount_minor' => (string) $request->cost->minor]];

            foreach ([[$request->depreciationAccountId, $request->openingDepreciation], [$request->impairmentAccountId, $request->openingImpairment],
                [$request->surplusAccountId, $request->openingSurplus],
                [$request->counterAccountId, $request->cost->minus($request->openingDepreciation)->minus($request->openingImpairment)->minus($request->openingSurplus)]] as [$account, $money]) {
                if (! $money->minor->isZero()) {
                    $rows[] = ['account_id' => $account, 'side' => $money->minor->isPositive() ? 'credit' : 'debit', 'amount_minor' => (string) $money->minor->abs()];
                }
            }

            $metadata = ['asset_id' => $id, 'policy_version' => $request->policyVersion];
            $counter = $this->db->find('ledger_accounts', $request->counterAccountId, $request->bookId);
            if (Row::nullable($counter, 'cash_flow') === 'cash') {
                $metadata['cash_flow'] = ['investing' => (string) $request->cost->minus($request->openingDepreciation)->minus($request->openingImpairment)->minor->negated()];
            }
            $journal = $this->post($request->bookId, 'asset-cap:'.hash('sha256', $request->key), $request->effectiveOn, 'Capitalize '.$request->reference,
                $rows, $currency, $request->dimensions, $metadata, new Source('asset.capitalization', $request->reference),
                $request->openingSurplus->minor->isZero() ? null : 'opening_balance');
            $this->db->insert('ledger_assets', ['id' => $id, 'book_id' => $request->bookId, 'idempotency_key' => $request->key, 'fingerprint' => $fingerprint,
                'reference' => $request->reference, 'effective_on' => $request->effectiveOn, 'available_on' => $request->availableOn,
                'cost_minor' => (string) $request->cost->minor, 'residual_minor' => (string) $request->residual->minor,
                'opening_depreciation_minor' => (string) $request->openingDepreciation->minor, 'opening_impairment_minor' => (string) $request->openingImpairment->minor,
                'opening_surplus_minor' => (string) $request->openingSurplus->minor, 'opening_loss_minor' => (string) $request->openingLoss->minor, 'surplus_account_id' => $request->surplusAccountId,
                'cost_account_id' => $request->costAccountId, 'depreciation_account_id' => $request->depreciationAccountId, 'impairment_account_id' => $request->impairmentAccountId,
                'expense_account_id' => $request->expenseAccountId, 'counter_account_id' => $request->counterAccountId, 'journal_id' => $journal?->id,
                'plan' => Canonical::json($request->plan), 'policy_version' => $request->policyVersion, 'stub_convention' => $request->stubConvention->value,
                'dimensions' => $request->dimensions === [] ? '{}' : Canonical::json($request->dimensions)]);

            return $id;
        }, $request->bookId);
    }

    /** @return list<string> */
    public function depreciate(string $bookId, string $assetId, string $throughDate, ?string $postingOn = null): array
    {
        Assert::date($throughDate);

        if ($postingOn !== null) {
            Assert::date($postingOn);
            Assert::that($postingOn >= $throughDate, 'A catch-up posting date cannot precede the depreciation cutoff.');
        }

        return $this->db->run(Permission::Assets, function () use ($bookId, $assetId, $throughDate, $postingOn): array {
            $book = $this->db->lockBook($bookId);
            $state = $this->quote(new Snapshot($bookId, $postingOn ?? $throughDate, Row::text($book, 'next_sequence')), $assetId);
            Assert::that(! Row::boolean($state, 'disposed'), 'Disposed assets cannot be depreciated.');
            $result = [];
            $pending = $state->pending ?? [];
            Assert::that(is_array($pending), 'Invalid asset depreciation quote.');

            foreach ($pending as $value) {
                $line = (object) $value;

                if (Row::text($line, 'due_on') > $throughDate) {
                    continue;
                }

                $number = (int) Row::text($line, 'line_no');
                $planId = Row::text($state, 'plan_id');
                $generation = $this->db->table('ledger_asset_events')->where('book_id', $bookId)->where('asset_id', $assetId)
                    ->where('kind', 'depreciation')->where('plan_id', $planId)->where('line_no', $number)->count();
                $key = 'asset-dep:'.hash('sha256', $assetId.':'.$planId.':'.$number.':'.$generation);
                $result[] = $this->event($bookId, $assetId, $key, $postingOn ?? Row::text($line, 'due_on'), 'depreciation', null, null, null, null,
                    null, null, Row::text($state, 'policy_version'), 'Scheduled depreciation', $planId, $number);
            }

            return $result;
        }, $bookId);
    }

    /** @return array<string, list<string>> */
    public function depreciateDue(string $bookId, string $throughDate): array
    {
        Assert::date($throughDate);

        return $this->db->run(Permission::Assets, function () use ($bookId, $throughDate): array {
            $book = $this->db->lockBook($bookId);
            $snapshot = new Snapshot($bookId, $throughDate, Row::text($book, 'next_sequence'));
            $posted = [];
            foreach ($this->db->table('ledger_assets')->where('book_id', $bookId)->where('effective_on', '<=', $throughDate)->orderBy('reference')->get() as $asset) {
                $id = Row::text($asset, 'id');
                $position = $this->position($snapshot, $id);
                if (! $position->disposed && $position->depreciationDue->minor->isPositive()) {
                    $posted[Row::text($asset, 'reference')] = $this->depreciate($bookId, $id, $throughDate);
                }
            }

            return $posted;
        }, $bookId);
    }

    /** @param list<ScheduleLine> $plan */
    public function revise(string $bookId, string $assetId, string $key, string $date, Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'estimate', null, null, null, null, $residual, $plan, $policyVersion, $reason);
    }

    /** @param list<ScheduleLine> $plan */
    public function impair(string $bookId, string $assetId, string $key, string $date, Money $loss, string $lossAccountId,
        Money $residual, array $plan, string $policyVersion, string $reason, ?string $surplusAccountId = null): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'impairment', $loss, $lossAccountId, null, null, $residual, $plan, $policyVersion, $reason, surplusAccountId: $surplusAccountId);
    }

    /** @param list<ScheduleLine> $plan */
    public function recover(string $bookId, string $assetId, string $key, string $date, Money $recoverableAmount, Money $unimpairedCarrying,
        string $recoveryAccountId, Money $residual, array $plan, string $policyVersion, string $reason, ?string $surplusAccountId = null): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'recovery', $recoverableAmount, $recoveryAccountId, null, null, $residual, $plan, $policyVersion, $reason,
            surplusAccountId: $surplusAccountId, unimpairedCarrying: $unimpairedCarrying);
    }

    public function dispose(string $bookId, string $assetId, string $key, string $date, Money $proceeds, string $proceedsAccountId, string $gainAccountId, string $lossAccountId, string $reason, ?string $retainedEarningsAccountId = null): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'disposal', $proceeds, $proceedsAccountId, $gainAccountId, $lossAccountId,
            null, null, 'asset-disposal:v1', $reason, retainedAccountId: $retainedEarningsAccountId);
    }

    /** @param list<ScheduleLine> $plan */
    public function addCost(string $bookId, string $assetId, string $key, string $date, Money $cost, string $counterAccountId,
        Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'addition', $cost, $counterAccountId, null, null, $residual, $plan, $policyVersion, $reason);
    }

    /** @param list<ScheduleLine> $plan */
    public function disposePart(string $bookId, string $assetId, string $key, string $date, string $fraction, Money $proceeds,
        string $proceedsAccountId, string $gainAccountId, string $lossAccountId, Money $residual, array $plan, string $policyVersion, string $reason, ?string $retainedEarningsAccountId = null): string
    {
        Assert::decimal($fraction);

        return $this->event($bookId, $assetId, $key, $date, 'partial_disposal', $proceeds, $proceedsAccountId, $gainAccountId, $lossAccountId,
            $residual, $plan, $policyVersion, $reason, fraction: $fraction, retainedAccountId: $retainedEarningsAccountId);
    }

    /** @param list<ScheduleLine> $plan */
    public function revalue(string $bookId, string $assetId, string $key, string $date, Money $uplift, string $surplusAccountId,
        Money $residual, array $plan, string $policyVersion, string $reason, ?string $gainAccountId = null, ?string $lossAccountId = null, ?Money $unimpairedCarrying = null): string
    {
        Assert::that(! $uplift->minor->isZero(), 'Revaluation requires a nonzero change.');
        Assert::that(! $residual->minor->isNegative(), 'Revaluation residual cannot be negative.');

        return $this->event($bookId, $assetId, $key, $date, 'revaluation', $uplift, $surplusAccountId, $gainAccountId, $lossAccountId, $residual, $plan, $policyVersion, $reason,
            unimpairedCarrying: $unimpairedCarrying);
    }

    public function cancel(string $bookId, string $assetId, string $key, string $date, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'cancellation', null, null, null, null, null, null, 'asset-cancellation:v1', $reason);
    }

    /** @param array<string, string> $dimensions */
    public function transfer(string $bookId, string $assetId, string $key, string $date, array $dimensions, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);
        ksort($dimensions);

        return $this->db->run(Permission::Assets, function () use ($bookId, $assetId, $key, $date, $dimensions, $reason): string {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $fingerprint = Canonical::hash([$assetId, $date, 'transfer', $dimensions, $reason]);
            $existing = $this->replay($bookId, $key, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            $this->depreciate($bookId, $assetId, $date);
            $asset = $this->db->find('ledger_assets', $assetId, $bookId);
            $from = $this->currentDimensions($bookId, $assetId);
            $position = $this->position(new Snapshot($bookId, $date, Row::text($this->db->find('ledger_books', $bookId), 'next_sequence')), $assetId);
            $postings = [];
            foreach ([[Row::text($asset, 'cost_account_id'), $position->cost], [Row::text($asset, 'depreciation_account_id'), $position->depreciation->negated()],
                [Row::text($asset, 'impairment_account_id'), $position->impairment->negated()]] as [$account, $balance]) {
                if ($balance->minor->isZero()) {
                    continue;
                }
                $amount = Money::minor((string) $balance->minor->abs(), $currency);
                $postings[] = new Posting($account, $balance->minor->isPositive() ? Side::Credit : Side::Debit, $amount, dimensions: $from);
                $postings[] = new Posting($account, $balance->minor->isPositive() ? Side::Debit : Side::Credit, $amount, dimensions: $dimensions);
            }
            Assert::that($postings !== [], 'An asset transfer needs a carrying balance to move.');
            $id = (string) Str::uuid7();
            $journal = $this->journals->post(new JournalRequest($bookId, 'asset-event:'.hash('sha256', $key), $date, $reason, $postings,
                ['asset_id' => $assetId, 'asset_event_id' => $id, 'policy_version' => 'asset-transfer:v1'], source: new Source('asset.event', $key)));
            $this->db->insert('ledger_asset_events', ['id' => $id, 'book_id' => $bookId, 'asset_id' => $assetId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'kind' => 'transfer', 'amount_minor' => '0', 'depreciation_minor' => '0', 'impairment_minor' => '0', 'cost_delta' => '0',
                'surplus_delta' => '0', 'loss_delta' => '0', 'disposal_delta' => 0, 'journal_id' => $journal->id, 'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions),
                'policy_version' => 'asset-transfer:v1', 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param non-empty-list<string> $assetIds
     * @param  array<string, Money>  $floors
     * @return array<string, Money>
     */
    public function allocateGroupImpairment(Snapshot $snapshot, array $assetIds, Money $loss, array $floors = []): array
    {
        Assert::that($assetIds !== [] && count(array_unique($assetIds)) === count($assetIds), 'A cash-generating unit needs distinct assets.');
        Assert::that($loss->minor->isPositive(), 'A group impairment loss must be positive.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $assetIds, $loss, $floors): array {
            $headroom = [];
            $carrying = [];
            foreach ($assetIds as $assetId) {
                $position = $this->position($snapshot, $assetId);
                Assert::that(! $position->disposed, 'Disposed assets cannot absorb impairment.');
                $floor = $floors[$assetId] ?? Money::minor('0', $loss->currency);
                Assert::that($floor->currency == $loss->currency && ! $floor->minor->isNegative(), 'Impairment floors use the book currency and cannot be negative.');
                $carrying[$assetId] = $position->carrying->minor;
                $headroom[$assetId] = BigInteger::max(BigInteger::zero(), $position->carrying->minor->minus($floor->minor));
            }
            $total = array_reduce($headroom, fn (BigInteger $sum, BigInteger $room): BigInteger => $sum->plus($room), BigInteger::zero());
            Assert::that($loss->minor->isLessThanOrEqualTo($total), 'The loss exceeds what the unit can absorb above its asset floors.');
            $allocated = array_fill_keys($assetIds, BigInteger::zero());
            $remaining = $loss->minor;
            while ($remaining->isPositive()) {
                $open = array_filter($assetIds, fn (string $id): bool => $headroom[$id]->minus($allocated[$id])->isPositive() && $carrying[$id]->isPositive());
                $weight = array_reduce($open, fn (BigInteger $sum, string $id): BigInteger => $sum->plus($carrying[$id]), BigInteger::zero());
                $shares = [];
                $remainders = [];
                $given = BigInteger::zero();
                foreach ($open as $id) {
                    $exact = BigRational::of($remaining)->multipliedBy($carrying[$id])->dividedBy($weight);
                    $shares[$id] = $exact->getIntegralPart();
                    $remainders[$id] = $exact->minus($shares[$id]);
                    $given = $given->plus($shares[$id]);
                }
                $order = array_flip($assetIds);
                uksort($remainders, fn (string $a, string $b): int => $remainders[$b]->compareTo($remainders[$a]) ?: $order[$a] <=> $order[$b]);
                foreach (array_keys($remainders) as $id) {
                    if ($given->isEqualTo($remaining)) {
                        break;
                    }
                    $shares[$id] = $shares[$id]->plus(1);
                    $given = $given->plus(1);
                }
                $progress = BigInteger::zero();
                foreach ($shares as $id => $share) {
                    $take = BigInteger::min($share, $headroom[$id]->minus($allocated[$id]));
                    $allocated[$id] = $allocated[$id]->plus($take);
                    $progress = $progress->plus($take);
                }
                $remaining = $remaining->minus($progress);
            }

            return array_map(fn (BigInteger $minor): Money => Money::minor((string) $minor, $loss->currency), $allocated);
        }, $snapshot->bookId);
    }

    /** @return array<string, string> */
    public function currentDimensions(string $bookId, string $assetId): array
    {
        return $this->db->run(Permission::Read, function () use ($bookId, $assetId): array {
            $this->db->find('ledger_assets', $assetId, $bookId);
            $row = $this->db->connection->selectOne('SELECT ledger_asset_current_dimensions(?::uuid, ?::uuid, ?::uuid)::text AS dimensions', [$this->db->scope->tenantId, $bookId, $assetId]);
            Assert::that($row instanceof stdClass, 'Asset dimensions are missing.');

            return Row::dimensions($row);
        }, $bookId);
    }

    /** @return list<array{asset_id: string, reference: string, cost_account_id: string, cost: array<string, Money>, depreciation: array<string, Money>, impairment: array<string, Money>, carrying: array{opening: Money, closing: Money}}> */
    public function rollForward(Snapshot $snapshot, string $startsOn, ?string $costAccountId = null): array
    {
        Assert::date($startsOn);
        Assert::that($startsOn <= $snapshot->effectiveOn, 'Report starts after it ends.');

        return $this->db->run(Permission::Read, function () use ($snapshot, $startsOn, $costAccountId): array {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $rows = $this->db->connection->select(<<<'SQL'
                WITH events AS (
                    SELECT a.id AS asset_id, a.journal_id, 'capitalization'::text AS kind FROM ledger_assets a WHERE a.tenant_id = ? AND a.book_id = ?
                    UNION ALL
                    SELECT e.asset_id, e.journal_id, coalesce(o.kind, e.kind) FROM ledger_asset_events e
                    LEFT JOIN ledger_asset_events o ON o.tenant_id = e.tenant_id AND o.book_id = e.book_id AND o.id = e.reverses_id
                    WHERE e.tenant_id = ? AND e.book_id = ? AND e.journal_id IS NOT NULL)
                SELECT ev.asset_id, ev.kind, j.effective_on < ? AS opening,
                    CASE p.account_id WHEN a.cost_account_id THEN 'cost' WHEN a.depreciation_account_id THEN 'depreciation'
                        WHEN a.impairment_account_id THEN 'impairment' ELSE 'expense' END AS role,
                    sum(CASE WHEN p.side = 'debit' THEN p.functional_minor ELSE -p.functional_minor END)::text AS amount
                FROM events ev
                JOIN ledger_assets a ON a.tenant_id = ? AND a.book_id = ? AND a.id = ev.asset_id
                JOIN ledger_journals j ON j.tenant_id = a.tenant_id AND j.book_id = a.book_id AND j.id = ev.journal_id
                JOIN ledger_postings p ON p.tenant_id = j.tenant_id AND p.book_id = j.book_id AND p.journal_id = j.id
                    AND p.account_id IN (a.cost_account_id, a.depreciation_account_id, a.impairment_account_id, a.expense_account_id)
                WHERE j.effective_on <= ? AND j.sequence <= ?
                GROUP BY 1, 2, 3, 4
                SQL, [$this->db->scope->tenantId, $snapshot->bookId, $this->db->scope->tenantId, $snapshot->bookId, $startsOn,
                $this->db->scope->tenantId, $snapshot->bookId, $snapshot->effectiveOn, $snapshot->throughSequence]);
            $columns = [
                'cost' => ['opening', 'additions', 'revaluations', 'disposals', 'other', 'closing'],
                'depreciation' => ['opening', 'additions', 'charge', 'revaluations', 'disposals', 'other', 'closing'],
                'impairment' => ['opening', 'additions', 'losses', 'reversals', 'revaluations', 'disposals', 'other', 'closing'],
            ];
            $category = fn (string $role, string $kind): string => match (true) {
                in_array($kind, ['capitalization', 'addition'], true) => 'additions',
                $kind === 'depreciation' && $role === 'depreciation' => 'charge',
                $kind === 'impairment' && $role === 'impairment' => 'losses',
                $kind === 'recovery' && $role === 'impairment' => 'reversals',
                $kind === 'revaluation' => 'revaluations',
                in_array($kind, ['disposal', 'partial_disposal', 'cancellation'], true) => 'disposals',
                default => 'other',
            };
            $amounts = [];
            $charges = [];
            $rows = array_values(array_filter($rows, fn (mixed $row): bool => $row instanceof stdClass));
            foreach ($rows as $row) {
                if (Row::text($row, 'role') === 'expense' && ! Row::boolean($row, 'opening')) {
                    $charges[Row::text($row, 'asset_id')][Row::text($row, 'kind')] = BigInteger::of(Row::text($row, 'amount'));
                }
            }
            foreach ($rows as $row) {
                $role = Row::text($row, 'role');
                if ($role === 'expense') {
                    continue;
                }
                $assetId = Row::text($row, 'asset_id');
                $kind = Row::text($row, 'kind');
                $minor = BigInteger::of(Row::text($row, 'amount'));
                $presented = $role === 'cost' ? $minor : $minor->negated();
                if (Row::boolean($row, 'opening')) {
                    $amounts[$assetId][$role]['opening'] = ($amounts[$assetId][$role]['opening'] ?? BigInteger::zero())->plus($presented);

                    continue;
                }
                if ($role === 'depreciation') {
                    $charge = $charges[$assetId][$kind] ?? BigInteger::zero();
                    $amounts[$assetId][$role]['charge'] = ($amounts[$assetId][$role]['charge'] ?? BigInteger::zero())->plus($charge);
                    $presented = $presented->minus($charge);
                }
                $bucket = $category($role, $kind);
                $amounts[$assetId][$role][$bucket] = ($amounts[$assetId][$role][$bucket] ?? BigInteger::zero())->plus($presented);
            }
            $assets = $this->db->table('ledger_assets')->where('book_id', $snapshot->bookId)->where('effective_on', '<=', $snapshot->effectiveOn)
                ->where('sequence', '<=', $snapshot->throughSequence)->orderBy('reference');
            if ($costAccountId !== null) {
                $assets->where('cost_account_id', Assert::uuid($costAccountId));
            }
            $result = [];
            foreach ($assets->get() as $asset) {
                $id = Row::text($asset, 'id');
                $sections = [];
                foreach ($columns as $role => $buckets) {
                    $total = BigInteger::zero();
                    foreach ($buckets as $bucket) {
                        if ($bucket === 'closing') {
                            $sections[$role]['closing'] = Money::minor((string) $total, $currency);

                            continue;
                        }
                        $value = $amounts[$id][$role][$bucket] ?? BigInteger::zero();
                        $total = $total->plus($value);
                        $sections[$role][$bucket] = Money::minor((string) $value, $currency);
                    }
                }
                $carrying = function (string $bucket) use ($sections, $currency): Money {
                    $zero = Money::minor('0', $currency);

                    return ($sections['cost'][$bucket] ?? $zero)->minus($sections['depreciation'][$bucket] ?? $zero)->minus($sections['impairment'][$bucket] ?? $zero);
                };
                $result[] = ['asset_id' => $id, 'reference' => Row::text($asset, 'reference'), 'cost_account_id' => Row::text($asset, 'cost_account_id'),
                    'cost' => $sections['cost'], 'depreciation' => $sections['depreciation'], 'impairment' => $sections['impairment'],
                    'carrying' => ['opening' => $carrying('opening'), 'closing' => $carrying('closing')]];
            }

            return $result;
        }, $snapshot->bookId);
    }

    public function position(Snapshot $snapshot, string $assetId): AssetPosition
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $assetId): AssetPosition {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $data = $this->quote($snapshot, $assetId);

            return new AssetPosition(Money::minor(Row::text($data, 'cost_minor'), $currency), Money::minor(Row::text($data, 'depreciation_minor'), $currency),
                Money::minor(Row::text($data, 'impairment_minor'), $currency), Money::minor(Row::text($data, 'carrying_minor'), $currency),
                Money::minor(Row::text($data, 'residual_minor'), $currency), Money::minor(Row::text($data, 'due_minor'), $currency), Row::boolean($data, 'disposed'), Row::text($data, 'plan_id'),
                Money::minor(Row::text($data, 'surplus_minor'), $currency), Money::minor(Row::text($data, 'loss_minor'), $currency));
        }, $snapshot->bookId);
    }

    public function reverse(string $bookId, string $eventId, string $key, string $date, string $reason): string
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Assets, function () use ($bookId, $eventId, $key, $date, $reason): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash(['reverse', $eventId, $date, $reason]);
            $existing = $this->replay($bookId, $key, $fingerprint);

            if ($existing !== null) {
                return $existing;
            }

            $original = $this->db->find('ledger_asset_events', $eventId, $bookId);
            $journalId = Row::nullable($original, 'journal_id');
            $journal = $journalId === null ? null : $this->journals->reverse($bookId, $journalId, 'asset-reverse:'.hash('sha256', $key), $date, $reason);
            $id = (string) Str::uuid7();
            $this->db->insert('ledger_asset_events', ['id' => $id, 'book_id' => $bookId, 'asset_id' => Row::text($original, 'asset_id'),
                'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'effective_on' => $date, 'kind' => 'reversal', 'amount_minor' => '0',
                'depreciation_minor' => (string) BigInteger::of(Row::text($original, 'depreciation_minor'))->negated(),
                'impairment_minor' => (string) BigInteger::of(Row::text($original, 'impairment_minor'))->negated(),
                'cost_delta' => (string) BigInteger::of(Row::text($original, 'cost_delta'))->negated(),
                'surplus_delta' => (string) BigInteger::of(Row::text($original, 'surplus_delta'))->negated(),
                'loss_delta' => (string) BigInteger::of(Row::text($original, 'loss_delta'))->negated(), 'surplus_account_id' => Row::nullable($original, 'surplus_account_id'),
                'disposal_delta' => -(int) Row::text($original, 'disposal_delta'), 'journal_id' => $journal?->id, 'reverses_id' => $eventId,
                'policy_version' => 'asset-reversal:v1', 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param list<ScheduleLine>|null $plan */
    private function event(string $bookId, string $assetId, string $key, string $date, string $kind, ?Money $amount, ?string $counterId,
        ?string $gainId, ?string $lossId, ?Money $residual, ?array $plan, string $policy, string $reason, ?string $planId = null, ?int $lineNo = null, ?string $fraction = null,
        ?string $surplusAccountId = null, ?Money $unimpairedCarrying = null, ?string $retainedAccountId = null): string
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);
        Assert::text($policy, 128);

        return $this->db->run(Permission::Assets, function () use ($bookId, $assetId, $key, $date, $kind, $amount, $counterId, $gainId, $lossId, $residual, $plan, $policy, $reason, $planId, $lineNo, $fraction, $surplusAccountId, $unimpairedCarrying, $retainedAccountId): string {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            Assert::that(($amount === null || $amount->currency == $currency) && ($residual === null || $residual->currency == $currency), 'Asset events require functional currency.');
            Assert::that($unimpairedCarrying === null || $unimpairedCarrying->currency == $currency, 'Unimpaired carrying ceilings require functional currency.');

            foreach ($plan ?? [] as $line) {
                Assert::that($line->amount->currency == $currency, 'Asset plans require functional currency.');
            }

            $fingerprint = Canonical::hash([$assetId, $date, $kind, $amount, $counterId, $gainId, $lossId, $residual, $plan, $policy, $reason, $planId, $lineNo]);
            if ($fraction !== null) {
                $fingerprint = Canonical::hash([$fingerprint, $fraction]);
            }
            if ($surplusAccountId !== null || $unimpairedCarrying !== null || $retainedAccountId !== null) {
                $fingerprint = Canonical::hash([$fingerprint, $surplusAccountId, $unimpairedCarrying, $retainedAccountId]);
            }
            $existing = $this->replay($bookId, $key, $fingerprint);

            if ($existing !== null) {
                return $existing;
            }

            if (! in_array($kind, ['depreciation', 'cancellation'], true)) {
                $this->depreciate($bookId, $assetId, $date);
            }

            $asset = $this->db->find('ledger_assets', $assetId, $bookId);
            $newPlan = $plan === null ? null : Canonical::json($plan);
            $valuation = Canonical::json(['surplus_account_id' => $surplusAccountId, 'unimpaired_minor' => $unimpairedCarrying === null ? null : (string) $unimpairedCarrying->minor, 'retained_account_id' => $retainedAccountId]);
            $row = $this->db->connection->selectOne('SELECT ledger_asset_event_quote(?::uuid, ?::uuid, ?::uuid, ?, ?::date, ?::numeric, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?::jsonb, ?::uuid, ?::integer, ?::numeric, ?::jsonb)::text AS data',
                [$this->db->scope->tenantId, $bookId, $assetId, $kind, $date, $amount === null ? '0' : (string) $amount->minor, $counterId, $gainId, $lossId,
                    $residual === null ? null : (string) $residual->minor, $newPlan, $planId, $lineNo, $fraction, $valuation]);
            Assert::that($row instanceof stdClass, 'Asset event quote is missing.');
            $quote = (object) Row::json($row, 'data');
            $dimensions = $this->currentDimensions($bookId, $assetId);
            $postings = $quote->postings ?? [];
            Assert::that(is_array($postings), 'Asset posting quote is invalid.');
            $id = (string) Str::uuid7();
            $metadata = ['asset_id' => $assetId, 'asset_event_id' => $id, 'policy_version' => $policy];

            if (in_array($kind, ['disposal', 'partial_disposal', 'addition'], true) && $counterId !== null) {
                $counter = $this->db->find('ledger_accounts', $counterId, $bookId);

                if (Row::nullable($counter, 'cash_flow') === 'cash') {
                    $metadata['cash_flow'] = ['investing' => $amount === null ? '0' : (string) ($kind === 'addition' ? $amount->minor->negated() : $amount->minor)];
                }
            }

            $journal = $kind === 'cancellation'
                ? $this->journals->reverse($bookId, Row::text($asset, 'journal_id'), 'asset-cancel:'.hash('sha256', $key), $date, $reason)
                : $this->post($bookId, 'asset-event:'.hash('sha256', $key), $date, $reason,
                    $postings, $currency, $dimensions, $metadata, new Source('asset.event', $key),
                    match ($kind) {
                        'revaluation', 'impairment', 'recovery' => 'oci', 'disposal', 'partial_disposal' => 'transfer', default => null
                    });
            $this->db->insert('ledger_asset_events', ['id' => $id, 'book_id' => $bookId, 'asset_id' => $assetId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'kind' => $kind, 'amount_minor' => $amount === null ? '0' : (string) $amount->minor,
                'counter_account_id' => $counterId, 'gain_account_id' => $gainId, 'loss_account_id' => $lossId,
                'depreciation_minor' => Row::text($quote, 'depreciation_minor'), 'impairment_minor' => Row::text($quote, 'impairment_minor'),
                'cost_delta' => Row::text($quote, 'cost_delta'), 'fraction' => $fraction,
                'surplus_delta' => Row::text($quote, 'surplus_delta'), 'loss_delta' => Row::text($quote, 'loss_delta'), 'surplus_account_id' => Row::nullable($quote, 'surplus_account_id'),
                'unimpaired_minor' => $unimpairedCarrying === null ? null : (string) $unimpairedCarrying->minor, 'retained_account_id' => $retainedAccountId,
                'disposal_delta' => (int) Row::text($quote, 'disposal_delta'), 'journal_id' => $journal?->id, 'plan_id' => $planId, 'line_no' => $lineNo,
                'plan' => $newPlan, 'residual_minor' => $residual === null ? null : (string) $residual->minor, 'policy_version' => $policy, 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    private function quote(Snapshot $snapshot, string $assetId): stdClass
    {
        $this->db->find('ledger_assets', $assetId, $snapshot->bookId);
        $row = $this->db->connection->selectOne('SELECT ledger_asset_quote(?::uuid, ?::uuid, ?::uuid, ?::date, ?::bigint)::text AS data',
            [$this->db->scope->tenantId, $snapshot->bookId, $assetId, $snapshot->effectiveOn, $snapshot->throughSequence]);

        return (object) ($row instanceof stdClass ? Row::json($row, 'data') : []);
    }

    private function replay(string $bookId, string $key, string $fingerprint): ?string
    {
        $existing = $this->db->table('ledger_asset_events')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $existing instanceof stdClass) {
            return null;
        }

        Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Asset event key was already used with different content.');

        return Row::text($existing, 'id');
    }

    /** @param array<array-key, mixed> $rows
     * @param  array<string, string>  $dimensions
     * @param  array<string, mixed>  $metadata
     */
    private function post(string $bookId, string $key, string $date, string $reason, array $rows, Currency $currency, array $dimensions, array $metadata, Source $source, ?string $reportingKind = null): ?Journal
    {
        $postings = [];

        foreach ($rows as $value) {
            $row = (object) $value;
            $accountId = Row::text($row, 'account_id');
            $movement = $reportingKind !== null && Row::text($this->db->find('ledger_accounts', $accountId, $bookId), 'type') === 'equity'
                ? new ReportingMovement($reportingKind, Row::text((object) $metadata, 'policy_version'), $source->reference, $reportingKind === 'oci' ? 'non_recyclable' : null) : null;
            $postings[] = new Posting($accountId, Side::from(Row::text($row, 'side')), Money::minor(Row::text($row, 'amount_minor'), $currency), dimensions: $dimensions, reportingMovement: $movement);
        }

        return $postings === [] ? null : $this->journals->post(new JournalRequest($bookId, $key, $date, $reason, $postings, $metadata, source: $source));
    }
}
