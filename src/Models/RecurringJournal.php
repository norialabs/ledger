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
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $description
 * @property array<array-key, mixed> $postings
 * @property array<array-key, mixed> $metadata
 * @property string $rate_type
 * @property string $unit
 * @property int $every
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property int|null $occurrences
 * @property int|null $due_after_days
 * @property bool $auto_reverse
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class RecurringJournal extends Model
{
    protected $table = 'ledger_recurring_journals';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['postings' => 'array', 'metadata' => 'array', 'every' => 'integer', 'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'occurrences' => 'integer', 'due_after_days' => 'integer', 'auto_reverse' => 'boolean', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return HasMany<RecurringRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(self::modelClass('recurring_run', RecurringRun::class), 'recurring_id');
    }
}
