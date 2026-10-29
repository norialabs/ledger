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
 * @property string $reference
 * @property string $kind
 * @property CarbonImmutable $effective_on
 * @property string $configuration_id
 * @property array<array-key, mixed> $inputs
 * @property array<array-key, mixed> $dimensions
 * @property string $carrying_account_id
 * @property string|null $allowance_account_id
 * @property string|null $asset_account_id
 * @property string|null $liability_account_id
 * @property string|null $refund_account_id
 * @property string $opening_minor
 * @property string|null $journal_id
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class FinanceContract extends Model
{
    protected $table = 'ledger_finance_contracts';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'inputs' => 'array', 'dimensions' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function allowanceAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'allowance_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'asset_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function liabilityAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'liability_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function refundAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'refund_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function carryingAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'carrying_account_id', 'id');
    }

    /** @return BelongsTo<Configuration, $this> */
    public function configuration(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('configuration', Configuration::class), 'configuration_id', 'id');
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

    /** @return HasMany<FinanceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(self::modelClass('finance_event', FinanceEvent::class), 'contract_id');
    }
}
