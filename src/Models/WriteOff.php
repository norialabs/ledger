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
 * @property string $item_id
 * @property string $account_id
 * @property string $amount_minor
 * @property CarbonImmutable $effective_on
 * @property string $journal_id
 * @property string $allocation_id
 * @property string|null $reverses_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class WriteOff extends Model
{
    protected $table = 'ledger_write_offs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'account_id', 'id');
    }

    /** @return BelongsTo<Allocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('allocation', Allocation::class), 'allocation_id', 'id');
    }

    /** @return BelongsTo<OpenItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('open_item', OpenItem::class), 'item_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<WriteOff, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('write_off', WriteOff::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
