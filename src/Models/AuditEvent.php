<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string $table_name
 * @property string $operation
 * @property array<array-key, mixed>|null $before_value
 * @property array<array-key, mixed>|null $after_value
 * @property string $actor_id
 * @property string|null $firm_id
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable $created_at
 */
class AuditEvent extends Model
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $table = 'ledger_audit_events';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before_value' => 'array', 'after_value' => 'array', 'recorded_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
