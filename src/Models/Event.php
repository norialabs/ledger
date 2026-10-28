<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $book_id
 * @property int $sequence
 * @property int $audit_id
 * @property CarbonImmutable $created_at
 */
class Event extends Model
{
    protected $table = 'ledger_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AuditEvent, $this> */
    public function audit(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('audit_event', AuditEvent::class), 'audit_id', 'id');
    }

    /** @return BelongsTo<Book, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(self::modelClass('book', Book::class), 'book_id', 'id');
    }
}
