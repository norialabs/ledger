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
 * @property string $account_id
 * @property string $reference
 * @property string $fingerprint
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $effective_on
 * @property string $opening_minor
 * @property string $closing_minor
 * @property array<array-key, mixed> $movements
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class BankStatement extends Model
{
    protected $table = 'ledger_bank_statements';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'effective_on' => 'immutable_date', 'movements' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'account_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return HasMany<BankLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(self::modelClass('bank_line', BankLine::class), 'statement_id');
    }
}
