<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $code
 * @property int $scale
 * @property CarbonImmutable $created_at
 */
class CurrencyDefinition extends Model
{
    protected $table = 'ledger_currency_definitions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scale' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
