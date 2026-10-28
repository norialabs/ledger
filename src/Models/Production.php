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
 * @property string $output_pool_id
 * @property string $output_movement_id
 * @property string $output_quantity
 * @property string $output_value_minor
 * @property string $conversion_minor
 * @property string $wip_account_id
 * @property array<array-key, mixed> $inputs
 * @property string|null $reverses_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class Production extends Model
{
    protected $table = 'ledger_productions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'inputs' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function outputMovement(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'output_movement_id', 'id');
    }

    /** @return BelongsTo<InventoryPool, $this> */
    public function outputPool(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_pool', InventoryPool::class), 'output_pool_id', 'id');
    }

    /** @return BelongsTo<Production, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('production', Production::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function wipAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'wip_account_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
