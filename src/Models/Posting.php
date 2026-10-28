<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property string $journal_id
 * @property int $line_no
 * @property string $account_id
 * @property string $side
 * @property string $amount_minor
 * @property string $functional_minor
 * @property string $currency_code
 * @property string $rate
 * @property CarbonImmutable $rate_as_of
 * @property string $rate_source
 * @property string $rounding
 * @property string|null $party_id
 * @property CarbonImmutable|null $due_on
 * @property array<array-key, mixed> $dimensions
 * @property string $description
 * @property bool $valuation
 * @property CarbonImmutable $created_at
 */
class Posting extends Model
{
    protected $table = 'ledger_postings';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['line_no' => 'integer', 'rate_as_of' => 'immutable_date', 'due_on' => 'immutable_date', 'dimensions' => 'array', 'valuation' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('account', Account::class), 'account_id', 'id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('currency', Currency::class), 'currency_code', 'code');
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('journal', Journal::class), 'journal_id', 'id');
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('party', Party::class), 'party_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
