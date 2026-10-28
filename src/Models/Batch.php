<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $description
 * @property array<array-key, mixed> $members
 * @property string|null $reverses_id
 * @property CarbonImmutable $created_at
 */
class Batch extends Model
{
    protected $table = 'ledger_batches';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['members' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Batch, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('batch', Batch::class), 'reverses_id', 'id');
    }

    /** @return HasMany<BatchMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(self::modelClass('batch_member', BatchMember::class), 'batch_id');
    }
}
