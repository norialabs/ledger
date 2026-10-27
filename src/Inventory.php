<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Illuminate\Support\Str;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Inventory\Movement;
use Noria\Ledger\Inventory\Production;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Inventory\StockBalance;
use Noria\Ledger\Inventory\Transfer;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use stdClass;

readonly class Inventory
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    /** @param array<string, string> $dimensions */
    public function pool(string $bookId, string $reference, string $sku, string $accountId, ValuationMethod $method, array $dimensions = []): string
    {
        Assert::text($reference, 128);
        Assert::text($sku, 128);

        return $this->db->run(Permission::Configure, function () use ($bookId, $reference, $sku, $accountId, $method, $dimensions): string {
            $this->db->lockBook($bookId);
            $fingerprint = Canonical::hash([$sku, $accountId, $method->value, $dimensions]);
            $existing = $this->db->table('ledger_inventory_pools')->where('book_id', $bookId)->where('reference', $reference)->first();

            if ($existing instanceof stdClass) {
                Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Inventory pool reference was already used with different configuration.');

                return Row::text($existing, 'id');
            }

            $id = (string) Str::uuid7();
            $this->db->insert('ledger_inventory_pools', ['id' => $id, 'book_id' => $bookId, 'reference' => $reference, 'sku' => $sku,
                'account_id' => $accountId, 'method' => $method->value, 'fingerprint' => $fingerprint, 'dimensions' => $dimensions === [] ? '{}' : Canonical::json($dimensions)]);

            return $id;
        }, $bookId);
    }

    public function receive(string $bookId, string $poolId, string $key, string $date, Quantity $quantity, Money $cost, string $counterAccountId, string $reason): Movement
    {
        Assert::that($quantity->value->isPositive() && ! $cost->minor->isNegative(), 'A receipt requires positive quantity and non-negative cost.');

        return $this->move($bookId, $poolId, $key, $date, 'receipt', $quantity, $cost, $counterAccountId, $reason);
    }

    public function issue(string $bookId, string $poolId, string $key, string $date, Quantity $quantity, string $counterAccountId, string $reason): Movement
    {
        Assert::that($quantity->value->isPositive(), 'An issue requires a positive quantity.');

        return $this->move($bookId, $poolId, $key, $date, 'issue', new Quantity((string) $quantity->value->negated()), null, $counterAccountId, $reason);
    }

    public function adjustValue(string $bookId, string $poolId, string $key, string $date, Money $delta, string $counterAccountId, string $reason): Movement
    {
        Assert::that(! $delta->minor->isZero(), 'A value adjustment must have an effect.');

        return $this->move($bookId, $poolId, $key, $date, 'adjustment', new Quantity('0'), $delta, $counterAccountId, $reason);
    }

    public function reverse(string $bookId, string $movementId, string $key, string $date, string $reason): Movement
    {
        return $this->reverseMovement($bookId, $movementId, $key, $date, $reason);
    }

    public function returnIssue(string $bookId, string $issueId, string $key, string $date, Quantity $quantity, string $reason, ?string $destinationPoolId = null): Movement
    {
        Assert::that($quantity->value->isPositive(), 'A stock return requires a positive quantity.');

        return $this->db->run(Permission::Inventory, function () use ($bookId, $issueId, $key, $date, $quantity, $reason, $destinationPoolId): Movement {
            $this->db->lockBook($bookId);
            $original = $this->db->find('ledger_inventory_moves', $issueId, $bookId);

            return $this->move($bookId, $destinationPoolId ?? Row::text($original, 'pool_id'), $key, $date, 'return', $quantity, null,
                Row::text($original, 'counter_account_id'), $reason, $issueId);
        }, $bookId);
    }

    public function transfer(string $bookId, string $sourcePoolId, string $destinationPoolId, string $key, string $date, Quantity $quantity, string $clearingAccountId, string $reason): Transfer
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);
        Assert::that($quantity->value->isPositive() && $sourcePoolId !== $destinationPoolId, 'A transfer requires positive quantity and different pools.');

        return $this->db->run(Permission::Inventory, function () use ($bookId, $sourcePoolId, $destinationPoolId, $key, $date, $quantity, $clearingAccountId, $reason): Transfer {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $fingerprint = Canonical::hash([$sourcePoolId, $destinationPoolId, $date, $quantity, $clearingAccountId, $reason]);
            $existing = $this->replayTransfer($bookId, $key, $fingerprint, $currency);

            if ($existing !== null) {
                return $existing;
            }

            $source = $this->db->find('ledger_inventory_pools', $sourcePoolId, $bookId);
            $destination = $this->db->find('ledger_inventory_pools', $destinationPoolId, $bookId);
            Assert::that(Row::text($source, 'sku') === Row::text($destination, 'sku'), 'Transfers require the same SKU.');
            $id = (string) Str::uuid7();
            $issue = $this->move($bookId, $sourcePoolId, 'transfer-out:'.hash('sha256', $key), $date, 'issue', new Quantity((string) $quantity->value->negated()), null,
                $clearingAccountId, $reason, transferId: $id);
            $receipt = $this->move($bookId, $destinationPoolId, 'transfer-in:'.hash('sha256', $key), $date, 'receipt', $quantity, $issue->value->negated(),
                $clearingAccountId, $reason, transferId: $id);
            $this->db->insert('ledger_inventory_transfers', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'source_pool_id' => $sourcePoolId, 'destination_pool_id' => $destinationPoolId, 'issue_id' => $issue->id,
                'receipt_id' => $receipt->id, 'quantity' => $quantity->decimal(), 'value_minor' => (string) $receipt->value->minor,
                'clearing_account_id' => $clearingAccountId, 'reason' => $reason]);

            return new Transfer($id, $issue, $receipt);
        }, $bookId);
    }

    public function returnReceipt(string $bookId, string $receiptId, string $key, string $date, Quantity $quantity, string $counterAccountId, string $varianceAccountId, string $reason): Movement
    {
        Assert::that($quantity->value->isPositive(), 'A supplier return requires a positive quantity.');

        return $this->db->run(Permission::Inventory, function () use ($bookId, $receiptId, $key, $date, $quantity, $counterAccountId, $varianceAccountId, $reason): Movement {
            $this->db->lockBook($bookId);
            $original = $this->db->find('ledger_inventory_moves', $receiptId, $bookId);

            return $this->move($bookId, Row::text($original, 'pool_id'), $key, $date, 'supplier_return', new Quantity((string) $quantity->value->negated()),
                null, $counterAccountId, $reason, $receiptId, varianceAccountId: $varianceAccountId);
        }, $bookId);
    }

    /** @param non-empty-list<array{pool: string, quantity: Quantity}> $inputs */
    public function produce(string $bookId, array $inputs, string $outputPoolId, Quantity $outputQuantity, string $wipAccountId, string $key, string $date, string $reason, ?Money $conversionCost = null): Production
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);
        Assert::that($inputs !== [] && array_is_list($inputs) && count($inputs) <= 10000 && $outputQuantity->value->isPositive(), 'Production requires ordered components and a positive output quantity.');
        foreach ($inputs as $input) {
            Assert::that($input['quantity']->value->isPositive() && $input['pool'] !== $outputPoolId, 'Production requires positive component quantities in pools distinct from its output.');
        }

        return $this->db->run(Permission::Inventory, function () use ($bookId, $inputs, $outputPoolId, $outputQuantity, $wipAccountId, $key, $date, $reason, $conversionCost): Production {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $conversionCost ??= Money::minor('0', $currency);
            Assert::that($conversionCost->currency == $currency && ! $conversionCost->minor->isNegative(), 'Conversion costs require a non-negative functional-currency amount.');
            $fingerprint = Canonical::hash([$inputs, $outputPoolId, $outputQuantity, $wipAccountId, $date, $reason, $conversionCost]);
            $legacyFingerprint = $conversionCost->minor->isZero() ? Canonical::hash([$inputs, $outputPoolId, $outputQuantity, $wipAccountId, $date, $reason]) : null;
            $existing = $this->replayProduction($bookId, $key, $fingerprint, $currency, $legacyFingerprint);

            if ($existing !== null) {
                return $existing;
            }

            $id = (string) Str::uuid7();
            $movements = [];
            $consumed = Money::minor('0', $currency);

            foreach ($inputs as $index => $input) {
                $movement = $this->move($bookId, $input['pool'], 'produce-in:'.hash('sha256', $key.':'.$index), $date, 'issue',
                    new Quantity((string) $input['quantity']->value->negated()), null, $wipAccountId, $reason, productionId: $id);
                $movements[] = ['movement_id' => $movement->id, 'pool_id' => $input['pool'], 'quantity' => $movement->quantity->decimal(), 'value_minor' => (string) $movement->value->minor];
                $consumed = $consumed->minus($movement->value);
            }

            $output = $this->move($bookId, $outputPoolId, 'produce-out:'.hash('sha256', $key), $date, 'receipt', $outputQuantity, $consumed->plus($conversionCost), $wipAccountId, $reason, productionId: $id);
            $this->db->insert('ledger_productions', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'output_pool_id' => $outputPoolId, 'output_movement_id' => $output->id, 'output_quantity' => $outputQuantity->decimal(),
                'output_value_minor' => (string) $output->value->minor, 'conversion_minor' => (string) $conversionCost->minor,
                'wip_account_id' => $wipAccountId, 'inputs' => Canonical::json($movements), 'reason' => $reason]);

            return new Production($id, array_map(fn (array $row): Movement => $this->fromRow($this->db->find('ledger_inventory_moves', $row['movement_id'], $bookId), $currency), $movements), $output);
        }, $bookId);
    }

    public function reverseProduction(string $bookId, string $productionId, string $key, string $date, string $reason): Production
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Inventory, function () use ($bookId, $productionId, $key, $date, $reason): Production {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $fingerprint = Canonical::hash(['reverse', $productionId, $date, $reason]);
            $existing = $this->replayProduction($bookId, $key, $fingerprint, $currency);

            if ($existing !== null) {
                return $existing;
            }

            $original = $this->db->find('ledger_productions', $productionId, $bookId);
            $id = (string) Str::uuid7();
            $output = $this->reverseMovement($bookId, Row::text($original, 'output_movement_id'), 'produce-out-reverse:'.hash('sha256', $key), $date, $reason, productionId: $id);
            $inputs = [];

            foreach (array_reverse(Row::json($original, 'inputs')) as $index => $row) {
                $movementId = Row::text((object) $row, 'movement_id');
                $movement = $this->reverseMovement($bookId, $movementId, 'produce-in-reverse:'.hash('sha256', $key.':'.$index), $date, $reason, productionId: $id);
                $inputs[] = $movement;
            }

            $inputs = array_reverse($inputs);
            $this->db->insert('ledger_productions', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'output_pool_id' => Row::text($original, 'output_pool_id'), 'output_movement_id' => $output->id,
                'output_quantity' => (string) (new Quantity(Row::text($original, 'output_quantity')))->value->negated(),
                'output_value_minor' => (string) Money::minor(Row::text($original, 'output_value_minor'), $currency)->minor->negated(),
                'conversion_minor' => (string) Money::minor(Row::text($original, 'conversion_minor'), $currency)->minor->negated(),
                'wip_account_id' => Row::text($original, 'wip_account_id'),
                'inputs' => Canonical::json(array_map(fn (Movement $movement): array => ['movement_id' => $movement->id, 'pool_id' => $this->poolOf($bookId, $movement->id), 'quantity' => $movement->quantity->decimal(), 'value_minor' => (string) $movement->value->minor], $inputs)),
                'reverses_id' => $productionId, 'reason' => $reason]);

            return new Production($id, $inputs, $output);
        }, $bookId);
    }

    public function reverseTransfer(string $bookId, string $transferId, string $key, string $date, string $reason): Transfer
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Inventory, function () use ($bookId, $transferId, $key, $date, $reason): Transfer {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $fingerprint = Canonical::hash(['reverse', $transferId, $date, $reason]);
            $existing = $this->replayTransfer($bookId, $key, $fingerprint, $currency);

            if ($existing !== null) {
                return $existing;
            }

            $original = $this->db->find('ledger_inventory_transfers', $transferId, $bookId);
            $id = (string) Str::uuid7();
            $receipt = $this->reverseMovement($bookId, Row::text($original, 'receipt_id'), 'transfer-in-reverse:'.hash('sha256', $key), $date, $reason, $id);
            $issue = $this->reverseMovement($bookId, Row::text($original, 'issue_id'), 'transfer-out-reverse:'.hash('sha256', $key), $date, $reason, $id);
            $this->db->insert('ledger_inventory_transfers', ['id' => $id, 'book_id' => $bookId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'source_pool_id' => Row::text($original, 'source_pool_id'), 'destination_pool_id' => Row::text($original, 'destination_pool_id'),
                'issue_id' => $issue->id, 'receipt_id' => $receipt->id, 'quantity' => (string) (new Quantity(Row::text($original, 'quantity')))->value->negated(),
                'value_minor' => (string) Money::minor(Row::text($original, 'value_minor'), $currency)->minor->negated(),
                'clearing_account_id' => Row::text($original, 'clearing_account_id'), 'reverses_id' => $transferId, 'reason' => $reason]);

            return new Transfer($id, $issue, $receipt);
        }, $bookId);
    }

    private function reverseMovement(string $bookId, string $movementId, string $key, string $date, string $reason, ?string $transferId = null, ?string $productionId = null): Movement
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Inventory, function () use ($bookId, $movementId, $key, $date, $reason, $transferId, $productionId): Movement {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            $fingerprint = Canonical::hash(['reverse', $movementId, $date, $reason]);
            $existing = $this->replay($bookId, $key, $fingerprint, $currency);

            if ($existing !== null) {
                return $existing;
            }

            $original = $this->db->find('ledger_inventory_moves', $movementId, $bookId);
            $journalId = Row::nullable($original, 'journal_id');
            $journal = $journalId === null ? null : $this->journals->reverse($bookId, $journalId, 'stock-reverse:'.hash('sha256', $key), $date, $reason);
            $id = (string) Str::uuid7();
            $plan = [];

            foreach (Row::json($original, 'plan') as $entry) {
                $row = (object) $entry;
                $plan[] = ['layer_id' => Row::text($row, 'layer_id'), 'quantity' => (string) (new Quantity(Row::text($row, 'quantity')))->value->negated(),
                    'value_minor' => (string) Money::minor(Row::text($row, 'value_minor'), $currency)->minor->negated()];
            }

            $this->db->insert('ledger_inventory_moves', ['id' => $id, 'book_id' => $bookId, 'pool_id' => Row::text($original, 'pool_id'), 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'effective_on' => $date, 'kind' => 'reversal', 'quantity' => (string) (new Quantity(Row::text($original, 'quantity')))->value->negated(),
                'value_minor' => (string) Money::minor(Row::text($original, 'value_minor'), $currency)->minor->negated(), 'counter_account_id' => Row::text($original, 'counter_account_id'),
                'journal_id' => $journal?->id, 'reason' => $reason, 'plan' => Canonical::json($plan), 'reverses_id' => $movementId, 'transfer_id' => $transferId, 'production_id' => $productionId,
                'commercial_minor' => Row::nullable($original, 'commercial_minor') === null ? null : (string) Money::minor(Row::text($original, 'commercial_minor'), $currency)->minor->negated(),
                'variance_account_id' => Row::nullable($original, 'variance_account_id')]);

            return $this->fromRow($this->db->find('ledger_inventory_moves', $id, $bookId), $currency);
        }, $bookId);
    }

    public function balance(Snapshot $snapshot, string $poolId): StockBalance
    {
        return $this->db->run(Permission::Read, function () use ($snapshot, $poolId): StockBalance {
            $book = $this->db->snapshotBook($snapshot);
            $this->db->find('ledger_inventory_pools', $poolId, $snapshot->bookId);
            $row = $this->db->table('ledger_inventory_moves')->where('book_id', $snapshot->bookId)->where('pool_id', $poolId)
                ->where('effective_on', '<=', $snapshot->effectiveOn)->where('sequence', '<=', $snapshot->throughSequence)
                ->selectRaw('coalesce(sum(quantity), 0)::text AS quantity, coalesce(sum(value_minor), 0)::text AS value_minor')->first();

            return new StockBalance(new Quantity($row instanceof stdClass ? Row::text($row, 'quantity') : '0'),
                Money::minor($row instanceof stdClass ? Row::text($row, 'value_minor') : '0', $this->db->currency(Row::text($book, 'currency_code'))));
        }, $snapshot->bookId);
    }

    private function move(string $bookId, string $poolId, string $key, string $date, string $kind, Quantity $quantity, ?Money $value, string $counterAccountId, string $reason, ?string $returnsId = null, ?string $transferId = null, ?string $varianceAccountId = null, ?string $productionId = null): Movement
    {
        Assert::text($key, 128);
        Assert::date($date);
        Assert::text($reason, 1024);

        return $this->db->run(Permission::Inventory, function () use ($bookId, $poolId, $key, $date, $kind, $quantity, $value, $counterAccountId, $reason, $returnsId, $transferId, $varianceAccountId, $productionId): Movement {
            $book = $this->db->lockBook($bookId);
            $currency = $this->db->currency(Row::text($book, 'currency_code'));
            Assert::that($value === null || $value->currency == $currency, 'Inventory is valued in the book functional currency.');
            $fingerprint = Canonical::hash([$poolId, $date, $kind, $quantity->decimal(), $value, $counterAccountId, $reason, $returnsId, $transferId]);
            if ($productionId !== null) {
                $fingerprint = Canonical::hash([$fingerprint, $productionId]);
            }
            if ($varianceAccountId !== null) {
                $fingerprint = Canonical::hash([$fingerprint, $varianceAccountId]);
            }
            $existing = $this->replay($bookId, $key, $fingerprint, $currency);

            if ($existing !== null) {
                return $existing;
            }

            $pool = $this->db->find('ledger_inventory_pools', $poolId, $bookId);
            if ($kind === 'supplier_return') {
                $quote = $this->db->connection->selectOne('SELECT ledger_inventory_supplier_return_quote(?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::date, ?::numeric)::text AS quote',
                    [$this->db->scope->tenantId, $bookId, $poolId, $returnsId, $date, $quantity->decimal()]);
            } elseif ($returnsId !== null) {
                $quote = $this->db->connection->selectOne('SELECT ledger_inventory_return_quote(?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::date, ?::numeric)::text AS quote',
                    [$this->db->scope->tenantId, $bookId, $poolId, $returnsId, $date, $quantity->decimal()]);
            } else {
                $quote = $this->db->connection->selectOne('SELECT ledger_inventory_quote(?::uuid, ?::uuid, ?::uuid, ?, ?::numeric, ?::numeric)::text AS quote',
                    [$this->db->scope->tenantId, $bookId, $poolId, $kind, $quantity->decimal(), $value === null ? '0' : (string) $value->minor]);
            }
            Assert::that($quote instanceof stdClass, 'Inventory quote is missing.');
            $data = (object) ($quote instanceof stdClass ? Row::json($quote, 'quote') : []);
            $movementValue = Money::minor(Row::text($data, 'value_minor'), $currency);
            $commercialValue = $kind === 'supplier_return' ? Money::minor(Row::text($data, 'commercial_minor'), $currency) : null;
            $dimensions = [];

            foreach (Row::json($pool, 'dimensions') as $dimension => $label) {
                if (is_string($dimension) && is_string($label)) {
                    $dimensions[$dimension] = $label;
                }
            }

            $counterDimensions = $dimensions;

            if ($returnsId !== null) {
                $original = $this->db->find('ledger_inventory_moves', $returnsId, $bookId);
                $sourcePool = $this->db->find('ledger_inventory_pools', Row::text($original, 'pool_id'), $bookId);
                $counterDimensions = [];

                foreach (Row::json($sourcePool, 'dimensions') as $dimension => $label) {
                    if (is_string($dimension) && is_string($label)) {
                        $counterDimensions[$dimension] = $label;
                    }
                }
            }

            $id = (string) Str::uuid7();
            $journal = null;

            if ($commercialValue !== null) {
                Assert::that($varianceAccountId !== null, 'Supplier returns require an explicit price variance account.');
                $postings = [];
                $effects = [[Row::text($pool, 'account_id'), $movementValue], [$counterAccountId, $commercialValue->negated()],
                    [$varianceAccountId ?? '', $commercialValue->minus($movementValue)]];
                foreach ($effects as [$accountId, $amount]) {
                    if (! $amount->minor->isZero()) {
                        $postings[] = new Posting($accountId, $amount->minor->isPositive() ? Side::Debit : Side::Credit,
                            Money::minor((string) $amount->minor->abs(), $currency), dimensions: $dimensions);
                    }
                }
                if ($postings !== []) {
                    $journal = $this->journals->post(new JournalRequest($bookId, 'stock:'.hash('sha256', $key), $date, $reason, $postings,
                        ['inventory_movement_id' => $id, 'receipt_id' => $returnsId, 'valuation_policy' => 'supplier-return:'.Row::text($pool, 'method').':v1'], source: new Source('inventory', $key)));
                }
            } elseif (! $movementValue->minor->isZero()) {
                $positive = $movementValue->minor->isPositive();
                $absolute = Money::minor((string) $movementValue->minor->abs(), $currency);
                $journal = $this->journals->post(new JournalRequest($bookId, 'stock:'.hash('sha256', $key), $date, $reason, [
                    new Posting(Row::text($pool, 'account_id'), $positive ? Side::Debit : Side::Credit, $absolute, dimensions: $dimensions),
                    new Posting($counterAccountId, $positive ? Side::Credit : Side::Debit, $absolute, dimensions: $counterDimensions),
                ], ['inventory_movement_id' => $id, 'valuation_policy' => Row::text($pool, 'method').':v1'], source: new Source('inventory', $key)));
            }

            $plan = $data->plan ?? [];
            $this->db->insert('ledger_inventory_moves', ['id' => $id, 'book_id' => $bookId, 'pool_id' => $poolId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                'effective_on' => $date, 'kind' => $kind, 'quantity' => $quantity->decimal(), 'value_minor' => (string) $movementValue->minor,
                'counter_account_id' => $counterAccountId, 'journal_id' => $journal?->id, 'reason' => $reason, 'plan' => Canonical::json($plan), 'returns_id' => $returnsId, 'transfer_id' => $transferId,
                'production_id' => $productionId,
                'commercial_minor' => $commercialValue === null ? null : (string) $commercialValue->minor, 'variance_account_id' => $varianceAccountId]);

            return new Movement($id, $journal?->id, $quantity, $movementValue, $commercialValue);
        }, $bookId);
    }

    private function replay(string $bookId, string $key, string $fingerprint, Currency $currency): ?Movement
    {
        $existing = $this->db->table('ledger_inventory_moves')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $existing instanceof stdClass) {
            return null;
        }

        Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Inventory key was already used with different content.');

        return $this->fromRow($existing, $currency);
    }

    private function replayTransfer(string $bookId, string $key, string $fingerprint, Currency $currency): ?Transfer
    {
        $existing = $this->db->table('ledger_inventory_transfers')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $existing instanceof stdClass) {
            return null;
        }

        Assert::that(Row::text($existing, 'fingerprint') === $fingerprint, 'Transfer key was already used with different content.');

        return new Transfer(Row::text($existing, 'id'), $this->fromRow($this->db->find('ledger_inventory_moves', Row::text($existing, 'issue_id'), $bookId), $currency),
            $this->fromRow($this->db->find('ledger_inventory_moves', Row::text($existing, 'receipt_id'), $bookId), $currency));
    }

    private function replayProduction(string $bookId, string $key, string $fingerprint, Currency $currency, ?string $legacyFingerprint = null): ?Production
    {
        $existing = $this->db->table('ledger_productions')->where('book_id', $bookId)->where('idempotency_key', $key)->first();

        if (! $existing instanceof stdClass) {
            return null;
        }

        Assert::that(in_array(Row::text($existing, 'fingerprint'), [$fingerprint, $legacyFingerprint], true), 'Production key was already used with different content.');

        $inputs = [];
        foreach (Row::json($existing, 'inputs') as $row) {
            $inputs[] = $this->fromRow($this->db->find('ledger_inventory_moves', Row::text((object) $row, 'movement_id'), $bookId), $currency);
        }

        return new Production(Row::text($existing, 'id'), $inputs, $this->fromRow($this->db->find('ledger_inventory_moves', Row::text($existing, 'output_movement_id'), $bookId), $currency));
    }

    private function poolOf(string $bookId, string $movementId): string
    {
        return Row::text($this->db->find('ledger_inventory_moves', $movementId, $bookId), 'pool_id');
    }

    private function fromRow(stdClass $row, Currency $currency): Movement
    {
        return new Movement(Row::text($row, 'id'), Row::nullable($row, 'journal_id'), new Quantity(Row::text($row, 'quantity')), Money::minor(Row::text($row, 'value_minor'), $currency),
            Row::nullable($row, 'commercial_minor') === null ? null : Money::minor(Row::text($row, 'commercial_minor'), $currency));
    }
}
