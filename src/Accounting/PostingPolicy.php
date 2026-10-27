<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use Noria\Ledger\Access\Scope;

interface PostingPolicy
{
    public function version(): string;

    public function validate(Scope $scope, JournalRequest $request): void;
}
