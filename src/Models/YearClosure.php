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
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string|null $journal_id
 * @property string $retained_account_id
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
class YearClosure extends Model
{
    protected $table = 'ledger_year_closures';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function retainedAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'retained_account_id', 'id');
    }
}
