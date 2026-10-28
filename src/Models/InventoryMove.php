<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $pool_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property string $kind
 * @property string $quantity
 * @property string $value_minor
 * @property string|null $commercial_minor
 * @property string|null $variance_account_id
 * @property string $counter_account_id
 * @property string|null $journal_id
 * @property array<array-key, mixed> $plan
 * @property string|null $lot
 * @property CarbonImmutable|null $expires_on
 * @property string|null $reverses_id
 * @property string|null $returns_id
 * @property string|null $transfer_id
 * @property string|null $production_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class InventoryMove extends Model
{
    protected $table = 'ledger_inventory_moves';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'plan' => 'array', 'expires_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'counter_account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<InventoryPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_pool', InventoryPool::class), 'pool_id', 'id');
    }

    /** @return BelongsTo<Production, $this> */
    public function production(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('production', Production::class), 'production_id', 'id');
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function returns(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'returns_id', 'id');
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<InventoryTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_transfer', InventoryTransfer::class), 'transfer_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function varianceAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'variance_account_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
