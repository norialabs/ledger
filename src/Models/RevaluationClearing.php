<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $item_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property string $reason
 * @property array<array-key, mixed> $journal_ids
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class RevaluationClearing extends Model
{
    protected $table = 'ledger_revaluation_clearings';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'journal_ids' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<OpenItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('open_item', OpenItem::class), 'item_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
