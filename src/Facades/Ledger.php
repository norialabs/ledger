<?php

declare(strict_types=1);

namespace Noria\Ledger\Facades;

use Illuminate\Support\Facades\Facade;

/** @phpstan-method static \Noria\Ledger\Session for(\Noria\Ledger\Access\Scope $scope)
 * @phpstan-method static \Noria\Ledger\Session current()
 */
final class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Noria\Ledger\Ledger::class;
    }
}
