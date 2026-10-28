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
 * @property int $occurrence
 * @property string $kind
 * @property string $journal_id
 * @property CarbonImmutable $created_at
 */
class RecurringRun extends Model
{
    protected $table = 'ledger_recurring_runs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurrence' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
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
