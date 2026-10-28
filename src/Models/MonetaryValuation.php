<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $account_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property array<array-key, mixed> $dimensions
 * @property string $source_minor
 * @property string $before_minor
 * @property string $delta_minor
 * @property string $rate
 * @property CarbonImmutable $rate_as_of
 * @property string $rate_source
 * @property string $rounding
 * @property string|null $journal_id
 * @property string|null $reverses_id
 * @property string $kind
 * @property string|null $trigger_journal_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class MonetaryValuation extends Model
{
    protected $table = 'ledger_monetary_valuations';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'dimensions' => 'array', 'rate_as_of' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<MonetaryValuation, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('monetary_valuation', MonetaryValuation::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function triggerJournal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'trigger_journal_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
