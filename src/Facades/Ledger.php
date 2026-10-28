<?php

declare(strict_types=1);

namespace Noria\Ledger\Facades;

use Illuminate\Support\Facades\Facade;

final class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Noria\Ledger\Ledger::class;
    }
}
