<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $description
 * @property string $debit_account_id
 * @property string $credit_account_id
 * @property string $amount_minor
 * @property array<array-key, mixed> $plan
 * @property string $policy_version
 * @property array<array-key, mixed> $dimensions
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable $created_at
 */
class Schedule extends Model
{
    protected $table = 'ledger_schedules';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['plan' => 'array', 'dimensions' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'credit_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'debit_account_id', 'id');
    }

    /** @return HasMany<ScheduleRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(self::modelClass('schedule_run', ScheduleRun::class), 'schedule_id');
    }
}
