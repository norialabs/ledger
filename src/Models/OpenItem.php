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
 * @property string $journal_id
 * @property int $line_no
 * @property CarbonImmutable $created_at
 */
class OpenItem extends Model
{
    protected $table = 'ledger_open_items';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_no' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return HasMany<Allocation, $this> */
    public function debitAllocations(): HasMany
    {
        return $this->hasMany(self::modelClass('allocation', Allocation::class), 'debit_item_id');
    }

    /** @return HasMany<Allocation, $this> */
    public function creditAllocations(): HasMany
    {
        return $this->hasMany(self::modelClass('allocation', Allocation::class), 'credit_item_id');
    }
}
