<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $base_code
 * @property string $quote_code
 * @property string $rate_type
 * @property CarbonImmutable $effective_on
 * @property int $revision
 * @property string $rate
 * @property string $source
 * @property string|null $reason
 * @property CarbonImmutable $recorded_at
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class ExchangeRate extends Model
{
    protected $table = 'ledger_exchange_rates';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'revision' => 'integer', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Currency, $this> */
    public function base(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('currency', Currency::class), 'base_code', 'code');
    }

    /** @return BelongsTo<Currency, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('currency', Currency::class), 'quote_code', 'code');
    }
}
