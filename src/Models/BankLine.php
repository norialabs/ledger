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
 * @property string $statement_id
 * @property string $account_id
 * @property int $line_no
 * @property string $external_id
 * @property CarbonImmutable $value_on
 * @property string $amount_minor
 * @property string $description
 * @property CarbonImmutable $created_at
 */
class BankLine extends Model
{
    protected $table = 'ledger_bank_lines';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_no' => 'integer', 'value_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'account_id', 'id');
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

    /** @return HasMany<BankMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(self::modelClass('bank_match', BankMatch::class), 'bank_line_id');
    }
}
