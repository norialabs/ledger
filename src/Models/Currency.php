<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property int $scale
 * @property CarbonImmutable $created_at
 */
class Currency extends Model
{
    protected $table = 'ledger_currencies';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scale' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
