<?php

declare(strict_types=1);

namespace Noria\Ledger\Access;

interface ScopeResolver
{
    public function resolve(): Scope;
}
