<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $recurring_id
 * @property CarbonImmutable $effective_on
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class RecurringCancellation extends Model
{
    protected $table = 'ledger_recurring_cancellations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<RecurringJournal, $this> */
    public function recurring(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('recurring_journal', RecurringJournal::class), 'recurring_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
