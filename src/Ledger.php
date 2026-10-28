<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Access\ScopeResolver;
use Noria\Ledger\Accounting\PostingPolicy;
use Noria\Ledger\Accounting\PostingTreatment;
use Noria\Ledger\Storage\ScopedDatabase;

final readonly class Ledger
{
    /** @param list<PostingPolicy> $postingPolicies
     * @param  list<PostingTreatment>  $postingTreatments
     */
    public function __construct(private Connection $connection, private Authorizer $authorizer, private array $postingPolicies = [], private ?Container $container = null, private array $postingTreatments = [],
        private ?ScopeResolver $scopeResolver = null) {}

    public function for(Scope $scope): Session
    {
        return new Session(new ScopedDatabase($this->connection, $scope, $this->authorizer), $this->postingPolicies, $this->container, $this->postingTreatments);
    }

    public function current(): Session
    {
        if ($this->scopeResolver === null) {
            throw new Exceptions\LedgerException('Configure ledger.scope_resolver to use the current scope.');
        }

        return $this->for($this->scopeResolver->resolve());
    }
}
