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
 * @property CarbonImmutable $effective_on
 * @property string $description
 * @property array<array-key, mixed> $request
 * @property string|null $supersedes_id
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class JournalDraft extends Model
{
    protected $table = 'ledger_journal_drafts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'request' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<JournalDraft, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal_draft', JournalDraft::class), 'supersedes_id', 'id');
    }

    /** @return HasMany<DraftDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(self::modelClass('draft_decision', DraftDecision::class), 'draft_id');
    }
}
