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
 * @property string $reference
 * @property string $name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Party extends Model
{
    protected $table = 'ledger_parties';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Entity, $this> */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('entity', Entity::class), 'entity_id', 'id');
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(self::modelClass('posting', Posting::class), 'party_id')->orderBy('line_no');
    }
}
