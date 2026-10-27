<?php

declare(strict_types=1);

namespace Noria\Ledger\Reporting;

use Brick\Math\BigInteger;
use JsonSerializable;
use Noria\Ledger\Support\Assert;

final readonly class Snapshot implements JsonSerializable
{
    public function __construct(public string $bookId, public string $effectiveOn, public string $throughSequence)
    {
        Assert::uuid($bookId);
        Assert::date($effectiveOn);
        Assert::that(preg_match('/^\d{1,19}$/D', $throughSequence) === 1, 'Invalid report sequence.');
        Assert::that(BigInteger::of($throughSequence)->isLessThanOrEqualTo('9223372036854775807'), 'Report sequence exceeds the database range.');
    }

    /** @return array{book_id: string, effective_on: string, through_sequence: string} */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'effective_on' => $this->effectiveOn, 'through_sequence' => $this->throughSequence];
    }
}
