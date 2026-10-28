<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $entity_id
 * @property string $name
 * @property string $currency_code
 * @property int $next_sequence
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Book extends Model
{
    protected $table = 'ledger_books';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('currency', Currency::class), 'currency_code', 'code');
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('entity', Entity::class), 'entity_id', 'id');
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(self::modelClass('account', Account::class), 'book_id')->orderBy('code');
    }

    /** @return HasMany<Period, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(self::modelClass('period', Period::class), 'book_id');
    }

    /** @return HasMany<Journal, $this> */
    public function journals(): HasMany
    {
        return $this->hasMany(self::modelClass('journal', Journal::class), 'book_id');
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(self::modelClass('budget', Budget::class), 'book_id');
    }

    /** @return HasMany<Configuration, $this> */
    public function configurations(): HasMany
    {
        return $this->hasMany(self::modelClass('configuration', Configuration::class), 'book_id');
    }
}
