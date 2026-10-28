<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $statement_id
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
class BankVoid extends Model
{
    protected $table = 'ledger_bank_voids';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BankStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('bank_statement', BankStatement::class), 'statement_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
