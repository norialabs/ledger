<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use Noria\Ledger\Access\Scope;

interface PostingTreatment
{
    public function name(): string;

    public function version(): string;

    /** @param array<string, string> $accounts */
    public function prepare(Scope $scope, TreatmentRequest $request, array $accounts): JournalRequest;
}
