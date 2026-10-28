<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class Outcome
{
    /** @param array<string, mixed> $evidence */
    public function __construct(public string $status, public string $reason, public array $evidence = [], public ?string $receiptReference = null, public int $delaySeconds = 0)
    {
        Assert::that(in_array($status, ['accepted', 'rejected', 'retry', 'uncertain', 'not_found'], true), 'Unknown statutory delivery outcome.');
        Assert::text($reason, 1024);
        Assert::that(($status === 'accepted') === ($receiptReference !== null), 'Only acceptance has a statutory receipt.');
        if ($receiptReference !== null) {
            Assert::text($receiptReference, 256);
        }
        Assert::that($delaySeconds >= 0 && $delaySeconds <= 86400 && (in_array($status, ['retry', 'uncertain', 'not_found'], true) || $delaySeconds === 0), 'Invalid statutory retry delay.');
        Canonical::json($evidence);
    }
}
