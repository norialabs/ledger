<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Tests\Support;

use NoriaLabs\Ledger\Access\Authorizer;
use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Access\Scope;

final class PermitAll implements Authorizer
{
    public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
    {
        return true;
    }
}
