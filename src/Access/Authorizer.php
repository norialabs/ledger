<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Access;

interface Authorizer
{
    public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool;
}
