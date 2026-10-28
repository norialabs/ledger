<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $name
 * @property string $version
 * @property array<array-key, mixed> $definition_json
 * @property string $definition_hash
 * @property array<array-key, mixed> $account_ids
 * @property array<array-key, mixed> $roles
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class Configuration extends Model
{
    protected $table = 'ledger_configurations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['definition_json' => 'array', 'account_ids' => 'array', 'roles' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
