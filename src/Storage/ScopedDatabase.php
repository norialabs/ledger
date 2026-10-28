<?php

declare(strict_types=1);

namespace Noria\Ledger\Storage;

use Brick\Math\BigInteger;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class ScopedDatabase
{
    public function __construct(public Connection $connection, public Scope $scope, private Authorizer $authorizer) {}

    /** @template T
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Permission $permission, Closure $work, ?string $bookId = null): mixed
    {
        $this->authorize($permission, $bookId);
        Assert::that($this->connection->getDriverName() === 'pgsql', 'Ledger requires PostgreSQL.');
        Assert::that($this->connection->getTablePrefix() === '', 'Ledger requires an empty database table prefix.');

        return $this->connection->transaction(function () use ($work): mixed {
            $previous = $this->connection->selectOne("SELECT current_setting('noria.ledger_tenant', true) AS tenant, current_setting('noria.ledger_actor', true) AS actor, current_setting('noria.ledger_firm', true) AS firm");
            Assert::that($previous instanceof stdClass, 'Cannot read ledger database scope.');
            $this->settings($this->scope->tenantId, $this->scope->actorId, $this->scope->firmId ?? '');
            $result = $work();
            $this->settings(Row::nullable($previous, 'tenant') ?? '', Row::nullable($previous, 'actor') ?? '', Row::nullable($previous, 'firm') ?? '');

            return $result;
        }, 3);
    }

    public function table(string $name): Builder
    {
        return $this->connection->table($name)->where($name.'.tenant_id', $this->scope->tenantId);
    }

    public function authorize(Permission $permission, ?string $bookId = null): void
    {
        Assert::that($this->authorizer->allows($this->scope, $permission, $bookId), 'Ledger access denied: '.$permission->value);
    }

    public function allows(Permission $permission, ?string $bookId = null): bool
    {
        return $this->authorizer->allows($this->scope, $permission, $bookId);
    }

    /** @param array<string, mixed> $values */
    public function insert(string $table, array $values): void
    {
        $this->connection->table($table)->insert([...$values, 'tenant_id' => $this->scope->tenantId]);
    }

    public function find(string $table, string $id, ?string $bookId = null): stdClass
    {
        Assert::uuid($id);
        $query = $this->table($table)->where('id', $id);

        if ($bookId !== null) {
            $query->where('book_id', $bookId);
        }

        $row = $query->first();
        Assert::that($row instanceof stdClass, 'Ledger record not found.');

        return $row;
    }

    public function lockBook(string $id): stdClass
    {
        Assert::uuid($id);
        $row = $this->table('ledger_books')->where('id', $id)->lockForUpdate()->first();
        Assert::that($row instanceof stdClass, 'Accounting book not found.');

        return $row;
    }

    public function snapshotBook(Snapshot $snapshot): stdClass
    {
        $book = $this->find('ledger_books', $snapshot->bookId);
        Assert::that(BigInteger::of($snapshot->throughSequence)->isLessThanOrEqualTo(Row::text($book, 'next_sequence')), 'A snapshot cannot use a future knowledge cutoff.');

        return $book;
    }

    public function currency(string $code): Currency
    {
        $row = $this->table('ledger_currencies')->where('code', $code)->first();
        Assert::that($row instanceof stdClass, 'Currency is not registered for this tenant.');

        return new Currency($code, (int) Row::text($row, 'scale'));
    }

    private function settings(string $tenant, string $actor, string $firm): void
    {
        $this->connection->select("SELECT set_config('noria.ledger_tenant', ?, true), set_config('noria.ledger_actor', ?, true), set_config('noria.ledger_firm', ?, true)", [$tenant, $actor, $firm]);
    }
}
