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
 * @property string $closure_id
 * @property string $journal_id
 * @property string $retained_account_id
 * @property array<array-key, mixed> $attribution
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class Restatement extends Model
{
    protected $table = 'ledger_restatements';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attribution' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<YearClosure, $this> */
    public function closure(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('year_closure', YearClosure::class), 'closure_id', 'id');
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

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
