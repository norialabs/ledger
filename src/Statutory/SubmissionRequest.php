<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

use JsonSerializable;
use Noria\Ledger\Support\Assert;

final readonly class SubmissionRequest implements JsonSerializable
{
    public function __construct(public string $bookId, public string $key, public string $kind, public string $sourceId,
        public string $channel, public string $adapter, public string $adapterVersion, public string $environment,
        public string $requestReference, public string $payload, public string $contentType = 'application/json',
        public ?string $originalId = null, public ?string $replacesId = null, public int $maxAttempts = 10)
    {
        Assert::uuid($bookId);
        Assert::uuid($sourceId);
        foreach ([$key, $channel, $adapter, $adapterVersion, $requestReference] as $value) {
            Assert::text($value, 128);
        }
        Assert::that(in_array($kind, ['invoice', 'credit_note', 'tax_return'], true), 'Unknown statutory submission kind.');
        Assert::that(in_array($environment, ['sandbox', 'production'], true), 'Statutory environment must be explicit.');
        Assert::that(in_array($contentType, ['application/json', 'application/xml', 'text/csv'], true), 'Unsupported statutory content type.');
        Assert::that($maxAttempts >= 1 && $maxAttempts <= 1000, 'Invalid statutory send-attempt budget.');
        Assert::text($payload, 10485760);
        Assert::that(preg_match('//u', $payload) === 1, 'Statutory payloads must be UTF-8 text.');
        if ($contentType === 'application/json') {
            $value = json_decode($payload, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            Assert::that($value instanceof \stdClass, 'A JSON statutory payload must be an object.');
        }
        Assert::that(($kind === 'credit_note') === ($originalId !== null), 'A credit note requires its original invoice submission.');
        if ($originalId !== null) {
            Assert::uuid($originalId);
        }
        if ($replacesId !== null) {
            Assert::uuid($replacesId);
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['book_id' => $this->bookId, 'key' => $this->key, 'kind' => $this->kind, 'source_id' => $this->sourceId, 'channel' => $this->channel,
            'adapter' => $this->adapter, 'adapter_version' => $this->adapterVersion, 'environment' => $this->environment, 'request_reference' => $this->requestReference,
            'payload' => $this->payload, 'content_type' => $this->contentType, 'original_id' => $this->originalId, 'replaces_id' => $this->replacesId, 'max_attempts' => $this->maxAttempts];
    }
}
