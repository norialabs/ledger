<?php

declare(strict_types=1);

namespace Noria\Ledger\Tests\Support;

use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;

final class PermitAll implements Authorizer
{
    public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
    {
        return true;
    }
}
