<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $schedule_id
 * @property int $line_no
 * @property string $journal_id
 * @property CarbonImmutable $created_at
 */
class ScheduleRun extends Model
{
    protected $table = 'ledger_schedule_runs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_no' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('schedule', Schedule::class), 'schedule_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
