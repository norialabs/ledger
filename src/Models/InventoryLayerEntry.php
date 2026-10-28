<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $movement_id
 * @property string $layer_id
 * @property string $quantity
 * @property string $value_minor
 * @property CarbonImmutable $created_at
 */
class InventoryLayerEntry extends Model
{
    protected $table = 'ledger_inventory_layer_entries';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function layer(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'layer_id', 'id');
    }

    /** @return BelongsTo<InventoryMove, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('inventory_move', InventoryMove::class), 'movement_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
