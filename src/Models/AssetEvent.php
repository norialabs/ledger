<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $asset_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property string $kind
 * @property string $amount_minor
 * @property string $cost_delta
 * @property string|null $fraction
 * @property string $depreciation_minor
 * @property string $impairment_minor
 * @property int $disposal_delta
 * @property string|null $counter_account_id
 * @property string|null $gain_account_id
 * @property string|null $loss_account_id
 * @property string|null $surplus_account_id
 * @property string|null $retained_account_id
 * @property string $surplus_delta
 * @property string $loss_delta
 * @property string|null $unimpaired_minor
 * @property array<array-key, mixed>|null $plan
 * @property array<array-key, mixed>|null $dimensions
 * @property string|null $residual_minor
 * @property string|null $plan_id
 * @property int|null $line_no
 * @property string|null $journal_id
 * @property string|null $reverses_id
 * @property string $policy_version
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class AssetEvent extends Model
{
    protected $table = 'ledger_asset_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'disposal_delta' => 'integer', 'plan' => 'array', 'dimensions' => 'array', 'line_no' => 'integer', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('asset', Asset::class), 'asset_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'counter_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function gainAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'gain_account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function lossAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'loss_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function retainedAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'retained_account_id', 'id');
    }

    /** @return BelongsTo<AssetEvent, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('asset_event', AssetEvent::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function surplusAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'surplus_account_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
