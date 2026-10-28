<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $associate_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property CarbonImmutable $effective_on
 * @property string $kind
 * @property string $source_minor
 * @property string $share_minor
 * @property string $carrying_delta
 * @property string $liability_delta
 * @property string $unrecognized_profit_delta
 * @property string $unrecognized_oci_delta
 * @property string $impairment_delta
 * @property string $obligation_limit_minor
 * @property string $prior_limit_minor
 * @property string|null $unimpaired_minor
 * @property string|null $counter_account_id
 * @property string|null $fraction
 * @property string|null $gain_account_id
 * @property string|null $loss_account_id
 * @property string|null $oci_transfer_account_id
 * @property string|null $retained_account_id
 * @property string|null $retained_fair_value_minor
 * @property string $oci_release_minor
 * @property string|null $net_assets_minor
 * @property string|null $journal_id
 * @property string|null $reverses_id
 * @property array<array-key, mixed> $evidence
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class AssociateEvent extends Model
{
    protected $table = 'ledger_associate_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'evidence' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Associate, $this> */
    public function associate(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('associate', Associate::class), 'associate_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'counter_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function gainAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'gain_account_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function lossAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'loss_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function ociTransferAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'oci_transfer_account_id', 'id');
    }

    /** @return BelongsTo<Account, $this> */
    public function retainedAccount(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'retained_account_id', 'id');
    }

    /** @return BelongsTo<AssociateEvent, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('associate_event', AssociateEvent::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
