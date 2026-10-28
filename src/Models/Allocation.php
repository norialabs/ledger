<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $debit_item_id
 * @property string $credit_item_id
 * @property string $amount_minor
 * @property string $debit_functional_minor
 * @property string $credit_functional_minor
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property string|null $reverses_id
 * @property string|null $fx_journal_id
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
class Allocation extends Model
{
    protected $table = 'ledger_allocations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<OpenItem, $this> */
    public function creditItem(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('open_item', OpenItem::class), 'credit_item_id', 'id');
    }

    /** @return BelongsTo<OpenItem, $this> */
    public function debitItem(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('open_item', OpenItem::class), 'debit_item_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function fxJournal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'fx_journal_id', 'id');
    }

    /** @return BelongsTo<Allocation, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('allocation', Allocation::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
