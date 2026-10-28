<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $contract_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property string $kind
 * @property array<array-key, mixed> $inputs
 * @property string $carrying_delta
 * @property string $allowance_delta
 * @property string $written_off_delta
 * @property string $recovered_delta
 * @property int $stage
 * @property int $prior_stage
 * @property string|null $journal_id
 * @property string|null $reverses_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class FinanceEvent extends Model
{
    protected $table = 'ledger_finance_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'inputs' => 'array', 'stage' => 'integer', 'prior_stage' => 'integer', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<FinanceContract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('finance_contract', FinanceContract::class), 'contract_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<FinanceEvent, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('finance_event', FinanceEvent::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
