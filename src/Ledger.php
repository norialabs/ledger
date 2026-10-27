<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use Illuminate\Database\Connection;
use NoriaLabs\Ledger\Access\Authorizer;
use NoriaLabs\Ledger\Access\Scope;
use NoriaLabs\Ledger\Accounting\PostingPolicy;
use NoriaLabs\Ledger\Storage\ScopedDatabase;

final readonly class Ledger
{
    /** @param list<PostingPolicy> $postingPolicies */
    public function __construct(private Connection $connection, private Authorizer $authorizer, private array $postingPolicies = []) {}

    public function for(Scope $scope): Session
    {
        return new Session(new ScopedDatabase($this->connection, $scope, $this->authorizer), $this->postingPolicies);
    }
}
