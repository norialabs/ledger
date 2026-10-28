<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

final readonly class Submission
{
    /** @param array<string, mixed> $source */
    public function __construct(public string $tenantId, public string $bookId, public string $id, public string $claimId, public string $mode,
        public string $channel, public string $environment, public string $adapter, public string $adapterVersion, public string $requestReference,
        public string $contentType, public string $payload, public string $payloadHash, public string $sourceHash, public array $source,
        public ?string $originalReceipt, public int $attempts) {}
}
