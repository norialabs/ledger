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
 * @property string $configuration_id
 * @property string $scheme
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $effective_on
 * @property int $through_sequence
 * @property string|null $previous_id
 * @property string|null $prior_period_id
 * @property string $opening_credit_minor
 * @property string|null $opening_reference
 * @property array<array-key, mixed> $result_json
 * @property string $result_hash
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class TaxReturn extends Model
{
    protected $table = 'ledger_tax_returns';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'effective_on' => 'immutable_date', 'result_json' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Configuration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('configuration', Configuration::class), 'configuration_id', 'id');
    }

    /** @return BelongsTo<TaxReturn, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('tax_return', TaxReturn::class), 'previous_id', 'id');
    }

    /** @return BelongsTo<TaxReturn, $this> */
    public function priorPeriod(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('tax_return', TaxReturn::class), 'prior_period_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
