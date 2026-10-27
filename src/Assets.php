<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Brick\Math\BigInteger;
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
                [$request->counterAccountId, $request->cost->minus($request->openingDepreciation)->minus($request->openingImpairment)]] as [$account, $money]) {
                if (! $money->minor->isZero()) {
                    $rows[] = ['account_id' => $account, 'side' => 'credit', 'amount_minor' => (string) $money->minor];
                }
            }

            $metadata = ['asset_id' => $id, 'policy_version' => $request->policyVersion];
            $counter = $this->db->find('ledger_accounts', $request->counterAccountId, $request->bookId);
            if (Row::nullable($counter, 'cash_flow') === 'cash') {
                $metadata['cash_flow'] = ['investing' => (string) $request->cost->minus($request->openingDepreciation)->minus($request->openingImpairment)->minor->negated()];
            }
            $journal = $this->post($request->bookId, 'asset-cap:'.hash('sha256', $request->key), $request->effectiveOn, 'Capitalize '.$request->reference,
                $rows, $currency, $request->dimensions, $metadata, new Source('asset.capitalization', $request->reference));
            $this->db->insert('ledger_assets', ['id' => $id, 'book_id' => $request->bookId, 'idempotency_key' => $request->key, 'fingerprint' => $fingerprint,
                'reference' => $request->reference, 'effective_on' => $request->effectiveOn, 'available_on' => $request->availableOn,
                'cost_minor' => (string) $request->cost->minor, 'residual_minor' => (string) $request->residual->minor,
                'opening_depreciation_minor' => (string) $request->openingDepreciation->minor, 'opening_impairment_minor' => (string) $request->openingImpairment->minor,
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

            if (! is_array($pending)) {
                return [];
            }

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

    /** @param list<ScheduleLine> $plan */
    public function revise(string $bookId, string $assetId, string $key, string $date, Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'estimate', null, null, null, null, $residual, $plan, $policyVersion, $reason);
    }

    /** @param list<ScheduleLine> $plan */
    public function impair(string $bookId, string $assetId, string $key, string $date, Money $loss, string $lossAccountId,
        Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'impairment', $loss, $lossAccountId, null, null, $residual, $plan, $policyVersion, $reason);
    }

    public function dispose(string $bookId, string $assetId, string $key, string $date, Money $proceeds, string $proceedsAccountId, string $gainAccountId, string $lossAccountId, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'disposal', $proceeds, $proceedsAccountId, $gainAccountId, $lossAccountId,
            null, null, 'asset-disposal:v1', $reason);
    }

    /** @param list<ScheduleLine> $plan */
    public function addCost(string $bookId, string $assetId, string $key, string $date, Money $cost, string $counterAccountId,
        Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'addition', $cost, $counterAccountId, null, null, $residual, $plan, $policyVersion, $reason);
    }

    /** @param list<ScheduleLine> $plan */
    public function disposePart(string $bookId, string $assetId, string $key, string $date, string $fraction, Money $proceeds,
        string $proceedsAccountId, string $gainAccountId, string $lossAccountId, Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        Assert::decimal($fraction);

        return $this->event($bookId, $assetId, $key, $date, 'partial_disposal', $proceeds, $proceedsAccountId, $gainAccountId, $lossAccountId,
            $residual, $plan, $policyVersion, $reason, fraction: $fraction);
    }

    /** @param list<ScheduleLine> $plan */
    public function revalue(string $bookId, string $assetId, string $key, string $date, Money $uplift, string $surplusAccountId,
        Money $residual, array $plan, string $policyVersion, string $reason): string
    {
        Assert::that($uplift->minor->isPositive(), 'Upward revaluation requires a positive uplift.');
        Assert::that(! $residual->minor->isNegative(), 'Revaluation residual cannot be negative.');

        return $this->event($bookId, $assetId, $key, $date, 'revaluation', $uplift, $surplusAccountId, null, null, $residual, $plan, $policyVersion, $reason);
    }

    public function cancel(string $bookId, string $assetId, string $key, string $date, string $reason): string
    {
        return $this->event($bookId, $assetId, $key, $date, 'cancellation', null, null, null, null, null, null, 'asset-cancellation:v1', $reason);
    }

    public function position(Snapshot $snapshot, string $assetId): AssetPosition
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $assetId): AssetPosition {
            $book = $this->db->snapshotBook($snapshot);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $data = $this->quote($snapshot, $assetId);

            return new AssetPosition(Money::minor(Row::text($data, 'cost_minor'), $currency), Money::minor(Row::text($data, 'depreciation_minor'), $currency),
                Money::minor(Row::text($data, 'impairment_minor'), $currency), Money::minor(Row::text($data, 'carrying_minor'), $currency),
                Money::minor(Row::text($data, 'residual_minor'), $currency), Money::minor(Row::text($data, 'due_minor'), $currency), Row::boolean($data, 'disposed'), Row::text($data, 'plan_id'));
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
                'disposal_delta' => -(int) Row::text($original, 'disposal_delta'), 'journal_id' => $journal?->id, 'reverses_id' => $eventId,
                'policy_version' => 'asset-reversal:v1', 'reason' => $reason]);

            return $id;
        }, $bookId);
    }

    /** @param list<ScheduleLine>|null $plan */
    private function event(string $bookId, string $assetId, string $key, string $date, string $kind, ?Money $amount, ?string $counterId,
        ?string $gainId, ?string $lossId, ?Money $residual, ?array $plan, string $policy, string $reason, ?string $planId = null, ?int $lineNo = null, ?string $fraction = null): string
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);
        Assert::text($policy, 128);

        return $this->db->run(Permission::Assets, function () use ($bookId, $assetId, $key, $date, $kind, $amount, $counterId, $gainId, $lossId, $residual, $plan, $policy, $reason, $planId, $lineNo, $fraction): string {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            Assert::that(($amount === null || $amount->currency == $currency) && ($residual === null || $residual->currency == $currency), 'Asset events require functional currency.');

            foreach ($plan ?? [] as $line) {
                Assert::that($line->amount->currency == $currency, 'Asset plans require functional currency.');
            }

            $fingerprint = Canonical::hash([$assetId, $date, $kind, $amount, $counterId, $gainId, $lossId, $residual, $plan, $policy, $reason, $planId, $lineNo]);
            if ($fraction !== null) {
                $fingerprint = Canonical::hash([$fingerprint, $fraction]);
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
            $row = $this->db->connection->selectOne('SELECT ledger_asset_event_quote(?::uuid, ?::uuid, ?::uuid, ?, ?::date, ?::numeric, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?::jsonb, ?::uuid, ?::integer, ?::numeric)::text AS data',
                [$this->db->scope->tenantId, $bookId, $assetId, $kind, $date, $amount === null ? '0' : (string) $amount->minor, $counterId, $gainId, $lossId,
                    $residual === null ? null : (string) $residual->minor, $newPlan, $planId, $lineNo, $fraction]);
            Assert::that($row instanceof stdClass, 'Asset event quote is missing.');
            $quote = (object) ($row instanceof stdClass ? Row::json($row, 'data') : []);
            $dimensions = [];

            foreach (Row::json($asset, 'dimensions') as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $dimensions[$name] = $value;
                }
            }

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
                    is_array($postings) ? $postings : [], $currency, $dimensions, $metadata, new Source('asset.event', $key));
            $this->db->insert('ledger_asset_events', ['id' => $id, 'book_id' => $bookId, 'asset_id' => $assetId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'kind' => $kind, 'amount_minor' => $amount === null ? '0' : (string) $amount->minor,
                'counter_account_id' => $counterId, 'gain_account_id' => $gainId, 'loss_account_id' => $lossId,
                'depreciation_minor' => Row::text($quote, 'depreciation_minor'), 'impairment_minor' => Row::text($quote, 'impairment_minor'),
                'cost_delta' => Row::text($quote, 'cost_delta'), 'fraction' => $fraction,
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
    private function post(string $bookId, string $key, string $date, string $reason, array $rows, Currency $currency, array $dimensions, array $metadata, Source $source): ?Journal
    {
        $postings = [];

        foreach ($rows as $value) {
            $row = (object) $value;
            $postings[] = new Posting(Row::text($row, 'account_id'), Side::from(Row::text($row, 'side')), Money::minor(Row::text($row, 'amount_minor'), $currency), dimensions: $dimensions);
        }

        return $postings === [] ? null : $this->journals->post(new JournalRequest($bookId, $key, $date, $reason, $postings, $metadata, source: $source));
    }
}
