<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Scope;

final class LedgerContext
{
    private ?Scope $scope = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function scope(): ?Scope
    {
        return $this->scope;
    }

    public function set(Scope $scope): void
    {
        $this->scope = $scope;
        $this->apply($scope->tenantId, $scope->actorId, $scope->firmId ?? '');
    }

    public function clear(): void
    {
        $this->scope = null;
        $this->apply('', '', '');
    }

    /** @template T
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Scope $scope, Closure $work): mixed
    {
        $previous = $this->scope;
        $this->set($scope);

        try {
            return $work();
        } finally {
            try {
                $previous === null ? $this->clear() : $this->set($previous);
            } catch (QueryException) {
                $this->scope = $previous;
                if ($this->database->connection()->transactionLevel() === 0) {
                    $this->database->disconnect();
                }
            }
        }
    }

    private function apply(string $tenant, string $actor, string $firm): void
    {
        $this->database->connection()->select("SELECT set_config('noria.ledger_tenant', ?, false), set_config('noria.ledger_actor', ?, false), set_config('noria.ledger_firm', ?, false)",
            [$tenant, $actor, $firm]);
    }
}
