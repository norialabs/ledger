<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $description
 * @property array<array-key, mixed> $postings
 * @property array<array-key, mixed> $metadata
 * @property string $actor_id
 * @property string|null $firm_id
 * @property string|null $reverses_id
 * @property bool $closing
 * @property string|null $source_type
 * @property string|null $source_reference
 * @property array<array-key, mixed> $policy_versions
 * @property CarbonImmutable $created_at
 */
class Journal extends Model
{
    protected $table = 'ledger_journals';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'postings' => 'array', 'metadata' => 'array', 'closing' => 'boolean', 'policy_versions' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'reverses_id', 'id');
    }

    /** @return HasMany<Posting, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(self::modelClass('posting', Posting::class), 'journal_id')->orderBy('line_no');
    }

    /** @return HasMany<OpenItem, $this> */
    public function openItems(): HasMany
    {
        return $this->hasMany(self::modelClass('open_item', OpenItem::class), 'journal_id');
    }

    /** @return HasOne<Journal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::modelClass('journal', Journal::class), 'reverses_id');
    }
}
