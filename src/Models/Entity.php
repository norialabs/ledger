<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $country
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Entity extends Model
{
    protected $table = 'ledger_entities';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    /** @return HasMany<Book, $this> */
    public function books(): HasMany
    {
        return $this->hasMany(self::modelClass('book', Book::class), 'entity_id');
    }

    /** @return HasMany<Party, $this> */
    public function parties(): HasMany
    {
        return $this->hasMany(self::modelClass('party', Party::class), 'entity_id');
    }

    /** @return HasMany<DimensionValue, $this> */
    public function dimensionValues(): HasMany
    {
        return $this->hasMany(self::modelClass('dimension_value', DimensionValue::class), 'entity_id');
    }
}
