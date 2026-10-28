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
 * @property string $bank_line_id
 * @property string $journal_id
 * @property int $line_no
 * @property string $amount_minor
 * @property CarbonImmutable $effective_on
 * @property CarbonImmutable $recorded_at
 * @property int $sequence
 * @property string $actor_id
 * @property string|null $firm_id
 * @property string $reason
 * @property string|null $reverses_id
 * @property CarbonImmutable $created_at
 */
class BankMatch extends Model
{
    protected $table = 'ledger_bank_matches';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_no' => 'integer', 'effective_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<BankLine, $this> */
    public function bankLine(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('bank_line', BankLine::class), 'bank_line_id', 'id');
    }

    /** @return BelongsTo<BankMatch, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('bank_match', BankMatch::class), 'reverses_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }
}
