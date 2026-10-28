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
 * @property string $kind
 * @property string|null $journal_id
 * @property string|null $return_id
 * @property string|null $original_id
 * @property string|null $replaces_id
 * @property string $channel
 * @property string $environment
 * @property string $adapter
 * @property string $adapter_version
 * @property string $request_reference
 * @property string $content_type
 * @property string $payload
 * @property string $payload_hash
 * @property string $source_hash
 * @property string $source_currency
 * @property int $source_scale
 * @property string $gross_minor
 * @property int $max_attempts
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class StatutorySubmission extends Model
{
    protected $table = 'ledger_statutory_submissions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['source_scale' => 'integer', 'max_attempts' => 'integer', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<StatutorySubmission, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('statutory_submission', StatutorySubmission::class), 'original_id', 'id');
    }

    /** @return BelongsTo<StatutorySubmission, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('statutory_submission', StatutorySubmission::class), 'replaces_id', 'id');
    }

    /** @return BelongsTo<TaxReturn, $this> */
    public function return(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('tax_return', TaxReturn::class), 'return_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
