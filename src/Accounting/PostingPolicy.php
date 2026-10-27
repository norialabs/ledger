<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Accounting;

use NoriaLabs\Ledger\Access\Scope;

interface PostingPolicy
{
    public function version(): string;

    public function validate(Scope $scope, JournalRequest $request): void;
}
