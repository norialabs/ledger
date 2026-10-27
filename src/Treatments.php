<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Treatments
{
    /** @var array<string, PostingTreatment> */
    private array $registry;

    /** @param list<PostingTreatment> $implementations */
    public function __construct(private ScopedDatabase $db, private Journals $journals, private Configurations $configurations, array $implementations = [])
    {
        $registry = [];
        foreach ($implementations as $implementation) {
            Assert::text($implementation->name(), 128);
            Assert::text($implementation->version(), 128);
            $key = Canonical::json([$implementation->name(), $implementation->version()]);
            Assert::that(! isset($registry[$key]), 'Posting treatment name and version must be unique.');
            $registry[$key] = $implementation;
        }
        $this->registry = $registry;
    }

    public function post(TreatmentRequest $request): Journal
    {
        return $this->db->run(Permission::Post, function () use ($request): Journal {
            $this->db->lockBook($request->bookId);
            $configuration = $this->configurations->get($request->bookId, $request->configurationId, $request->effectiveOn);
            $hash = Canonical::hash($request);
            $existing = $this->db->table('ledger_journals')->where('book_id', $request->bookId)->where('idempotency_key', $request->key)->first();
            if ($existing instanceof stdClass) {
                $evidence = Row::json($existing, 'metadata')['treatment'] ?? null;
                Assert::that(is_array($evidence) && ($evidence['request_hash'] ?? null) === $hash, 'Treatment retry key already contains different inputs.');

                return Journal::fromRow($existing);
            }

            $implementation = $this->registry[Canonical::json([$request->treatment, $request->version])] ?? null;
            Assert::that($implementation instanceof PostingTreatment, 'The requested posting treatment version is not registered.');
            if (! $implementation instanceof PostingTreatment) {
                throw new \LogicException('Missing posting treatment.');
            }
            $prepared = $implementation->prepare($this->db->scope, $request, $configuration['roles']);
            Assert::that($prepared->bookId === $request->bookId && $prepared->key === $request->key && $prepared->effectiveOn === $request->effectiveOn
                && $prepared->source == $request->source && ! $prepared->closing && $prepared->reversesId === null, 'A posting treatment cannot change its accounting identity or create closing/reversal entries.');

            return $this->journals->post(new JournalRequest($prepared->bookId, $prepared->key, $prepared->effectiveOn, $prepared->description, $prepared->postings,
                [...$prepared->metadata, 'treatment' => ['name' => $request->treatment, 'version' => $request->version, 'request_hash' => $hash,
                    'request' => $request, 'configuration_hash' => $configuration['content_hash']]], source: $prepared->source));
        }, $request->bookId);
    }
}
