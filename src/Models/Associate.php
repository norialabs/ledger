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
 * @property string $investee_entity_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $reference
 * @property CarbonImmutable $effective_on
 * @property string $ownership_fraction
 * @property string $cost_minor
 * @property string $fair_value_net_assets_minor
 * @property string $carrying_minor
 * @property string $goodwill_minor
 * @property string $bargain_minor
 * @property string $investment_account_id
 * @property string $funding_account_id
 * @property string $profit_account_id
 * @property string $oci_account_id
 * @property string $dividend_account_id
 * @property string $impairment_account_id
 * @property string|null $loss_liability_account_id
 * @property string $influence_basis
 * @property string $policy_version
 * @property string $dividend_cash_flow
 * @property array<array-key, mixed> $dimensions
 * @property string|null $journal_id
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class Associate extends Model
{
    protected $table = 'ledger_associates';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'dimensions' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function dividendAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'dividend_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'funding_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function impairmentAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'impairment_account_id', 'id');
    }

    /** @return BelongsTo<Entity, $this> */
    public function investeeEntity(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('entity', Entity::class), 'investee_entity_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function investmentAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'investment_account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function lossLiabilityAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'loss_liability_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function ociAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'oci_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function profitAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'profit_account_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return HasMany<AssociateEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(self::modelClass('associate_event', AssociateEvent::class), 'associate_id');
    }
}
