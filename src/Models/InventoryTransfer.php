<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property string $source_pool_id
 * @property string $destination_pool_id
 * @property string $issue_id
 * @property string $receipt_id
 * @property string $quantity
 * @property string $value_minor
 * @property string $clearing_account_id
 * @property string|null $reverses_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class InventoryTransfer extends Model
{
    protected $table = 'ledger_inventory_transfers';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'clearing_account_id', 'id');
    }

    /** @return BelongsTo<InventoryPool, $this> */
    public function destinationPool(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_pool', InventoryPool::class), 'destination_pool_id', 'id');
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'issue_id', 'id');
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'receipt_id', 'id');
    }

    /** @return BelongsTo<InventoryTransfer, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_transfer', InventoryTransfer::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<InventoryPool, $this> */
    public function sourcePool(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_pool', InventoryPool::class), 'source_pool_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
