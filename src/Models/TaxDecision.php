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
 * @property string $scheme
 * @property string $configuration_id
 * @property CarbonImmutable $effective_on
 * @property string $journal_id
 * @property int $line_no
 * @property string $tax_code
 * @property string $kind
 * @property string $fraction
 * @property string|null $classification
 * @property string $reason
 * @property array<array-key, mixed> $evidence
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $created_at
 */
class TaxDecision extends Model
{
    protected $table = 'ledger_tax_decisions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effective_on' => 'immutable_date', 'line_no' => 'integer', 'evidence' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
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
}
