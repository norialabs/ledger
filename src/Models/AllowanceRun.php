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
 * @property string $control_account_id
 * @property string $allowance_account_id
 * @property string $expense_account_id
 * @property CarbonImmutable $effective_on
 * @property array<array-key, mixed> $matrix
 * @property array<array-key, mixed> $exposure
 * @property array<array-key, mixed> $inputs
 * @property array<array-key, mixed> $measurement
 * @property int $through_sequence
 * @property string $required_minor
 * @property string $previous_minor
 * @property string|null $journal_id
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class AllowanceRun extends Model
{
    protected $table = 'ledger_allowance_runs';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'matrix' => 'array', 'exposure' => 'array', 'inputs' => 'array', 'measurement' => 'array', 'through_sequence' => 'integer', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function allowanceAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'allowance_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function controlAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'control_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'expense_account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
