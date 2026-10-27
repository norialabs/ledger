<?php

declare(strict_types=1);

namespace Noria\Ledger\Access;

final class DenyAccess implements Authorizer
{
    public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
    {
        return false;
    }
}
