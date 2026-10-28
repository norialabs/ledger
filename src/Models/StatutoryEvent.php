<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $submission_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property string $kind
 * @property string|null $mode
 * @property string|null $claim_id
 * @property int|null $lease_seconds
 * @property CarbonImmutable|null $lease_expires_at
 * @property int $delay_seconds
 * @property CarbonImmutable $available_at
 * @property string|null $receipt_reference
 * @property string $channel
 * @property string $environment
 * @property array<array-key, mixed> $evidence
 * @property string $reason
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class StatutoryEvent extends Model
{
    protected $table = 'ledger_statutory_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['lease_seconds' => 'integer', 'lease_expires_at' => 'immutable_datetime', 'delay_seconds' => 'integer', 'available_at' => 'immutable_datetime', 'evidence' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<StatutoryEvent, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('statutory_event', StatutoryEvent::class), 'claim_id', 'id');
    }

    /** @return BelongsTo<StatutorySubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('statutory_submission', StatutorySubmission::class), 'submission_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
