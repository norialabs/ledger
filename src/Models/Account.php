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
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $normal_side
 * @property string|null $currency_code
 * @property string|null $parent_id
 * @property bool $is_group
 * @property bool $is_control
 * @property bool $active
 * @property string|null $cash_flow
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Account extends Model
{
    protected $table = 'ledger_accounts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_group' => 'boolean', 'is_control' => 'boolean', 'active' => 'boolean', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('currency', Currency::class), 'currency_code', 'code');
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'parent_id', 'id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::modelClass('account', Account::class), 'parent_id')->orderBy('code');
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(self::modelClass('posting', Posting::class), 'account_id')->orderBy('line_no');
    }

    /** @return HasMany<AccountDimension, $this> */
    public function requiredDimensions(): HasMany
    {
        return $this->hasMany(self::modelClass('account_dimension', AccountDimension::class), 'account_id');
    }
}
