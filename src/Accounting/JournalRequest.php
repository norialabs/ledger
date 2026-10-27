<?php

declare(strict_types=1);

namespace Noria\Ledger\Accounting;

use JsonSerializable;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;

final readonly class JournalRequest implements JsonSerializable
{
    /** @param array<array-key, Posting> $postings
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $bookId,
        public string $key,
        public string $effectiveOn,
        public string $description,
        public array $postings,
        public array $metadata = [],
        public ?string $reversesId = null,
        public bool $closing = false,
        public ?Source $source = null,
    ) {
        Assert::uuid($bookId);
        Assert::text($key, 128);
        Assert::date($effectiveOn);
        Assert::text($description, 1024);
        Assert::that(count($postings) >= 2 && count($postings) <= 10000 && array_is_list($postings), 'A journal requires between 2 and 10,000 ordered postings.');
        Canonical::json($metadata);

        if ($reversesId !== null) {
            Assert::uuid($reversesId);
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'effective_on' => $this->effectiveOn, 'description' => $this->description, 'postings' => $this->postings, 'metadata' => $this->metadata, 'reverses_id' => $this->reversesId, 'closing' => $this->closing, 'source' => $this->source];
    }
}
