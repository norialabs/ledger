<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $reference
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable $available_on
 * @property string $cost_minor
 * @property string $residual_minor
 * @property string $opening_depreciation_minor
 * @property string $opening_impairment_minor
 * @property string $opening_surplus_minor
 * @property string|null $opening_loss_minor
 * @property string|null $surplus_account_id
 * @property string $cost_account_id
 * @property string $depreciation_account_id
 * @property string $impairment_account_id
 * @property string $expense_account_id
 * @property string $counter_account_id
 * @property string $journal_id
 * @property array<array-key, mixed> $plan
 * @property array<array-key, mixed> $dimensions
 * @property string $stub_convention
 * @property string $policy_version
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class Asset extends Model
{
    protected $table = 'ledger_assets';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'available_on' => 'immutable_date', 'plan' => 'array', 'dimensions' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function costAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'cost_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'counter_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'depreciation_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'expense_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function impairmentAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'impairment_account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
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

    /** @return HasMany<AssetEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(self::modelClass('asset_event', AssetEvent::class), 'asset_id');
    }
}
