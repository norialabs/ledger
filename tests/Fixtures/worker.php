<?php

declare(strict_types=1);

use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AllocationRequest;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Inventory\Quantity;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\Rounding;
use Noria\Ledger\Reporting\Snapshot;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;

require __DIR__.'/../../vendor/autoload.php';

$input = fgets(STDIN);
$command = json_decode($input === false ? '{}' : $input, false, 512, JSON_THROW_ON_ERROR);

if (! $command instanceof stdClass) {
    throw new RuntimeException('Missing worker command.');
}

$connection = Database::connection();
$connection->select("SELECT set_config('application_name', ?, false)", [Row::text($command, 'application')]);
$ledger = (new Ledger($connection, new PermitAll))->for(new Scope(Row::text($command, 'tenant'), 'concurrent-worker'));
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);

try {
    $amount = Money::of(Row::text($command, 'amount'), new Currency('KES', 2));
    $id = match (Row::text($command, 'operation')) {
        'income_tax_post' => $ledger->incomeTax->post(Row::text($command, 'book'), Row::text($command, 'computation'), Row::text($command, 'key'), Row::text($command, 'date'), 'Concurrent income tax'),
        'deferred_tax_post' => $ledger->incomeTax->postDeferred(Row::text($command, 'book'), Row::text($command, 'computation'), Row::text($command, 'key'), Row::text($command, 'date'), 'Concurrent deferred tax'),
        'budget_commit' => $ledger->budgets->commit(Row::text($command, 'book'), Row::text($command, 'budget'), 1, Row::text($command, 'key'), '2026-01-01', $amount, 'Concurrent PO'),
        'post' => $ledger->journals->post(new JournalRequest(Row::text($command, 'book'), Row::text($command, 'key'), '2026-01-01', 'Concurrent receipt', [
            new Posting(Row::text($command, 'cash'), Side::Debit, $amount), new Posting(Row::text($command, 'income'), Side::Credit, $amount),
        ]))->id,
        'allocate' => $ledger->settlements->allocate(new AllocationRequest(Row::text($command, 'book'), Row::text($command, 'key'), Row::text($command, 'debit'),
            Row::text($command, 'credit'), $amount, '2026-02-01', 'Concurrent allocation')),
        'recycle_oci' => $ledger->journals->recycleOci(Row::text($command, 'book'), Row::text($command, 'key'), Row::text($command, 'source'), 1,
            '2026-02-01', $amount, Row::text($command, 'income'), 'reviewed:1', 'Concurrent reviewed recycling')->id,
        'issue' => $ledger->inventory->issue(Row::text($command, 'book'), Row::text($command, 'pool'), Row::text($command, 'key'), '2026-02-01',
            new Quantity(Row::text($command, 'quantity')), Row::text($command, 'counter'), 'Concurrent issue')->id,
        'depreciate' => implode(',', $ledger->assets->depreciate(Row::text($command, 'book'), Row::text($command, 'asset'), '2026-01-31')),
        'return' => $ledger->inventory->returnIssue(Row::text($command, 'book'), Row::text($command, 'issue'), Row::text($command, 'key'), '2026-02-01',
            new Quantity(Row::text($command, 'quantity')), 'Concurrent return')->id,
        'supplier_return' => $ledger->inventory->returnReceipt(Row::text($command, 'book'), Row::text($command, 'receipt'), Row::text($command, 'key'), '2026-02-01',
            new Quantity(Row::text($command, 'quantity')), Row::text($command, 'counter'), Row::text($command, 'variance'), 'Concurrent supplier return')->id,
        'transfer' => $ledger->inventory->transfer(Row::text($command, 'book'), Row::text($command, 'source'), Row::text($command, 'destination'),
            Row::text($command, 'key'), '2026-02-01', new Quantity(Row::text($command, 'quantity')), Row::text($command, 'clearing'), 'Concurrent transfer')->id,
        'monetary' => $ledger->monetaryAccounts->revalue(Row::text($command, 'book'), Row::text($command, 'account'), Row::text($command, 'key'), '2026-01-31',
            new ExchangeRate(Currency::iso('USD'), Currency::iso('KES'), '140', '2026-01-31', 'closing', Rounding::HalfEven),
            Row::text($command, 'gain'), Row::text($command, 'loss'), 'Concurrent monetary valuation')->id,
        'associate_profit' => $ledger->associates->profit(Row::text($command, 'book'), Row::text($command, 'associate'), Row::text($command, 'key'), '2026-02-01', $amount, 'Concurrent associate result'),
        'finance_period' => $ledger->finance->period(Row::text($command, 'book'), Row::text($command, 'contract'), Row::text($command, 'key'), '2026-01-31', 1, 'Concurrent installment'),
        'revenue_invoice' => $ledger->finance->invoiceRevenue(Row::text($command, 'book'), Row::text($command, 'contract'), Row::text($command, 'key'), '2026-01-31', $amount->decimal(),
            Row::text($command, 'invoice_reference'), '2026-02-28', 'Reviewed unconditional billing right', 'Concurrent revenue billing'),
        'finance_replace' => $ledger->finance->replaceLoan(Row::text($command, 'book'), Row::text($command, 'contract'), Row::text($command, 'key'), '2026-01-01',
            [['due_on' => '2026-12-31', 'payment' => '880', 'interest_paid' => '80']], '800', '0.1', Row::text($command, 'gain'), Row::text($command, 'loss'),
            ['policy_version' => 'reviewed-exchange:1', 'reference' => 'signed-exchange:1', 'qualitative_substantial' => false], 'Reviewed fair value:1', 'Concurrent replacement', '0', '0', '0', 'financing', 'financing'),
        'finance_settle' => $ledger->finance->settle(Row::text($command, 'book'), Row::text($command, 'contract'), Row::text($command, 'key'), '2026-01-01', $amount->decimal(), '0',
            Row::text($command, 'gain'), Row::text($command, 'loss'), 'Reviewed full discharge at carrying date', 'Concurrent loan payoff'),
        'policy_change' => $ledger->yearEnd->changePolicy(Row::text($command, 'book'), Row::text($command, 'key'), '2026-02-01', Row::text($command, 'retained'), 'policy:race',
            ['impracticable' => false, 'reference' => 'IAS8-review:race', 'explanation' => 'Practicable', 'earliest_comparative_on' => '2024-01-01', 'application_from' => '2024-01-01', 'current_starts_on' => '2026-01-01'],
            ['reference' => 'Opening', 'tax_reference' => 'No tax', 'postings' => [new Posting(Row::text($command, 'retained'), Side::Debit, $amount), new Posting(Row::text($command, 'asset'), Side::Credit, $amount)], 'tax' => []],
            array_map(fn (string $closure): array => ['closure_id' => $closure, 'reference' => 'Annual', 'tax_reference' => 'No tax',
                'postings' => [new Posting(Row::text($command, 'expense'), Side::Debit, $amount), new Posting(Row::text($command, 'asset'), Side::Credit, $amount)], 'tax' => []],
                [Row::text($command, 'closure_2024'), Row::text($command, 'closure_2025')]), 'Concurrent policy change'),
        'tax_prepare' => $ledger->taxes->prepare(new Snapshot(Row::text($command, 'book'), '2026-01-31', Row::text($command, 'cutoff')), Row::text($command, 'key'), Row::text($command, 'configuration'), 'vat', '2026-01-01'),
        'statutory_claim' => $ledger->statutory->claim(Row::text($command, 'book'), Row::text($command, 'submission'), Row::text($command, 'key'))->claimId ?? '',
        default => throw new RuntimeException('Unknown worker operation.'),
    };
    fwrite(STDOUT, json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
}
