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
 * @property string $reference
 * @property string $sku
 * @property string $fingerprint
 * @property string $account_id
 * @property string $method
 * @property bool $serialized
 * @property array<array-key, mixed> $dimensions
 * @property CarbonImmutable $created_at
 */
class InventoryPool extends Model
{
    protected $table = 'ledger_inventory_pools';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['serialized' => 'boolean', 'dimensions' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'account_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return HasMany<InventoryMove, $this> */
    public function moves(): HasMany
    {
        return $this->hasMany(self::modelClass('inventory_move', InventoryMove::class), 'pool_id');
    }
}
