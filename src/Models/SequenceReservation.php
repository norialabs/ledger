<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property int $size
 * @property int $after_sequence
 * @property int $through_sequence
 * @property int $transaction_xid
 * @property CarbonImmutable $recorded_at
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class SequenceReservation extends Model
{
    protected $table = 'ledger_sequence_reservations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer', 'after_sequence' => 'integer', 'through_sequence' => 'integer', 'transaction_xid' => 'integer',
            'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
