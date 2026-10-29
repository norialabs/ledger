<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Noria\Ledger\Access\Authorizer;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Access\Scope;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Accounting\Source;
use Noria\Ledger\Accounting\TreatmentRequest;
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Finance\FinancialCalculator;
use Noria\Ledger\Inventory\ValuationMethod;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Scheduling\Schedule;
use Noria\Ledger\Session;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;

function financeConfiguration(Session $ledger, string $book, string $position = 'asset'): string
{
    return $ledger->configurations->install($book, new Pack('example-finance', '1', accounts: [
        ['code' => '1300', 'name' => 'Loan amortized cost', 'type' => $position],
        ['code' => '1100', 'name' => 'Contract receivables', 'type' => 'asset', 'control' => true],
        ['code' => '1330', 'name' => 'Unbilled contract asset', 'type' => 'asset'],
        ['code' => '1335', 'name' => 'Contract asset loss allowance', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '2610', 'name' => 'Refund liability', 'type' => 'liability'],
        ['code' => '1310', 'name' => 'Loss allowance', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '1500', 'name' => 'Right of use', 'type' => 'asset'],
        ['code' => '1510', 'name' => 'Accumulated right-of-use depreciation', 'type' => 'asset', 'normal_side' => 'credit'],
        ['code' => '2500', 'name' => 'Lease liability', 'type' => 'liability'],
        ['code' => '2600', 'name' => 'Contract liability', 'type' => 'liability'],
        ['code' => '4100', 'name' => 'Effective interest', 'type' => $position === 'asset' ? 'income' : 'expense'],
        ['code' => '4200', 'name' => 'Equipment revenue', 'type' => 'income'],
        ['code' => '4300', 'name' => 'Service revenue', 'type' => 'income'],
        ['code' => '4500', 'name' => 'Loan modification and settlement gains', 'type' => 'income'],
        ['code' => '5100', 'name' => 'Credit losses', 'type' => 'expense'],
        ['code' => '5200', 'name' => 'Right-of-use depreciation', 'type' => 'expense'],
        ['code' => '5500', 'name' => 'Loan modification and settlement losses', 'type' => 'expense'],
    ], roles: ['cash' => '1000', 'loan' => '1300', 'interest' => '4100', 'ecl.allowance' => '1310', 'ecl.expense' => '5100',
        'lease.asset' => '1500', 'lease.liability' => '2500', 'lease.accumulated' => '1510', 'lease.depreciation' => '5200',
        'contract.liability' => '2600', 'contract.asset' => '1330', 'contract.refund' => '2610', 'contract.allowance' => '1335', 'contract.impairment' => '5100', 'receivable' => '1100', 'revenue.equipment' => '4200', 'revenue.service' => '4300', 'loan.gain' => '4500', 'loan.loss' => '5500']));
}

/** @return array{fixed: string, variable: array{expected: string, included: string, limit?: string, estimation_method: string, constraint_reference: string, highly_probable: bool}, obligations: non-empty-list<array{reference: string, standalone_price: string}>} */
function contractRevenueTerms(string $fixed = '100', string $variable = '0', bool $single = false): array
{
    return ['fixed' => $fixed, 'variable' => ['expected' => $variable, 'included' => $variable, ...(str_starts_with($variable, '-') ? ['limit' => $variable] : []),
        'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Reviewed highly probable no significant reversal:1', 'highly_probable' => true],
        'obligations' => $single ? [['reference' => 'service', 'standalone_price' => '100']] : [['reference' => 'equipment', 'standalone_price' => '80'], ['reference' => 'service', 'standalone_price' => '40']]];
}

/** @return array{configuration: string, party: string, contract: string} */
function contractRevenueFixture(Session $ledger, string $book, string $entity, string $fixed = '100', string $variable = '0', bool $single = false): array
{
    $configuration = financeConfiguration($ledger, $book);
    $party = $ledger->catalog->party($entity, 'CONTRACT-CUSTOMER', 'Contract customer');
    $contract = $ledger->finance->open($book, 'revenue-contract', 'REVENUE-CONTRACT', '2026-01-01', 'contract_revenue', $configuration,
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Reviewed enforceable contract and performance obligations', 'party_id' => $party, 'terms' => contractRevenueTerms($fixed, $variable, $single)]);

    return ['configuration' => $configuration, 'party' => $party, 'contract' => $contract];
}

it('earns an unbilled contract asset and transfers it to receivables without recognizing revenue twice', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $contract = $fixture['contract'];
    $recognized = $this->ledger->finance->recognize($this->book, $contract, 'earned', '2026-01-31', 'service', '1', 'Service earned before unconditional billing right');
    $saved = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $before = $this->ledger->finance->revenuePosition($saved, $contract);
    expect($before['asset']->decimal())->toBe('100.00')->and($before['earned']->decimal())->toBe('100.00')->and($before['billed']->decimal())->toBe('0.00');
    $invoice = $this->ledger->finance->invoiceRevenue($this->book, $contract, 'bill', '2026-02-01', '100', 'INV-1', '2026-03-01', 'Unconditional right established', 'Invoice earned services');
    expect($this->ledger->finance->invoiceRevenue($this->book, $contract, 'bill', '2026-02-01', '100', 'INV-1', '2026-03-01', 'Unconditional right established', 'Invoice earned services'))->toBe($invoice);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $position = $this->ledger->finance->revenuePosition($snapshot, $contract);
    $receivable = $this->ledger->configurations->account($this->book, $fixture['configuration'], 'receivable');
    expect($position['asset']->decimal())->toBe('0.00')->and($position['liability']->decimal())->toBe('0.00')->and($position['billed']->decimal())->toBe('100.00')
        ->and(byAccount($this->ledger->reports->trialBalance($snapshot))[$receivable]->closing->decimal())->toBe('100.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('100.00')
        ->and($this->ledger->reports->openItems($snapshot, $fixture['party'])[0]->outstanding->decimal())->toBe('100.00')
        ->and($this->ledger->finance->revenuePosition($saved, $contract)['asset']->decimal())->toBe('100.00');
    $this->ledger->finance->reverse($this->book, $invoice, 'undo-billing', '2026-03-01', 'Correct billing right');
    $this->ledger->finance->reverse($this->book, $recognized, 'undo-earning', '2026-03-01', 'Correct performance evidence');
    $this->ledger->finance->cancel($this->book, $contract, 'cancel-unfunded', '2026-03-01', 'Retire corrected contract');
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract)['earned']->decimal())->toBe('0.00');
});

it('allocates billed consideration by standalone prices and nets only that contracts asset and liability', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity);
    $contract = $fixture['contract'];
    $this->ledger->finance->invoiceRevenue($this->book, $contract, 'advance-bill', '2026-01-02', '100', 'INV-ADVANCE', '2026-01-31', 'Unconditional milestone', 'Bill before delivery');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract);
    expect($position['liability']->decimal())->toBe('100.00')->and($position['allocations']['equipment']->decimal())->toBe('66.67')->and($position['allocations']['service']->decimal())->toBe('33.33');
    $this->ledger->finance->recognize($this->book, $contract, 'equipment-earned', '2026-01-31', 'equipment', '1', 'Delivery');
    $this->ledger->finance->recognize($this->book, $contract, 'service-earned', '2026-02-28', 'service', '1', 'Completion');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-02-28'), $contract);
    expect($position['earned']->decimal())->toBe('100.00')->and($position['asset']->decimal())->toBe('0.00')->and($position['liability']->decimal())->toBe('0.00');
});

it('retains variable reassessments and corrects overbilling through a linked credit rather than duplicate revenue', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, variable: '20', single: true);
    $contract = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $contract, 'earned-variable', '2026-01-02', 'service', '1', 'Complete contract');
    $invoice = $this->ledger->finance->invoiceRevenue($this->book, $contract, 'variable-invoice', '2026-01-02', '120', 'INV-VARIABLE', '2026-02-01', 'Unconditional billed amount', 'Bill reviewed consideration');
    $saved = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $revision = $this->ledger->finance->reviseRevenue($this->book, $contract, 'variable-reduced', '2026-02-01', $contract, contractRevenueTerms('100', '10', true), 'variable_reassessment', 'Revised expected bonus:2', 'Reduce estimate 20 to 10');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract);
    expect($position['earned']->decimal())->toBe('110.00')->and($position['liability']->decimal())->toBe('0.00')->and($position['refund']->decimal())->toBe('10.00')->and($position['termsId'])->toBe($revision);
    $credit = $this->ledger->finance->creditRevenue($this->book, $contract, 'variable-credit', '2026-02-02', '10', 'CREDIT-1', $invoice, 'refund', 'Customer credit approval', 'Credit excess invoice');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    expect($this->ledger->finance->revenuePosition($snapshot, $contract)['refund']->decimal())->toBe('0.00')
        ->and($this->ledger->finance->revenuePosition($saved, $contract)['earned']->decimal())->toBe('120.00');
    $events = $this->ledger->finance->evidence($snapshot, $contract)['events'];
    expect(data_get($events[2]->inputs, 'terms.variable.expected'))->toBe('10')->and(data_get($events[2]->calculation, 'earned_delta_minor'))->toBe('-1000');
    $this->ledger->finance->reverse($this->book, $credit, 'undo-variable-credit', '2026-03-01', 'Correct credit');
    $this->ledger->finance->reverse($this->book, $revision, 'undo-variable-revision', '2026-03-01', 'Restore estimate');
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract)['earned']->decimal())->toBe('120.00');
});

it('records an eligible cumulative catch-up and restores its previous allocations and progress exactly', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $contract = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $contract, 'half-integrated-service', '2026-01-31', 'service', '0.5', 'Reviewed integrated performance');
    $revision = $this->ledger->finance->reviseRevenue($this->book, $contract, 'integrated-modification', '2026-02-01', $contract, contractRevenueTerms('120', '0', true),
        'cumulative_catch_up', 'Remaining services form part of the partially satisfied single obligation', 'Revised price and integrated progress', ['service' => '0.6'], false);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->finance->revenuePosition($snapshot, $contract)['earned']->decimal())->toBe('72.00');
    $final = $this->ledger->finance->recognize($this->book, $contract, 'finish-integrated', '2026-02-28', 'service', '1', 'Complete revised service', termsId: $revision);
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-02-28'), $contract)['earned']->decimal())->toBe('120.00');
    $this->ledger->finance->reverse($this->book, $final, 'undo-finish-integrated', '2026-03-01', 'Correct completion');
    $this->ledger->finance->reverse($this->book, $revision, 'undo-integrated-modification', '2026-03-01', 'Withdraw modification');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract);
    expect($position['earned']->decimal())->toBe('50.00')->and($position['progress']['service'])->toBe('0.5')->and($position['price']->decimal())->toBe('100.00')
        ->and($this->ledger->finance->revenuePosition($snapshot, $contract)['earned']->decimal())->toBe('72.00');
});

it('accounts for a distinct standalone-priced addition as a separate contract with independent corrections', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'addition');
    $parent = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $parent, 'original-half', '2026-01-31', 'service', '0.5', 'Original service progress');
    $child = $this->ledger->finance->open($this->book, 'additional-services', 'ADDITIONAL-SERVICES', '2026-02-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Additional distinct service at standalone price', 'party_id' => $fixture['party'],
            'terms' => contractRevenueTerms('30', '0', true), 'separate_from' => $parent, 'modification_reference' => 'MOD-1', 'distinct' => true, 'standalone_priced' => true], ['contract' => 'addition']);
    $earned = $this->ledger->finance->recognize($this->book, $child, 'addition-complete', '2026-02-28', 'service', '1', 'Deliver additional service');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->finance->revenuePosition($snapshot, $parent)['earned']->decimal())->toBe('50.00')
        ->and($this->ledger->finance->revenuePosition($snapshot, $child)['earned']->decimal())->toBe('30.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('80.00');
    $this->ledger->finance->reverse($this->book, $earned, 'correct-addition', '2026-03-01', 'Withdraw additional performance');
    $this->ledger->finance->cancel($this->book, $child, 'cancel-addition', '2026-03-01', 'Cancel separate addition');
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-03-31'), $parent)['earned']->decimal())->toBe('50.00');
});

it('rejects unsupported revenue constraints and modification judgments atomically', function (string $case, string $message) {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $terms = contractRevenueTerms('100', '20', true);
    if ($case === 'unsupported-constraint') {
        $terms['variable']['highly_probable'] = false;
    } elseif ($case === 'optimistic-penalty') {
        $terms['variable']['expected'] = '-20';
        $terms['variable']['included'] = '-10';
        $terms['variable']['limit'] = '-40';
    } elseif ($case === 'fixed-change') {
        $terms['fixed'] = '110';
    }
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->ledger->finance->reviseRevenue($this->book, $fixture['contract'], 'invalid-revenue', '2026-01-02', $fixture['contract'], $terms,
        $case === 'distinct-catchup' ? 'cumulative_catch_up' : 'variable_reassessment', 'Reviewed proposal', 'Unsupported inputs',
        $case === 'distinct-catchup' ? ['service' => '0'] : null, $case === 'distinct-catchup' ? true : null))->toThrow(QueryException::class, $message)
        ->and($this->ledger->events->cutoff($this->book))->toBe($before);
})->with([['unsupported-constraint', 'Constrained consideration'], ['optimistic-penalty', 'Constrained consideration'], ['fixed-change', 'retains fixed consideration'], ['distinct-catchup', 'non-distinct remaining']]);

it('recognizes reviewed rebates as reduced consideration rather than constraining them into higher revenue', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, variable: '-20', single: true);
    $this->ledger->finance->recognize($this->book, $fixture['contract'], 'rebate-service', '2026-01-02', 'service', '1', 'Complete discounted service');
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $fixture['contract'])['earned']->decimal())->toBe('80.00');
});

it('keeps satisfied obligations and their earned revenue fixed through a cumulative catch-up', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity);
    $contract = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $contract, 'equipment-delivered', '2026-01-10', 'equipment', '1', 'Equipment delivered');
    $this->ledger->finance->recognize($this->book, $contract, 'service-half', '2026-01-20', 'service', '0.5', 'Half of the integrated service');
    $terms = contractRevenueTerms('130');
    $terms['obligations'][1]['standalone_price'] = '70';
    $catchUp = function (string $equipmentPrice, string $equipmentProgress) use ($terms, $contract): string {
        $terms['obligations'][0]['standalone_price'] = $equipmentPrice;

        return $this->ledger->finance->reviseRevenue($this->book, $contract, 'catch-up', '2026-02-01', $contract, $terms, 'cumulative_catch_up',
            'Remaining service is not distinct from the partially satisfied service', 'Integrated scope change', ['equipment' => $equipmentProgress, 'service' => '0.4'], false);
    };
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $catchUp('10', '1'))->toThrow(QueryException::class, 'satisfied obligations')
        ->and(fn () => $catchUp('80', '0'))->toThrow(QueryException::class, 'satisfied obligations')
        ->and($this->ledger->events->cutoff($this->book))->toBe($before);
    $revision = $catchUp('80', '1');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-02-28'), $contract);
    expect($position['allocations']['equipment']->decimal())->toBe('66.67')->and($position['allocations']['service']->decimal())->toBe('63.33')
        ->and($position['recognized']['equipment']->decimal())->toBe('66.67')->and($position['recognized']['service']->decimal())->toBe('25.33')
        ->and($position['price']->decimal())->toBe('130.00');
    $bonus = $this->ledger->finance->reviseRevenue($this->book, $contract, 'bonus', '2026-02-02', $revision, [...contractRevenueTerms('130', '10'), 'obligations' => $terms['obligations']],
        'variable_reassessment', 'Approved bonus:1', 'Bonus becomes highly probable');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-02-28'), $contract);
    expect($position['allocations']['equipment']->decimal())->toBe('66.67')->and($position['allocations']['service']->decimal())->toBe('73.33');
    $this->ledger->finance->reverse($this->book, $bonus, 'undo-bonus', '2026-03-01', 'Withdraw bonus');
    $this->ledger->finance->reverse($this->book, $revision, 'undo-catch-up', '2026-03-01', 'Withdraw modification');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract);
    expect($position['recognized']['equipment']->decimal())->toBe('66.67')->and($position['recognized']['service']->decimal())->toBe('16.66')
        ->and($position['allocations']['service']->decimal())->toBe('33.33');
    $this->ledger->finance->recognize($this->book, $contract, 'service-done', '2026-03-02', 'service', '1', 'Service complete');
    expect(fn () => $this->ledger->finance->reviseRevenue($this->book, $contract, 'all-satisfied', '2026-03-03', $contract, contractRevenueTerms('130'), 'cumulative_catch_up',
        'Completed contract', 'Price change after completion', ['equipment' => '1', 'service' => '1'], false))->toThrow(QueryException::class, 'Revised consideration cannot reallocate');
});

it('invoices rebate contracts at their gross price with a separate refund liability', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $party = $fixture['party'];
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'rebate');
    $terms = contractRevenueTerms('100', single: true);
    $terms['variable'] = ['expected' => '-20', 'included' => '-20', 'limit' => '-30', 'estimation_method' => 'expected_value', 'constraint_reference' => 'Reviewed volume rebate', 'highly_probable' => true];
    $contract = $this->ledger->finance->open($this->book, 'rebate-contract', 'REBATE-CONTRACT', '2026-01-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Rebate contract', 'party_id' => $party, 'terms' => $terms], ['contract' => 'rebate']);
    $this->ledger->finance->recognize($this->book, $contract, 'rebate-earned', '2026-01-10', 'service', '1', 'Complete service');
    expect(fn () => $this->ledger->finance->invoiceRevenue($this->book, $contract, 'over-gross', '2026-01-11', '100.01', 'INV-OVER', '2026-02-01', 'Gross price', 'Over gross'))
        ->toThrow(QueryException::class, 'exceeds the reviewed contract consideration');
    $invoice = $this->ledger->finance->invoiceRevenue($this->book, $contract, 'gross', '2026-01-11', '100', 'INV-GROSS', '2026-02-01', 'Gross contractual price', 'Bill before rebate');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $position = $this->ledger->finance->revenuePosition($snapshot, $contract);
    $refund = $this->ledger->configurations->account($this->book, $fixture['configuration'], 'contract.refund');
    expect($position['earned']->decimal())->toBe('80.00')->and($position['refund']->decimal())->toBe('20.00')->and($position['asset']->decimal())->toBe('0.00')
        ->and($position['liability']->decimal())->toBe('0.00')->and(byAccount($this->ledger->reports->trialBalance($snapshot))[$refund]->closing->decimal())->toBe('-20.00')
        ->and($this->ledger->reports->openItems($snapshot, $party)[0]->outstanding->decimal())->toBe('100.00');
    expect(fn () => $this->ledger->finance->creditRevenue($this->book, $contract, 'too-much-rebate', '2026-01-31', '20.01', 'REBATE-X', $invoice, 'refund', 'Rebate', 'Rebate'))
        ->toThrow(QueryException::class, 'refund liability');
    $this->ledger->finance->creditRevenue($this->book, $contract, 'rebate', '2026-01-31', '20', 'REBATE-1', $invoice, 'refund', 'Rebate earned by volume', 'Issue rebate credit');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract);
    expect($position['refund']->decimal())->toBe('0.00')->and($position['earned']->decimal())->toBe('80.00')->and($position['billed']->decimal())->toBe('80.00')
        ->and(fn () => $this->ledger->finance->invoiceRevenue($this->book, $contract, 'rebill', '2026-01-31', '20', 'INV-REBILL', '2026-02-28', 'Gross price', 'Rebill refunded rebate'))
        ->toThrow(QueryException::class, 'exceeds the reviewed contract consideration');
});

it('constrains each variable component separately within its reviewed limit', function (array $components, ?string $price, string $message = '') {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'components');
    $terms = [...contractRevenueTerms('100', single: true), 'variable' => $components];
    $open = fn (): string => $this->ledger->finance->open($this->book, 'components', 'COMPONENTS', '2026-01-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Mixed variable consideration', 'party_id' => $fixture['party'], 'terms' => $terms], ['contract' => 'components']);
    if ($price === null) {
        expect($open)->toThrow(QueryException::class, $message);

        return;
    }
    $contract = $open();
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract)['price']->decimal())->toBe($price);
})->with([
    'constrained bonus with penalty' => [[
        ['reference' => 'bonus', 'expected' => '20', 'included' => '0', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Bonus not highly probable', 'highly_probable' => false],
        ['reference' => 'penalty', 'expected' => '-10', 'included' => '-10', 'limit' => '-10', 'estimation_method' => 'expected_value', 'constraint_reference' => 'Penalty estimate', 'highly_probable' => true],
    ], '90.00'],
    'conservative rebate without assurance' => [[
        ['reference' => 'rebate', 'expected' => '-20', 'included' => '-25', 'limit' => '-30', 'estimation_method' => 'expected_value', 'constraint_reference' => 'Rebate may rise', 'highly_probable' => false],
    ], '75.00'],
    'penalty beyond its limit' => [[
        ['reference' => 'penalty', 'expected' => '-20', 'included' => '-60', 'limit' => '-30', 'estimation_method' => 'expected_value', 'constraint_reference' => 'Unbounded', 'highly_probable' => true],
    ], null, 'Constrained consideration'],
    'unassured rebate estimate' => [[
        ['reference' => 'rebate', 'expected' => '-20', 'included' => '-20', 'limit' => '-30', 'estimation_method' => 'expected_value', 'constraint_reference' => 'Not assured', 'highly_probable' => false],
    ], null, 'Constrained consideration'],
    'rebate without limit' => [[
        ['reference' => 'rebate', 'expected' => '-20', 'included' => '-20', 'estimation_method' => 'expected_value', 'constraint_reference' => 'No limit', 'highly_probable' => true],
    ], null, 'reviewed contractual limit'],
    'duplicate component' => [[
        ['reference' => 'bonus', 'expected' => '0', 'included' => '0', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'None', 'highly_probable' => true],
        ['reference' => 'bonus', 'expected' => '0', 'included' => '0', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'None', 'highly_probable' => true],
    ], null, 'reviewed constraint evidence'],
]);

it('bills a constrained clawback bonus into a refund liability and refuses a catch-up without a modification', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'clawback');
    $terms = [...contractRevenueTerms('100', single: true), 'variable' => ['expected' => '20', 'included' => '0', 'billable' => '20', 'estimation_method' => 'most_likely_amount',
        'constraint_reference' => 'Upfront bonus subject to clawback', 'highly_probable' => false]];
    $contract = $this->ledger->finance->open($this->book, 'clawback', 'CLAWBACK', '2026-01-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Bonus paid upfront with clawback', 'party_id' => $fixture['party'], 'terms' => $terms], ['contract' => 'clawback']);
    $this->ledger->finance->recognize($this->book, $contract, 'half', '2026-01-10', 'service', '0.5', 'Half performed');
    $this->ledger->finance->invoiceRevenue($this->book, $contract, 'upfront', '2026-01-10', '120', 'INV-UPFRONT', '2026-01-31', 'Contractual upfront billing', 'Bill price and bonus');
    $position = $this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract);
    expect($position['earned']->decimal())->toBe('50.00')->and($position['liability']->decimal())->toBe('50.00')->and($position['refund']->decimal())->toBe('20.00')
        ->and(fn () => $this->ledger->finance->reviseRevenue($this->book, $contract, 'no-op', '2026-01-11', $contract, $terms, 'cumulative_catch_up', 'Progress only', 'Not a modification',
            ['service' => '0.6'], false))->toThrow(QueryException::class, 'reviewed change to the contract terms');
});

it('compares catch-up terms by their economics and rebills consideration that becomes due again', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity);
    $contract = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $contract, 'equipment', '2026-01-10', 'equipment', '1', 'Delivered');
    $restated = contractRevenueTerms();
    $restated['fixed'] = '100.00';
    $restated['obligations'] = array_reverse($restated['obligations']);
    expect(fn () => $this->ledger->finance->reviseRevenue($this->book, $contract, 'cosmetic', '2026-01-11', $contract, $restated, 'cumulative_catch_up', 'No change', 'Cosmetic',
        ['equipment' => '1', 'service' => '0.1'], false))->toThrow(QueryException::class, 'reviewed change to the contract terms');
    $listed = [...contractRevenueTerms(), 'variable' => [[...contractRevenueTerms()['variable'], 'reference' => 'bonus'],
        ['reference' => 'unused', 'expected' => '0', 'included' => '0', 'estimation_method' => 'expected_value', 'constraint_reference' => 'None', 'highly_probable' => true]]];
    expect(fn () => $this->ledger->finance->reviseRevenue($this->book, $contract, 'reshaped', '2026-01-11', $contract, $listed, 'cumulative_catch_up', 'No change', 'Reshaped',
        ['equipment' => '1', 'service' => '0.1'], false))->toThrow(QueryException::class, 'reviewed change to the contract terms');
    $this->ledger->finance->recognize($this->book, $contract, 'service', '2026-01-12', 'service', '1', 'Service complete');
    $invoice = $this->ledger->finance->invoiceRevenue($this->book, $contract, 'bill', '2026-01-12', '100', 'INV-1', '2026-02-12', 'Billing right', 'Bill');
    $lower = $this->ledger->finance->reviseRevenue($this->book, $contract, 'penalty', '2026-01-13', $contract,
        [...contractRevenueTerms(), 'variable' => ['expected' => '-20', 'included' => '-20', 'limit' => '-20', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Penalty', 'highly_probable' => true]],
        'variable_reassessment', 'Penalty incurred', 'Penalty');
    $this->ledger->finance->creditRevenue($this->book, $contract, 'refund', '2026-01-14', '20', 'CR-1', $invoice, 'refund', 'Penalty refund', 'Refund');
    $this->ledger->finance->reviseRevenue($this->book, $contract, 'waived', '2026-01-15', $lower, contractRevenueTerms(), 'variable_reassessment', 'Penalty waived', 'Waive');
    $this->ledger->finance->invoiceRevenue($this->book, $contract, 'rebill', '2026-01-16', '20', 'INV-2', '2026-02-16', 'Waived penalty is due again', 'Rebill');
    expect(fn () => $this->ledger->finance->invoiceRevenue($this->book, $contract, 'excess', '2026-01-16', '0.01', 'INV-3', '2026-02-16', 'Right', 'Excess'))
        ->toThrow(QueryException::class, 'exceeds the reviewed contract consideration')
        ->and($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract)['asset']->decimal())->toBe('0.00');
});

it('rebills a waived penalty beside a billable bonus and accepts restated numerals in a reassessment', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'mixed');
    $bonus = ['reference' => 'bonus', 'expected' => '20', 'included' => '0', 'billable' => '20', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Clawback bonus', 'highly_probable' => false];
    $penalty = ['reference' => 'penalty', 'expected' => '-10', 'included' => '-10', 'limit' => '-10', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Penalty', 'highly_probable' => true];
    $waived = ['reference' => 'penalty', 'expected' => '0', 'included' => '0', 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Penalty waived', 'highly_probable' => true];
    $terms = fn (array $penalty): array => [...contractRevenueTerms('100', single: true), 'variable' => [$bonus, $penalty]];
    $contract = $this->ledger->finance->open($this->book, 'mixed', 'MIXED', '2026-01-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Bonus and penalty', 'party_id' => $fixture['party'], 'terms' => $terms($penalty)], ['contract' => 'mixed']);
    $this->ledger->finance->recognize($this->book, $contract, 'done', '2026-01-10', 'service', '1', 'Complete');
    $invoice = $this->ledger->finance->invoiceRevenue($this->book, $contract, 'bill', '2026-01-10', '120', 'INV-1', '2026-02-10', 'Billing right', 'Bill');
    $this->ledger->finance->creditRevenue($this->book, $contract, 'penalty-refund', '2026-01-11', '10', 'CR-1', $invoice, 'refund', 'Penalty refund', 'Refund');
    $this->ledger->finance->reviseRevenue($this->book, $contract, 'waive', '2026-01-12', $contract, $terms($waived), 'variable_reassessment', 'Penalty waived', 'Waive');
    $this->ledger->finance->invoiceRevenue($this->book, $contract, 'rebill', '2026-01-13', '10', 'INV-2', '2026-02-13', 'Waived penalty due again', 'Rebill');
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract)['billed']->decimal())->toBe('120.00')
        ->and($this->ledger->finance->reviseRevenue($this->book, $fixture['contract'], 'numerals', '2026-01-13', $fixture['contract'],
            [...contractRevenueTerms('100.00', single: true), 'obligations' => [['reference' => 'service', 'standalone_price' => '100.0']]], 'variable_reassessment', 'Restated numerals', 'Same terms'))
        ->toBeString();
});

it('bounds upward billing ahead of the constraint by an explicit reviewed billable amount', function (array $variable, string $message) {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'billable');
    expect(fn () => $this->ledger->finance->open($this->book, 'billable', 'BILLABLE', '2026-01-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Billable bonus', 'party_id' => $fixture['party'],
            'terms' => [...contractRevenueTerms('100', single: true), 'variable' => [...$variable, 'estimation_method' => 'most_likely_amount', 'constraint_reference' => 'Reviewed', 'highly_probable' => false]]],
        ['contract' => 'billable']))->toThrow(QueryException::class, $message);
})->with([
    'above the estimate' => [['expected' => '20', 'included' => '0', 'billable' => '25'], 'between the included amount and the unconstrained estimate'],
    'on a rebate' => [['expected' => '-20', 'included' => '-25', 'limit' => '-30', 'billable' => '0'], 'Only upward variable consideration'],
]);

it('keeps contract-asset allowances out of receivable allowance runs and requires a credit-normal allowance account', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $allowance = $this->ledger->configurations->account($this->book, $fixture['configuration'], 'contract.allowance');
    $receivable = $this->ledger->configurations->account($this->book, $fixture['configuration'], 'receivable');
    $expense = $this->ledger->configurations->account($this->book, $fixture['configuration'], 'contract.impairment');
    expect(fn () => $this->ledger->settlements->provide($this->book, 'adopt', $receivable, $allowance, $expense, '2026-01-31', ['current' => '0.05', '1-30' => '0.05', '31-60' => '0.05', '61-90' => '0.05', '91+' => '0.05'], 'Adopt'))
        ->toThrow(QueryException::class, 'cannot enter another valuation register');
    $debit = $this->ledger->configurations->install($this->book, new Pack('debit-allowance', '1', accounts: [
        ['code' => '1336', 'name' => 'Debit-normal allowance', 'type' => 'asset'], ['code' => '1331', 'name' => 'Second contract asset', 'type' => 'asset'],
        ['code' => '2601', 'name' => 'Second contract liability', 'type' => 'liability'], ['code' => '2611', 'name' => 'Second refund liability', 'type' => 'liability'],
    ], roles: ['contract.asset' => '1331', 'contract.liability' => '2601', 'contract.refund' => '2611', 'contract.allowance' => '1336', 'contract.impairment' => '5100',
        'receivable' => '1100', 'revenue.service' => '4300']));
    expect(fn () => $this->ledger->finance->open($this->book, 'debit-allowance', 'DEBIT-ALLOWANCE', '2026-01-01', 'contract_revenue', $debit,
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Contract', 'party_id' => $fixture['party'], 'terms' => contractRevenueTerms('100', '0', true)]))
        ->toThrow(QueryException::class, 'incompatible account: contract.allowance');
});

it('impairs a contract asset and requires remeasurement before billing below its allowance', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $contract = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $contract, 'earned', '2026-01-10', 'service', '1', 'Earned before billing right');
    expect(fn () => $this->ledger->finance->impairContractAsset($this->book, $contract, 'excess', '2026-01-31', '100.01', 'Lifetime ECL:1', 'Excess loss'))
        ->toThrow(QueryException::class, 'loss allowance');
    $impairment = $this->ledger->finance->impairContractAsset($this->book, $contract, 'impair', '2026-01-31', '5', 'Lifetime ECL:1', 'Simplified-approach lifetime loss');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $allowance = $this->ledger->configurations->account($this->book, $fixture['configuration'], 'contract.allowance');
    expect($this->ledger->finance->revenuePosition($snapshot, $contract)['allowance']->decimal())->toBe('5.00')
        ->and(byAccount($this->ledger->reports->trialBalance($snapshot))[$allowance]->closing->decimal())->toBe('-5.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe('95.00')
        ->and(fn () => $this->ledger->finance->invoiceRevenue($this->book, $contract, 'bill', '2026-02-01', '100', 'INV-1', '2026-03-01', 'Billing right', 'Bill'))
        ->toThrow(QueryException::class, 'Remeasure contract-asset impairment');
    $this->ledger->finance->impairContractAsset($this->book, $contract, 'release', '2026-02-01', '0', 'Lifetime ECL:2', 'Release before billing');
    $this->ledger->finance->invoiceRevenue($this->book, $contract, 'bill', '2026-02-01', '100', 'INV-1', '2026-03-01', 'Billing right', 'Bill');
    expect($this->ledger->finance->revenuePosition($this->ledger->reports->snapshot($this->book, '2026-02-28'), $contract)['allowance']->decimal())->toBe('0.00')
        ->and($this->ledger->finance->evidence($snapshot, $contract)['events'][1]->id)->toBe($impairment);
});

it('enforces revenue correction, billing and separate-contract guards', function (string $case, string $message) {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $contract = $fixture['contract'];
    $finance = $this->ledger->finance;
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'addition');
    $other = $this->ledger->catalog->party($this->entity, 'OTHER-CUSTOMER', 'Other customer');
    $addition = fn (string $party) => $finance->open($this->book, 'addition', 'ADDITION', '2026-02-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Distinct addition', 'party_id' => $party, 'terms' => contractRevenueTerms('30', '0', true),
            'separate_from' => $contract, 'modification_reference' => 'MOD-1', 'distinct' => true, 'standalone_priced' => true], ['contract' => 'addition']);
    $action = match ($case) {
        'stale-terms' => function () use ($finance, $contract): void {
            $finance->reviseRevenue($this->book, $contract, 'bonus', '2026-01-05', $contract, contractRevenueTerms('100', '10', true), 'variable_reassessment', 'Bonus', 'Bonus');
            $finance->recognize($this->book, $contract, 'stale', '2026-01-06', 'service', '1', 'Stale worker', termsId: $contract);
        },
        'reduce-progress' => function () use ($finance, $contract): void {
            $finance->recognize($this->book, $contract, 'half', '2026-01-05', 'service', '0.5', 'Half');
            $finance->recognize($this->book, $contract, 'less', '2026-01-06', 'service', '0.4', 'Less');
        },
        'credit-corrected' => function () use ($finance, $contract): void {
            $invoice = $finance->invoiceRevenue($this->book, $contract, 'bill', '2026-01-05', '50', 'INV-1', '2026-02-01', 'Right', 'Bill');
            $finance->reverse($this->book, $invoice, 'undo-bill', '2026-01-06', 'Undo');
            $finance->creditRevenue($this->book, $contract, 'credit', '2026-01-07', '10', 'CR-1', $invoice, 'billing_correction', 'Credit', 'Credit');
        },
        'refund-without-liability' => function () use ($finance, $contract): void {
            $invoice = $finance->invoiceRevenue($this->book, $contract, 'bill', '2026-01-05', '50', 'INV-1', '2026-02-01', 'Right', 'Bill');
            $finance->creditRevenue($this->book, $contract, 'credit', '2026-01-07', '10', 'CR-1', $invoice, 'refund', 'Credit', 'Credit');
        },
        'early-cancel' => function () use ($finance, $contract): void {
            $finance->recognize($this->book, $contract, 'half', '2026-01-05', 'service', '0.5', 'Half');
            $finance->cancel($this->book, $contract, 'cancel', '2026-01-06', 'Cancel');
        },
        'drop-obligation' => fn () => $finance->reviseRevenue($this->book, $contract, 'drop', '2026-01-05', $contract, contractRevenueTerms('100'), 'cumulative_catch_up', 'Scope', 'Scope',
            ['equipment' => '0', 'service' => '0'], false),
        'other-customer' => fn () => $addition($other),
        'parent-cancel' => function () use ($finance, $contract, $addition, $fixture): void {
            $addition($fixture['party']);
            $finance->cancel($this->book, $contract, 'cancel-parent', '2026-02-02', 'Cancel parent');
        },
        default => throw new RuntimeException('Unknown case'),
    };
    expect($action)->toThrow(QueryException::class, $message);
})->with([
    ['stale-terms', 'current terms'], ['reduce-progress', 'before reducing progress'], ['credit-corrected', 'corrected invoice'],
    ['refund-without-liability', 'refund liability'], ['early-cancel', 'before cancellation'], ['drop-obligation', 'integrated obligation identities'],
    ['other-customer', 'live original customer contract'], ['parent-cancel', 'separate contract additions'],
]);

it('nets asset and liability per contract when contracts share configured accounts', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $this->ledger->catalog->dimensionValue($this->entity, 'contract', 'second');
    $second = $this->ledger->finance->open($this->book, 'second', 'SECOND', '2026-01-01', 'contract_revenue', $fixture['configuration'],
        ['policy_version' => 'IFRS15-reviewed:1', 'basis_reference' => 'Second contract', 'party_id' => $fixture['party'], 'terms' => contractRevenueTerms('100', '0', true)], ['contract' => 'second']);
    $this->ledger->finance->recognize($this->book, $fixture['contract'], 'first-half', '2026-01-10', 'service', '0.5', 'Half earned');
    $this->ledger->finance->invoiceRevenue($this->book, $second, 'second-advance', '2026-01-10', '30', 'INV-2', '2026-02-01', 'Advance', 'Advance');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$this->ledger->configurations->account($this->book, $fixture['configuration'], 'contract.asset')]->closing->decimal())->toBe('50.00')
        ->and($balances[$this->ledger->configurations->account($this->book, $fixture['configuration'], 'contract.liability')]->closing->decimal())->toBe('-30.00');
});

it('independently rejects a forged no-journal revenue decision and blocks credit beyond the linked invoice', function () {
    $fixture = contractRevenueFixture($this->ledger, $this->book, $this->entity, single: true);
    $contract = $fixture['contract'];
    $this->ledger->finance->recognize($this->book, $contract, 'half-service', '2026-01-02', 'service', '0.5', 'Reviewed halfway progress');
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->storage->run(Permission::Finance, fn () => $this->storage->insert('ledger_finance_events', ['id' => (string) Str::uuid7(), 'book_id' => $this->book, 'contract_id' => $contract,
        'idempotency_key' => 'forged-noop', 'fingerprint' => str_repeat('a', 64), 'effective_on' => '2026-01-02', 'kind' => 'recognize', 'inputs' => '{"reference":"service","cumulative_fraction":"0.5"}',
        'calculation' => '{}', 'carrying_delta' => '0', 'allowance_delta' => '0', 'written_off_delta' => '0', 'recovered_delta' => '0', 'stage' => 1, 'prior_stage' => 1, 'reason' => 'Forged unchanged decision']), $this->book))
        ->toThrow(QueryException::class, 'calculation evidence')->and($this->ledger->events->cutoff($this->book))->toBe($before);
    $invoice = $this->ledger->finance->invoiceRevenue($this->book, $contract, 'partial-invoice', '2026-01-03', '30', 'INV-PARTIAL', '2026-02-01', 'Unconditional right', 'Partial bill');
    $this->ledger->finance->creditRevenue($this->book, $contract, 'partial-credit', '2026-01-04', '20', 'CREDIT-PARTIAL', $invoice, 'billing_correction', 'Approved credit', 'Partial credit');
    expect(fn () => $this->ledger->finance->creditRevenue($this->book, $contract, 'excess-credit', '2026-01-05', '11', 'CREDIT-EXCESS', $invoice, 'billing_correction', 'Unsupported credit', 'Excess credit'))
        ->toThrow(QueryException::class, 'Credits exceed');
});

/** @param array<string, mixed> $inputs */
function financeRequest(string $book, string $configuration, string $key, string $date, array $inputs): TreatmentRequest
{
    return new TreatmentRequest($book, $key, $date, new Source('finance.example', $key), 'ledger.finance', '1', $configuration,
        [...$inputs, 'rounding' => 'half_even', 'policy_version' => 'example:1', 'basis_reference' => 'reviewed-inputs:42']);
}

it('posts lender and borrower effective interest with explicit cash-flow classification', function (string $position) {
    $configuration = financeConfiguration($this->ledger, $this->book, $position);
    $loan = $this->ledger->configurations->account($this->book, $configuration, 'loan');
    $lender = $position === 'asset';
    $this->ledger->journals->post(new JournalRequest($this->book, 'loan-opening', '2026-01-01', 'Net advance after integral fee', [
        new Posting($loan, $lender ? Side::Debit : Side::Credit, Money::of('950', $this->kes)),
        new Posting($this->cash, $lender ? Side::Credit : Side::Debit, Money::of('950', $this->kes)),
    ], ['cash_flow' => [$lender ? 'investing' : 'financing' => $lender ? '-95000' : '95000']]));
    $request = financeRequest($this->book, $configuration, 'loan-first', '2026-06-30', [
        'operation' => 'loan.period', 'position' => $position, 'opening' => '950', 'rate_per_slot' => '0.1', 'payment' => '100', 'interest_paid' => '95', 'interest_cash_flow' => 'operating',
    ]);
    $journal = $this->ledger->treatments->post($request);
    $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'loan-final', '2026-12-31', [
        'operation' => 'loan.period', 'position' => $position, 'opening' => '945', 'rate_per_slot' => '0.1', 'payment' => '1039.50', 'interest_paid' => '94.50', 'interest_cash_flow' => 'operating',
    ]));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$loan]->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe($lender ? '189.50' : '-189.50')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
    $this->ledger->periods->close($this->book, $this->period, 'Close measured lending');
    expect($this->ledger->treatments->post($request)->id)->toBe($journal->id);
})->with(['asset', 'liability']);

it('includes integral fees and transaction costs in effective interest without immediate fee income', function (string $kind, string $cost, string $opening, string $interest) {
    $asset = $kind === 'loan_asset';
    $configuration = financeConfiguration($this->ledger, $this->book, $asset ? 'asset' : 'liability');
    $rate = (new FinancialCalculator)->effectiveRate(Money::of($opening, $this->kes), [['due_on' => '2026-12-31', 'payment' => Money::of('1100', $this->kes)]]);
    $inputs = ['opening' => $opening, 'rate_per_slot' => $rate, 'interest_cash_flow' => 'operating', 'policy_version' => 'reviewed-integral-fees:1', 'basis_reference' => 'Signed financing and directly attributable cost review',
        'origination' => ['principal' => '1000', 'integral_fee' => '50', 'transaction_cost' => $cost, 'fee_reference' => 'Integral origination fee approval', 'cost_reference' => 'Direct incremental transaction cost approval',
            'rate_reference' => 'Reviewed exact cash-flow EIR', 'fee_cash_flow' => $asset ? 'investing' : 'financing', 'cost_cash_flow' => $asset ? 'investing' : 'financing'],
        'payments' => [['due_on' => '2026-12-31', 'payment' => '1100', 'interest_paid' => '100']]];
    $contract = $this->ledger->finance->open($this->book, 'integral-fees', 'INTEGRAL-FEES', '2026-01-01', $kind, $configuration, $inputs);
    $saved = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($saved, $contract)['carrying']->decimal())->toBe($opening.'.00')
        ->and($this->ledger->reports->incomeStatement($saved, '2026-01-01')['profit']->decimal())->toBe('0.00');
    $event = $this->ledger->finance->period($this->book, $contract, 'integral-final', '2026-12-31', 1, 'Accrete all integral fee and cost effects');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe(($asset ? '' : '-').$interest.'.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue()
        ->and(data_get($this->ledger->finance->evidence($snapshot, $contract)['definition']->inputs, 'origination.integral_fee'))->toBe('50');
    $this->ledger->finance->reverse($this->book, $event, 'undo-integral-final', '2026-12-31', 'Restore original cost');
    $this->ledger->finance->cancel($this->book, $contract, 'undo-integral-opening', '2026-12-31', 'Correct entire origination');
    expect(byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-12-31')))[$this->cash]->closing->decimal())->toBe('0.00');
})->with([['loan_asset', '0', '950', '150'], ['loan_asset', '20', '970', '130'], ['loan_liability', '20', '930', '170']]);

it('resets only future floating slots while preserving accrued history and the effective margin', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'floating', 'FLOATING', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'floating' => ['policy_version' => 'floating-policy:1', 'reference' => 'Reviewed benchmark fixing:1', 'benchmark_rate' => '0.06', 'contractual_spread' => '0.04', 'amortization' => 'fixed_principal']]);
    $this->ledger->finance->period($this->book, $contract, 'before-reset', '2026-06-30', 1, 'Accrue original rate');
    $saved = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $reset = $this->ledger->finance->resetRate($this->book, $contract, 'market-reset', '2026-06-30', [['due_on' => '2026-12-31', 'payment' => '1058.40', 'interest_paid' => '113.40']],
        '0.12', '0.08', 'Pure market benchmark change under original contract', 'Record new benchmark');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract);
    expect($position['carrying']->decimal())->toBe('945.00')->and($this->ledger->finance->plan($saved, $contract)['rate_per_slot'])->toBe('0.1')
        ->and($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['rate_per_slot'])->toBe('0.12');
    $last = $this->ledger->finance->period($this->book, $contract, 'after-reset', '2026-12-31', 1, 'Accrue only revised future slot', planId: $reset);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('208.40')
        ->and($this->ledger->finance->evidence($snapshot, $contract)['events'][1]->journalId)->toBeNull();
    $this->ledger->finance->reverse($this->book, $last, 'undo-floating-last', '2026-12-31', 'Correct future cash');
    $this->ledger->finance->reverse($this->book, $reset, 'undo-floating-reset', '2026-12-31', 'Restore original benchmark');
    expect($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['rate_per_slot'])->toBe('0.1')
        ->and($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying']->decimal())->toBe('945.00');
});

it('derecognizes and replaces a substantially modified loan with a new instrument identity and effective rate', function (string $kind) {
    $asset = $kind === 'loan_asset';
    $configuration = financeConfiguration($this->ledger, $this->book, $asset ? 'asset' : 'liability');
    $contract = $this->ledger->finance->open($this->book, 'replace-loan', 'REPLACE-LOAN', '2026-01-01', $kind, $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'before-replacement', '2026-06-30', 1, 'Original installment');
    if ($asset) {
        $this->ledger->finance->measureLoss($this->book, $contract, 'old-allowance', '2026-06-30', ['stage' => 2, 'model_reference' => 'Old instrument allowance', 'contractual' => [['period' => 0, 'amount' => '945']], 'scenarios' => [['weight' => '1', 'receipts' => ['895']]]], 'Old credit assessment');
    }
    $saved = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $payments = [['due_on' => '2026-12-31', 'payment' => '840', 'interest_paid' => '40']];
    $review = ['policy_version' => 'derecognition-policy:1', 'reference' => 'Reviewed legal and economic replacement', 'qualitative_substantial' => false, ...($asset ? ['asset_derecognition' => true, 'credit_impaired_at_recognition' => false] : [])];
    $change = $this->ledger->finance->replaceLoan($this->book, $contract, 'replacement', '2026-06-30', $payments, '800', '0.05',
        $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
        $review, 'Reviewed non-POCI fair value and replacement terms', 'Replace the original instrument', $asset ? '0' : '20', '0', $asset ? '0' : '5', 'financing', 'financing',
        $asset ? modifiedLoanModel($payments, 1, ['829.50']) : null);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $plan = $this->ledger->finance->plan($snapshot, $contract);
    $position = $this->ledger->finance->position($snapshot, $contract);
    expect($plan['instrument_id'])->toBe($change)->and($plan['rate_per_slot'])->toBe('0.05')->and($position['carrying']->decimal())->toBe('800.00')
        ->and($position['allowance']->decimal())->toBe($asset ? '10.00' : '0.00')->and($position['stage'])->toBe(1)
        ->and($this->ledger->finance->plan($saved, $contract)['instrument_id'])->toBe($contract);
    $last = $this->ledger->finance->period($this->book, $contract, 'replacement-period', '2026-12-31', 1, 'Complete replacement instrument', planId: $change);
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying']->decimal())->toBe('0.00');
    $this->ledger->finance->reverse($this->book, $last, 'undo-replacement-period', '2026-12-31', 'Restore replacement');
    $this->ledger->finance->reverse($this->book, $change, 'undo-replacement', '2026-12-31', 'Restore original financial instrument');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract);
    expect($position['carrying']->decimal())->toBe('945.00')->and($position['allowance']->decimal())->toBe($asset ? '50.00' : '0.00')
        ->and($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['rate_per_slot'])->toBe('0.1');
})->with(['loan_asset', 'loan_liability']);

it('preserves unamortized integral fees when re-estimating a floating effective rate from changed coupons', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $calculator = new FinancialCalculator;
    $originalRate = $calculator->effectiveRate(Money::of('950', $this->kes), [['due_on' => '2026-12-31', 'payment' => Money::of('1100', $this->kes)]]);
    $contract = $this->ledger->finance->open($this->book, 'fee-bearing-floater', 'FEE-BEARING-FLOATER', '2026-01-01', 'loan_asset', $configuration,
        ['opening' => '950', 'rate_per_slot' => $originalRate, 'interest_cash_flow' => 'operating', 'policy_version' => 'integral-floating:1', 'basis_reference' => 'Reviewed floating principal and integral fee',
            'origination' => ['principal' => '1000', 'integral_fee' => '50', 'transaction_cost' => '0', 'fee_reference' => 'Integral fee', 'cost_reference' => 'No costs', 'rate_reference' => 'Initial cash-flow EIR', 'fee_cash_flow' => 'operating', 'cost_cash_flow' => 'investing'],
            'floating' => ['policy_version' => 'benchmark:1', 'reference' => 'Original market fixing', 'benchmark_rate' => '0.06', 'contractual_spread' => '0.04', 'amortization' => 'fixed_principal'],
            'payments' => [['due_on' => '2026-12-31', 'payment' => '1100', 'interest_paid' => '100']]]);
    $revisedRate = $calculator->effectiveRate(Money::of('950', $this->kes), [['due_on' => '2026-12-31', 'payment' => Money::of('1120', $this->kes)]]);
    $reset = $this->ledger->finance->resetRate($this->book, $contract, 'fee-bearing-reset', '2026-01-01', [['due_on' => '2026-12-31', 'payment' => '1120', 'interest_paid' => '120']],
        $revisedRate, '0.08', 'Market-only coupon changes from 10% to 12%; recompute EIR without expensing the remaining fee', 'Re-estimate floating EIR');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract)['carrying']->decimal())->toBe('950.00');
    $this->ledger->finance->period($this->book, $contract, 'fee-bearing-final', '2026-12-31', 1, 'Receive principal and revised contractual coupon', planId: $reset);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('170.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->operating->decimal())->toBe('170.00');
});

it('remeasures a lease at a reviewed revised discount rate and corrects every affected cent', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $contract = $this->ledger->finance->open($this->book, 'rate-lease', 'RATE-LEASE', '2026-01-01', 'lease', $configuration,
        ['policy_version' => 'lease-policy:1', 'basis_reference' => 'Reviewed lease', 'rate_per_slot' => '0.1', 'rate_period_months' => 1, 'interest_cash_flow' => 'operating',
            'payments' => [['due_on' => '2026-01-31', 'payment' => '121', 'interest_paid' => '21'], ['due_on' => '2026-02-28', 'payment' => '121', 'interest_paid' => '11']]]);
    $this->ledger->finance->period($this->book, $contract, 'lease-before-rate-change', '2026-01-31', 1, 'Original lease slot');
    $saved = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    $change = $this->ledger->finance->remeasureLease($this->book, $contract, 'lease-rate-change', '2026-01-31', [['due_on' => '2026-02-28', 'payment' => '120', 'interest_paid' => '20']],
        $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'), '210', 'Reviewed lease-term reassessment', 'Remeasure at revised rate',
        revisedRate: '0.2', rateRevisionReference: 'IFRS16 reviewed revised discount-rate requirement');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract)['carrying']->decimal())->toBe('100.00')
        ->and($this->ledger->finance->position($saved, $contract)['carrying']->decimal())->toBe('110.00');
    $last = $this->ledger->finance->period($this->book, $contract, 'lease-revised-rate-period', '2026-02-28', 1, 'Pay revised lease', planId: $change);
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-02-28'), $contract)['carrying']->decimal())->toBe('0.00');
    $this->ledger->finance->reverse($this->book, $last, 'undo-revised-lease-period', '2026-03-01', 'Restore lease');
    $this->ledger->finance->reverse($this->book, $change, 'undo-revised-lease-rate', '2026-03-01', 'Restore prior discount rate');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-03-01'), $contract)['carrying']->decimal())->toBe('110.00');
});

/** @return array<string, mixed> */
function reviewedStageAssessment(int $days = 0): array
{
    return ['policy_version' => 'credit-staging-policy:1', 'reference' => 'Reviewed borrower risk and backstops', 'credit_impaired' => false, 'defaulted' => false, 'significant_increase' => false,
        'low_credit_risk' => false, 'days_past_due' => $days, 'sicr_backstop_days' => 30, 'default_backstop_days' => 90,
        'slot_months' => 12, 'stage1_horizon_slots' => 1, 'lifetime_slots' => 2];
}

/** @return array<string, mixed> */
function floatingLoanInputs(): array
{
    return [...registeredLoanInputs(), 'floating' => ['policy_version' => 'floating-policy:1', 'reference' => 'Reviewed benchmark fixing:1', 'benchmark_rate' => '0.06', 'contractual_spread' => '0.04', 'amortization' => 'fixed_principal']];
}

it('keeps the last recognized carrying date when a later-dated correction reverses a reset', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'late-correction', 'LATE-CORRECTION', '2026-01-01', 'loan_asset', $configuration, floatingLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'first', '2026-06-30', 1, 'First period');
    $payments = [['due_on' => '2026-12-31', 'payment' => '1058.40', 'interest_paid' => '113.40']];
    $reset = $this->ledger->finance->resetRate($this->book, $contract, 'reset', '2026-06-30', $payments, '0.12', '0.08', 'Fixing:1', 'Benchmark fixing');
    $this->ledger->finance->reverse($this->book, $reset, 'late-undo', '2026-08-15', 'Withdraw fixing');
    expect($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying_on'])->toBe('2026-06-30')
        ->and(fn () => $this->ledger->finance->resetRate($this->book, $contract, 'mid-slot', '2026-08-15', $payments, '0.12', '0.08', 'Fixing:2', 'Mid-slot fixing'))
        ->toThrow(QueryException::class, 'recognized carrying date');
    $accrued = $this->ledger->catalog->account($this->book, '1320', 'Accrued interest', AccountType::Asset);
    $journal = $this->ledger->finance->accrueInterest($this->book, $contract, 'accrue', '2026-09-30', $accrued, 'September accrual');
    expect($this->ledger->journals->lines($this->book, $journal->id ?? '')[0]->amount->decimal())->toBe('47.25')
        ->and($journal?->metadata['accrual'])->toEqualCanonicalizing(['elapsed_days' => 92, 'slot_days' => 184, 'slot_interest_minor' => '9450']);
});

it('caps a revised-rate lease reduction at net right-of-use carrying and takes the excess to profit', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $contract = $this->ledger->finance->open($this->book, 'depreciated-lease', 'DEPRECIATED-LEASE', '2026-01-01', 'lease', $configuration,
        ['policy_version' => 'lease-policy:1', 'basis_reference' => 'Reviewed lease', 'rate_per_slot' => '0.1', 'interest_cash_flow' => 'operating',
            'payments' => [['due_on' => '2026-01-31', 'payment' => '100', 'interest_paid' => '19.09'], ['due_on' => '2026-02-28', 'payment' => '121', 'interest_paid' => '11']]]);
    $account = fn (string $role): string => $this->ledger->configurations->account($this->book, $configuration, $role);
    $this->ledger->journals->post(new JournalRequest($this->book, 'rou-depreciation', '2026-01-31', 'Right-of-use depreciation', [
        new Posting($account('lease.depreciation'), Side::Debit, Money::of('150', $this->kes)), new Posting($account('lease.accumulated'), Side::Credit, Money::of('150', $this->kes)),
    ]));
    $this->ledger->finance->period($this->book, $contract, 'first', '2026-01-31', 1, 'Pay January');
    $remeasure = fn (string $rou): string => $this->ledger->finance->remeasureLease($this->book, $contract, 'reassessed-'.$rou, '2026-01-31', [['due_on' => '2026-02-28', 'payment' => '121', 'interest_paid' => '60.50']],
        $account('loan.gain'), $account('loan.loss'), $rou, 'Reassessed lease term', 'Revised discount rate', revisedRate: '1', rateRevisionReference: 'Reviewed reassessment rate');
    expect(fn () => $remeasure('190.91'))->toThrow(QueryException::class, 'net of accumulated depreciation');
    $event = $remeasure('40.91');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('60.50')
        ->and($balances[$account('lease.asset')]->closing->decimal())->toBe('150.00')
        ->and($balances[$account('lease.accumulated')]->closing->decimal())->toBe('-150.00')
        ->and($balances[$account('loan.gain')]->closing->decimal())->toBe('-8.59');
    $this->ledger->finance->reverse($this->book, $event, 'undo-reassessment', '2026-01-31', 'Restore');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')));
    expect($balances[$account('lease.asset')]->closing->decimal())->toBe('190.91')->and($balances[$account('lease.liability')]->closing->decimal())->toBe('-110.00');
});

it('measures a replacement asset at fair value plus its own costs less fees and refuses credit-impaired originations', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $account = fn (string $role): string => $this->ledger->configurations->account($this->book, $configuration, $role);
    $contract = $this->ledger->finance->open($this->book, 'impaired-source', 'IMPAIRED-SOURCE', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'first', '2026-06-30', 1, 'First period');
    $this->ledger->finance->measureLoss($this->book, $contract, 'impaired', '2026-06-30', ['stage' => 3, 'model_reference' => 'Defaulted borrower',
        'contractual' => [['period' => 0, 'amount' => '945']], 'scenarios' => [['weight' => '1', 'receipts' => ['795']]]], 'Credit impaired');
    $this->ledger->finance->partialWriteOff($this->book, $contract, 'partial', '2026-06-30', '45', 'Partial write-off');
    $payments = [['due_on' => '2026-12-31', 'payment' => '840', 'interest_paid' => '40']];
    $rate = (new FinancialCalculator)->effectiveRate(Money::of('785', $this->kes), [['due_on' => '2026-12-31', 'payment' => Money::of('840', $this->kes)]]);
    $review = ['policy_version' => 'derecognition:1', 'reference' => 'Restructured after default', 'qualitative_substantial' => true, 'asset_derecognition' => true, 'credit_impaired_at_recognition' => true];
    $replace = fn (bool $creditImpaired, bool $unknownModelInput): string => $this->ledger->finance->replaceLoan($this->book, $contract, 'replacement', '2026-06-30', $payments, '800', $rate,
        $account('loan.gain'), $account('loan.loss'), [...$review, 'credit_impaired_at_recognition' => $creditImpaired], 'Reviewed fair value', 'Replace', '0', '20', '5', 'investing', 'investing',
        [...modifiedLoanModel($payments), ...($unknownModelInput ? ['bogus' => true] : [])]);
    expect(fn () => $replace(true, false))->toThrow(QueryException::class, 'credit-impaired assets are not supported')
        ->and(fn () => $replace(false, true))->toThrow(QueryException::class, 'Unknown replacement ECL model input');
    $replacement = $replace(false, false);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $position = $this->ledger->finance->position($snapshot, $contract);
    expect($position['carrying']->decimal())->toBe('785.00')->and($position['allowance']->decimal())->toBe('0.00')->and($position['stage'])->toBe(1)
        ->and(byAccount($this->ledger->reports->trialBalance($snapshot))[$account('loan.gain')]->closing->decimal())->toBe('-5.00')
        ->and(data_get($this->ledger->finance->evidence($snapshot, $contract)['events'][3]->calculation, 'initial_amortized_cost_minor'))->toBe('78500');
    $this->ledger->finance->period($this->book, $contract, 'replacement-period', '2026-12-31', 1, 'Scheduled replacement receipt', planId: $replacement);
    $final = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($final, $contract)['carrying']->decimal())->toBe('0.00')
        ->and(byAccount($this->ledger->reports->trialBalance($final))[$account('interest')]->closing->decimal())->toBe('-150.00');
});

it('refuses a fixing that would defer arrears or reshape principal and accepts one after a reviewed reschedule', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $account = fn (string $role): string => $this->ledger->configurations->account($this->book, $configuration, $role);
    $contract = $this->ledger->finance->open($this->book, 'arrears-floater', 'ARREARS-FLOATER', '2026-01-01', 'loan_asset', $configuration, floatingLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'short', '2026-06-30', 1, 'Short payment', paidAmount: '60', interestPaid: '60');
    $fixed = [['due_on' => '2026-12-31', 'payment' => '1103.20', 'interest_paid' => '118.20']];
    expect(fn () => $this->ledger->finance->resetRate($this->book, $contract, 'arrears-reset', '2026-06-30', $fixed, '0.12', '0.08', 'Fixing in arrears', 'Fixing'))
        ->toThrow(QueryException::class, 'cannot defer arrears');
    $rescheduled = [['due_on' => '2026-12-31', 'payment' => '1083.50', 'interest_paid' => '98.50']];
    $modification = $this->ledger->finance->modify($this->book, $contract, 'reschedule', '2026-06-30', $rescheduled, $account('loan.gain'), $account('loan.loss'),
        'Reviewed arrears reschedule', 'Capitalize overdue cash', modifiedLoanModel($rescheduled));
    $reset = $this->ledger->finance->resetRate($this->book, $contract, 'after-reschedule', '2026-06-30', $fixed, '0.12', '0.08', 'Fixing after reschedule', 'Fixing');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract)['carrying']->decimal())->toBe('985.00');
    $this->ledger->finance->period($this->book, $contract, 'after-reset', '2026-12-31', 1, 'Revised coupon', planId: $reset);
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying']->decimal())->toBe('0.00')
        ->and($modification)->not->toBe($reset);
});

it('ties floating schedules to their pinned amortization convention', function (string $case, ?string $message) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    if ($case === 'reshape') {
        $inputs = [...floatingLoanInputs(), 'rate_period_months' => 6, 'payments' => [['due_on' => '2026-06-30', 'payment' => '100', 'interest_paid' => '95'],
            ['due_on' => '2026-12-31', 'payment' => '569.50', 'interest_paid' => '94.50'], ['due_on' => '2027-06-30', 'payment' => '517', 'interest_paid' => '47']]];
        $contract = $this->ledger->finance->open($this->book, 'reshape', 'RESHAPE', '2026-01-01', 'loan_asset', $configuration, $inputs);
        $this->ledger->finance->period($this->book, $contract, 'first', '2026-06-30', 1, 'First');
        expect(fn () => $this->ledger->finance->resetRate($this->book, $contract, 'bullet', '2026-06-30', [['due_on' => '2026-12-31', 'payment' => '94.50', 'interest_paid' => '94.50'],
            ['due_on' => '2027-06-30', 'payment' => '1039.50', 'interest_paid' => '94.50']], '0.1', '0.06', 'Unchanged benchmark', 'Restructuring as a fixing'))
            ->toThrow(QueryException::class, 'keeps every remaining principal repayment');

        return;
    }
    $annuity = fn (string $first, string $last, string $interest): array => [['due_on' => '2026-06-30', 'payment' => $first, 'interest_paid' => '100'],
        ['due_on' => '2026-12-31', 'payment' => $last, 'interest_paid' => $interest]];
    $inputs = ['opening' => '1000', 'rate_per_slot' => '0.1', 'interest_cash_flow' => 'operating', 'policy_version' => 'annuity:1', 'basis_reference' => 'Level-payment floater',
        'payments' => $case === 'level' ? $annuity('576.19', '576.19', '52.38') : $annuity('600', '550', '50'),
        'floating' => ['policy_version' => 'floating:1', 'reference' => 'Fixing:1', 'benchmark_rate' => '0.06', 'contractual_spread' => '0.04', 'amortization' => 'level_payment']];
    if ($message !== null) {
        expect(fn () => $this->ledger->finance->open($this->book, 'annuity', 'ANNUITY', '2026-01-01', 'loan_asset', $configuration, $inputs))->toThrow(QueryException::class, $message);

        return;
    }
    $contract = $this->ledger->finance->open($this->book, 'annuity', 'ANNUITY', '2026-01-01', 'loan_asset', $configuration, $inputs);
    $this->ledger->finance->period($this->book, $contract, 'first', '2026-06-30', 1, 'First');
    $this->ledger->finance->resetRate($this->book, $contract, 'annuity-reset', '2026-06-30', [['due_on' => '2026-12-31', 'payment' => '586.67', 'interest_paid' => '62.86']],
        '0.12', '0.08', 'Annuity fixing', 'Recomputed final annuity');
    expect($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['rate_per_slot'])->toBe('0.12');
})->with([['reshape', null], ['level', null], ['not-level', 'recomputed annuity in every slot']]);

it('keeps the due date of a late-posted slot as the carrying date', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'late-posting', 'LATE-POSTING', '2026-01-01', 'loan_asset', $configuration, floatingLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'late', '2026-08-15', 1, 'First period posted late');
    $payments = [['due_on' => '2026-12-31', 'payment' => '1058.40', 'interest_paid' => '113.40']];
    expect($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying_on'])->toBe('2026-06-30')
        ->and(fn () => $this->ledger->finance->resetRate($this->book, $contract, 'late-fixing', '2026-08-15', $payments, '0.12', '0.08', 'Fixing', 'Mid-slot fixing'))
        ->toThrow(QueryException::class, 'recognized carrying date');
    $accrued = $this->ledger->catalog->account($this->book, '1320', 'Accrued interest', AccountType::Asset);
    $journal = $this->ledger->finance->accrueInterest($this->book, $contract, 'accrue', '2026-09-30', $accrued, 'September accrual');
    expect($this->ledger->journals->lines($this->book, $journal->id ?? '')[0]->amount->decimal())->toBe('47.25');
});

it('floors reviewed days past due at the oldest unpaid slot and assesses after a partial write-off', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'aged-arrears', 'AGED-ARREARS', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '300', 'rate_per_slot' => '0', 'rate_period_months' => 12, 'payments' => [
            ['due_on' => '2026-12-31', 'payment' => '100', 'interest_paid' => '0'], ['due_on' => '2027-12-31', 'payment' => '100', 'interest_paid' => '0'],
            ['due_on' => '2028-12-31', 'payment' => '100', 'interest_paid' => '0']]]);
    $this->ledger->periods->open($this->book, '2027', '2027-01-01', '2027-12-31');
    $this->ledger->finance->period($this->book, $contract, 'short', '2026-12-31', 1, 'Short', paidAmount: '60', interestPaid: '0');
    $this->ledger->finance->period($this->book, $contract, 'full', '2027-12-31', 2, 'Full', paidAmount: '100', interestPaid: '0');
    $flows = [['period' => 0, 'amount' => '40'], ['period' => 1, 'amount' => '100']];
    $scenarios = [['weight' => '1', 'defaults' => [['period' => 1, 'probability' => '0.5', 'receipts' => ['40', '0']]]]];
    $assessment = fn (int $days): array => [...reviewedStageAssessment($days), 'lifetime_slots' => 1, 'backstop_rebuttal_reference' => 'Reviewed cure plan', 'default_rebuttal_reference' => 'Reviewed collateral'];
    expect(fn () => $this->ledger->finance->assessCredit($this->book, $contract, 'understated', '2027-12-31', $assessment(31), $flows, $scenarios, 'Understated arrears'))
        ->toThrow(QueryException::class, 'fewer than the ledger shows');
    $this->ledger->finance->assessCredit($this->book, $contract, 'aged', '2027-12-31', $assessment(365), $flows, $scenarios, 'Ledger-consistent arrears');
    $this->ledger->finance->partialWriteOff($this->book, $contract, 'partial', '2027-12-31', '60', 'Partial write-off');
    $this->ledger->finance->assessCredit($this->book, $contract, 'after-write-off', '2027-12-31', $assessment(365), [['period' => 1, 'amount' => '100']],
        [['weight' => '1', 'defaults' => [['period' => 1, 'probability' => '0.5', 'receipts' => ['0']]]]], 'Reassess after write-off');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2027-12-31'), $contract)['carrying']->decimal())->toBe('80.00');
});

/** @return non-empty-list<array{due_on: string, payment: string, interest_paid: string}> */
function couponSchedule(int $principalMinor, int $couponBasisPoints, int $slots, int $firstMonth = 1): array
{
    $payments = [];
    $outstanding = $principalMinor;
    for ($slot = 1; $slot <= $slots; $slot++) {
        $product = $outstanding * $couponBasisPoints;
        $interest = intdiv($product, 10000);
        $remainder = $product % 10000;
        $interest += $remainder * 2 > 10000 || $remainder * 2 === 10000 && $interest % 2 === 1 ? 1 : 0;
        $principal = $slot === $slots ? $outstanding : intdiv($principalMinor, $slots);
        $outstanding -= $principal;
        $payments[] = ['due_on' => (new DateTimeImmutable('2026-01-01'))->modify('+'.($firstMonth + $slot - 1).' month')->modify('-1 day')->format('Y-m-d'),
            'payment' => bcdiv((string) ($principal + $interest), '100', 2), 'interest_paid' => bcdiv((string) $interest, '100', 2)];
    }

    return $payments === [] ? throw new RuntimeException('A coupon schedule needs a slot.') : $payments;
}

it('resets fee-free floaters whose rounded coupons do not present-value exactly to carrying', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'rounded-floater', 'ROUNDED-FLOATER', '2026-01-01', 'loan_asset', $configuration, [...registeredLoanInputs(), 'opening' => '1000.11',
        'rate_per_slot' => '0.04', 'payments' => couponSchedule(100011, 400, 5),
        'floating' => ['policy_version' => 'floating:1', 'reference' => 'Fixing:1', 'benchmark_rate' => '0.03', 'contractual_spread' => '0.01', 'amortization' => 'fixed_principal']]);
    $revised = couponSchedule(100011, 500, 5);
    $reset = $this->ledger->finance->resetRate($this->book, $contract, 'rounded-reset', '2026-01-01', $revised, '0.05', '0.04', 'Fixing:2', 'Rounded coupon fixing');
    expect($this->ledger->finance->plan($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract)['measurement'])->toBe('period_rounding');
    foreach ($revised as $index => $payment) {
        $this->ledger->finance->period($this->book, $contract, 'slot-'.$index, $payment['due_on'], $index + 1, 'Coupon slot', planId: $reset);
    }
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying']->decimal())->toBe('0.00');
});

it('computes level-payment annuities exactly and refuses a market fixing after a partial write-off', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'tiny-coupon', 'TINY-COUPON', '2026-01-01', 'loan_asset', $configuration, [...registeredLoanInputs(), 'opening' => '10000000.01',
        'rate_per_slot' => '0.000000001', 'payments' => [['due_on' => '2026-01-31', 'payment' => '3333333.34', 'interest_paid' => '0.01'],
            ['due_on' => '2026-02-28', 'payment' => '3333333.34', 'interest_paid' => '0.01'], ['due_on' => '2026-03-31', 'payment' => '3333333.35', 'interest_paid' => '0']],
        'floating' => ['policy_version' => 'floating:1', 'reference' => 'Fixing:1', 'benchmark_rate' => '0.000000001', 'contractual_spread' => '0', 'amortization' => 'level_payment']]);
    $this->ledger->finance->period($this->book, $contract, 'first', '2026-01-31', 1, 'First');
    $this->ledger->finance->partialWriteOff($this->book, $contract, 'partial', '2026-01-31', '10', 'Partial write-off');
    expect(fn () => $this->ledger->finance->resetRate($this->book, $contract, 'after-write-off', '2026-01-31', [['due_on' => '2026-02-28', 'payment' => '3333333.34', 'interest_paid' => '0.01'],
        ['due_on' => '2026-03-31', 'payment' => '3333333.35', 'interest_paid' => '0']], '0.000000001', '0.000000001', 'Fixing:2', 'Fixing'))
        ->toThrow(QueryException::class, 'After a partial write-off, re-estimate');
});

it('keeps the days-past-due floor after a write-off larger than the arrears', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $payments = [];
    foreach (['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'] as $due) {
        $payments[] = ['due_on' => $due, 'payment' => '100', 'interest_paid' => '0'];
    }
    $contract = $this->ledger->finance->open($this->book, 'written-arrears', 'WRITTEN-ARREARS', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '400', 'rate_per_slot' => '0', 'rate_period_months' => 1, 'payments' => $payments]);
    $this->ledger->finance->period($this->book, $contract, 'missed-1', '2026-01-31', 1, 'Missed', paidAmount: '0', interestPaid: '0');
    $this->ledger->finance->period($this->book, $contract, 'missed-2', '2026-02-28', 2, 'Missed', paidAmount: '0', interestPaid: '0');
    $this->ledger->finance->partialWriteOff($this->book, $contract, 'write-off', '2026-02-28', '350', 'Write off most of the balance');
    $this->ledger->finance->period($this->book, $contract, 'missed-3', '2026-03-31', 3, 'Missed', paidAmount: '0', interestPaid: '0');
    expect(fn () => $this->ledger->finance->assessCredit($this->book, $contract, 'current', '2026-03-31', [...reviewedStageAssessment(0), 'slot_months' => 1, 'lifetime_slots' => 1],
        [['period' => 1, 'amount' => '100']], [['weight' => '1', 'defaults' => [['period' => 1, 'probability' => '0.5', 'receipts' => ['0']]]]], 'Understated'))
        ->toThrow(QueryException::class, 'fewer than the ledger shows');

});

it('settles a lease against right-of-use depreciation dated on or before settlement', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $account = fn (string $role): string => $this->ledger->configurations->account($this->book, $configuration, $role);
    $contract = $this->ledger->finance->open($this->book, 'dated-lease', 'DATED-LEASE', '2026-01-01', 'lease', $configuration,
        ['policy_version' => 'lease-policy:1', 'basis_reference' => 'Reviewed lease', 'rate_per_slot' => '0.1', 'interest_cash_flow' => 'operating',
            'payments' => [['due_on' => '2026-01-31', 'payment' => '100', 'interest_paid' => '19.09'], ['due_on' => '2026-02-28', 'payment' => '121', 'interest_paid' => '11']]]);
    foreach (['2026-01-31' => '50', '2026-02-15' => '60'] as $date => $amount) {
        $this->ledger->journals->post(new JournalRequest($this->book, 'depreciation-'.$date, $date, 'Right-of-use depreciation', [
            new Posting($account('lease.depreciation'), Side::Debit, Money::of($amount, $this->kes)), new Posting($account('lease.accumulated'), Side::Credit, Money::of($amount, $this->kes)),
        ]));
    }
    $this->ledger->finance->period($this->book, $contract, 'first', '2026-01-31', 1, 'Pay January');
    $this->ledger->finance->settle($this->book, $contract, 'terminate', '2026-01-31', '110', '0', $account('loan.gain'), $account('loan.loss'), 'Reviewed termination', 'Terminate lease');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31')));
    expect($balances[$account('lease.asset')]->closing->decimal())->toBe('0.00')->and($balances[$account('lease.accumulated')]->closing->decimal())->toBe('0.00')
        ->and(byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')))[$account('lease.accumulated')]->closing->decimal())->toBe('-60.00');
});

it('keeps a reviewed stage through modifications and refuses leases without accumulated depreciation evidence', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $account = fn (string $role): string => $this->ledger->configurations->account($this->book, $configuration, $role);
    $contract = $this->ledger->finance->open($this->book, 'staged', 'STAGED', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '200', 'rate_per_slot' => '0', 'rate_period_months' => 12,
            'payments' => [['due_on' => '2026-12-31', 'payment' => '100', 'interest_paid' => '0'], ['due_on' => '2027-12-31', 'payment' => '100', 'interest_paid' => '0']]]);
    $flows = [['period' => 1, 'amount' => '100'], ['period' => 2, 'amount' => '100']];
    $this->ledger->finance->assessCredit($this->book, $contract, 'stage-two', '2026-01-01', reviewedStageAssessment(31), $flows,
        [['weight' => '1', 'defaults' => [['period' => 2, 'probability' => '0.2', 'receipts' => ['100', '0']]]]], 'Stage two');
    $revised = [['due_on' => '2026-12-31', 'payment' => '100', 'interest_paid' => '0'], ['due_on' => '2027-12-31', 'payment' => '100', 'interest_paid' => '0']];
    expect(fn () => $this->ledger->finance->modify($this->book, $contract, 'restage', '2026-01-01', $revised, $account('loan.gain'), $account('loan.loss'), 'Reviewed', 'Restage', modifiedLoanModel($revised)))
        ->toThrow(QueryException::class, 'through assessCredit');
    $bare = $this->ledger->configurations->install($this->book, new Pack('bare-lease', '1', accounts: [
        ['code' => '1600', 'name' => 'ROU', 'type' => 'asset'], ['code' => '2700', 'name' => 'Lease liability', 'type' => 'liability'], ['code' => '4700', 'name' => 'Lease interest', 'type' => 'expense'],
    ], roles: ['cash' => '1000', 'lease.asset' => '1600', 'lease.liability' => '2700', 'interest' => '4700']));
    expect(fn () => $this->ledger->finance->open($this->book, 'bare', 'BARE', '2026-01-01', 'lease', $bare, ['policy_version' => 'lease:1', 'basis_reference' => 'Lease',
        'rate_per_slot' => '0.1', 'interest_cash_flow' => 'operating', 'payments' => [['due_on' => '2026-01-31', 'payment' => '110', 'interest_paid' => '10']]]))
        ->toThrow(QueryException::class, 'complete account roles');
});

it('assesses a loan in arrears with its overdue cash at period zero and keeps staging with assessCredit', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'arrears-staging', 'ARREARS-STAGING', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '200', 'rate_per_slot' => '0', 'rate_period_months' => 12,
            'payments' => [['due_on' => '2026-12-31', 'payment' => '100', 'interest_paid' => '0'], ['due_on' => '2027-12-31', 'payment' => '100', 'interest_paid' => '0']]]);
    $this->ledger->finance->period($this->book, $contract, 'short', '2026-12-31', 1, 'Short receipt', paidAmount: '60', interestPaid: '0');
    $assessment = [...reviewedStageAssessment(31), 'lifetime_slots' => 1];
    $scenarios = [['weight' => '1', 'defaults' => [['period' => 1, 'probability' => '0.5', 'receipts' => ['40', '0']]]]];
    $assess = fn (string $key, int $days, ?string $arrears): string => $this->ledger->finance->assessCredit($this->book, $contract, $key, '2026-12-31', [...$assessment, 'days_past_due' => $days],
        [...($arrears === null ? [] : [['period' => 0, 'amount' => $arrears]]), ['period' => 1, 'amount' => '100']], $scenarios, 'Reviewed arrears staging');
    expect(fn () => $assess('missing', 31, null))->toThrow(QueryException::class, 'cover remaining contractual payments')
        ->and(fn () => $assess('wrong', 31, '30'))->toThrow(QueryException::class, 'period zero');
    $assess('arrears', 31, '40');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract);
    expect($position['stage'])->toBe(2)->and($position['allowance']->decimal())->toBe('50.00')
        ->and(fn () => $this->ledger->finance->measureLoss($this->book, $contract, 'restage', '2026-12-31', ['stage' => 1, 'model_reference' => 'Override',
            'contractual' => [['period' => 0, 'amount' => '140']], 'scenarios' => [['weight' => '1', 'receipts' => ['140']]]], 'Bypass staging'))->toThrow(QueryException::class, 'through assessCredit');
});

it('re-estimates a fee-bearing floating rate after recognized interest without expensing the unamortized fee', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $calculator = new FinancialCalculator;
    $plan = [['due_on' => '2026-06-30', 'payment' => '100', 'interest_paid' => '100'], ['due_on' => '2026-12-31', 'payment' => '1100', 'interest_paid' => '100']];
    $contract = $this->ledger->finance->open($this->book, 'later-fee-floater', 'LATER-FEE-FLOATER', '2026-01-01', 'loan_asset', $configuration,
        ['opening' => '950', 'rate_per_slot' => $calculator->effectiveRate(Money::of('950', $this->kes), [['due_on' => '2026-06-30', 'payment' => Money::of('100', $this->kes)], ['due_on' => '2026-12-31', 'payment' => Money::of('1100', $this->kes)]]), 'interest_cash_flow' => 'operating',
            'policy_version' => 'integral-floating:1', 'basis_reference' => 'Reviewed floating principal and integral fee', 'payments' => $plan,
            'origination' => ['principal' => '1000', 'integral_fee' => '50', 'transaction_cost' => '0', 'fee_reference' => 'Integral fee', 'cost_reference' => 'No costs',
                'rate_reference' => 'Initial cash-flow EIR', 'fee_cash_flow' => 'operating', 'cost_cash_flow' => 'investing'],
            'floating' => ['policy_version' => 'benchmark:1', 'reference' => 'Original fixing', 'benchmark_rate' => '0.06', 'contractual_spread' => '0.04', 'amortization' => 'fixed_principal']]);
    $this->ledger->finance->period($this->book, $contract, 'first-coupon', '2026-06-30', 1, 'First coupon');
    $carrying = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract)['carrying'];
    $revised = [['due_on' => '2026-12-31', 'payment' => '1120', 'interest_paid' => '120']];
    $reset = $this->ledger->finance->resetRate($this->book, $contract, 'later-reset', '2026-06-30', $revised, $calculator->effectiveRate($carrying, [['due_on' => '2026-12-31', 'payment' => Money::of('1120', $this->kes)]]), '0.08',
        'Fixing after first coupon', 'Re-estimate EIR from the carrying amount');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract)['carrying']->decimal())->toBe($carrying->decimal());
    $this->ledger->finance->period($this->book, $contract, 'final-coupon', '2026-12-31', 1, 'Final coupon', planId: $reset);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('270.00');
});

it('counts monthly defaults through the twelfth slot in stage one and the thirteenth only over lifetime', function (int $horizon, int $days, ?string $allowance) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $payments = [];
    for ($slot = 1; $slot <= 13; $slot++) {
        $payments[] = ['due_on' => (new DateTimeImmutable('2026-01-01'))->modify('+'.$slot.' month')->modify('-1 day')->format('Y-m-d'), 'payment' => '100', 'interest_paid' => '0'];
    }
    $contract = $this->ledger->finance->open($this->book, 'monthly-horizon', 'MONTHLY-HORIZON', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '1300', 'rate_per_slot' => '0', 'rate_period_months' => 1, 'payments' => $payments]);
    $flows = array_map(fn (int $slot): array => ['period' => $slot, 'amount' => '100'], range(1, 13));
    $receipts = fn (int $default): array => array_map(fn (int $slot): string => $slot < $default ? '100' : '0', range(1, 13));
    $assess = fn (): string => $this->ledger->finance->assessCredit($this->book, $contract, 'monthly', '2026-01-01',
        [...reviewedStageAssessment($days), 'slot_months' => 1, 'stage1_horizon_slots' => $horizon, 'lifetime_slots' => 13], $flows,
        [['weight' => '1', 'defaults' => [['period' => 12, 'probability' => '0.1', 'receipts' => $receipts(12)], ['period' => 13, 'probability' => '0.2', 'receipts' => $receipts(13)]]]], 'Monthly horizon');
    if ($allowance === null) {
        expect($assess)->toThrow(QueryException::class, 'twelve months');

        return;
    }
    $assess();
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract)['allowance']->decimal())->toBe($allowance);
})->with([[12, 0, '20.00'], [12, 31, '40.00'], [13, 0, null]]);

it('measures lifetime cash shortfalls from defaults inside the stage-one horizon and then lifetime defaults in stages two and three', function (string $rate) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $firstCash = $rate === '0' ? '100' : '110';
    $lastCash = $rate === '0' ? '100' : '121';
    $halfRecovery = $rate === '0' ? '50' : '60.50';
    $contract = $this->ledger->finance->open($this->book, 'default-horizons', 'DEFAULT-HORIZONS', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '200', 'rate_per_slot' => $rate, 'rate_period_months' => 12,
            'payments' => [['due_on' => '2026-12-31', 'payment' => $firstCash, 'interest_paid' => $rate === '0' ? '0' : '20'], ['due_on' => '2027-12-31', 'payment' => $lastCash, 'interest_paid' => $rate === '0' ? '0' : '11']]]);
    $flows = [['period' => 1, 'amount' => $firstCash], ['period' => 2, 'amount' => $lastCash]];
    $scenarios = [['weight' => '1', 'defaults' => [['period' => 1, 'probability' => '0.1', 'receipts' => [$firstCash, $halfRecovery]], ['period' => 2, 'probability' => '0.2', 'receipts' => [$firstCash, '0']]]]];
    $first = $this->ledger->finance->assessCredit($this->book, $contract, 'stage-one', '2026-01-01', reviewedStageAssessment(), $flows, $scenarios, 'Defaults inside twelve months cause losses after twelve months too');
    $saved = $this->ledger->reports->snapshot($this->book, '2026-01-01');
    expect($this->ledger->finance->position($saved, $contract)['allowance']->decimal())->toBe('5.00');
    $second = $this->ledger->finance->assessCredit($this->book, $contract, 'stage-two', '2026-01-01', reviewedStageAssessment(31), $flows, $scenarios, 'Unrebutted thirty-day SICR presumption');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract)['allowance']->decimal())->toBe('25.00');
    $third = $this->ledger->finance->assessCredit($this->book, $contract, 'stage-three', '2026-01-01', reviewedStageAssessment(90), $flows,
        [['weight' => '1', 'defaults' => [['period' => 0, 'probability' => '1', 'receipts' => $rate === '0' ? ['60', '40'] : ['55', '60.50']]]]], 'Unrebutted ninety-day default presumption');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract);
    expect($position['stage'])->toBe(3)->and($position['allowance']->decimal())->toBe('100.00')
        ->and($this->ledger->finance->position($saved, $contract)['allowance']->decimal())->toBe('5.00');
    foreach ([$third, $second, $first] as $index => $event) {
        $this->ledger->finance->reverse($this->book, $event, 'stage-correction-'.$index, '2026-01-01', 'Restore exact stage and horizon effects');
    }
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract);
    expect($position['stage'])->toBe(1)->and($position['allowance']->decimal())->toBe('0.00');
})->with(['0', '0.1']);

it('includes only lender-borrower fees in the exact replacement ten-percent test', function (string $fee, string $thirdParty, bool $accepted) {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $payments = [['due_on' => '2026-12-31', 'payment' => '1100', 'interest_paid' => '100']];
    $contract = $this->ledger->finance->open($this->book, 'replacement-threshold', 'REPLACEMENT-THRESHOLD', '2026-01-01', 'loan_liability', $configuration,
        [...registeredLoanInputs(), 'opening' => '1000', 'payments' => $payments]);
    $replace = fn (): string => $this->ledger->finance->replaceLoan($this->book, $contract, 'test-exchange', '2026-01-01', $payments, '1000', '0.1',
        $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
        ['policy_version' => 'liability-exchange:1', 'reference' => 'No qualitative substantiality; independently check quantitative threshold', 'qualitative_substantial' => false],
        'Reviewed exchange fair value', 'Evaluate ten-percent boundary', $fee, '0', $thirdParty, 'financing', 'financing');
    if ($accepted) {
        $event = $replace();
        $evidence = $this->ledger->finance->evidence($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['events'][0];
        expect($evidence->id)->toBe($event)->and(data_get($evidence->calculation, 'ten_percent_test'))->toBeTrue();
    } else {
        expect($replace)->toThrow(QueryException::class, 'ten-percent test');
    }
})->with([['100', '0', true], ['99.99', '1000', false], ['0', '1000', false]]);

it('does not collapse deferred payment time in the original-rate derecognition comparison', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $contract = $this->ledger->finance->open($this->book, 'timed-exchange', 'TIMED-EXCHANGE', '2026-01-01', 'loan_liability', $configuration,
        [...registeredLoanInputs(), 'opening' => '1000', 'rate_period_months' => 12, 'payments' => [['due_on' => '2026-12-31', 'payment' => '1100', 'interest_paid' => '100']]]);
    $gain = $this->ledger->configurations->account($this->book, $configuration, 'loan.gain');
    $loss = $this->ledger->configurations->account($this->book, $configuration, 'loan.loss');
    $review = ['policy_version' => 'exchange:1', 'reference' => 'Review deferred contractual cash', 'qualitative_substantial' => false];
    expect(fn () => $this->ledger->finance->replaceLoan($this->book, $contract, 'collapsed-time', '2026-01-01', [['due_on' => '2027-12-31', 'payment' => '1210', 'interest_paid' => '210']], '1000', '0.1',
        $gain, $loss, $review, 'Deferred schedule', 'Incorrectly collapse time', '0', '0', '0', 'financing', 'financing'))->toThrow(QueryException::class, 'explicit zero-payment slots');
    expect(fn () => $this->ledger->finance->replaceLoan($this->book, $contract, 'preserved-time', '2026-01-01', [['due_on' => '2026-12-31', 'payment' => '0', 'interest_paid' => '0'], ['due_on' => '2027-12-31', 'payment' => '1210', 'interest_paid' => '210']], '1000', '0.1',
        $gain, $loss, $review, 'Same original-rate present value after retaining time', 'No quantitative derecognition', '0', '0', '0', 'financing', 'financing'))->toThrow(QueryException::class, 'ten-percent test');
});

it('rejects default-time models with incomplete horizons, excess probability or losses before default', function (string $case, string $message) {
    $assessment = reviewedStageAssessment();
    $defaults = [['period' => 1, 'probability' => '0.1', 'receipts' => ['100', '50']], ['period' => 2, 'probability' => '0.2', 'receipts' => ['100', '0']]];
    if ($case === 'horizon') {
        $assessment['stage1_horizon_slots'] = 2;
    } elseif ($case === 'probability') {
        $defaults[0]['probability'] = '0.7';
        $defaults[1]['probability'] = '0.4';
    } elseif ($case === 'before-default') {
        $defaults[1]['receipts'] = ['90', '0'];
    } elseif ($case === 'low-risk') {
        $assessment = [...$assessment, 'low_credit_risk' => true, 'days_past_due' => 31];
    }
    $model = ['assessment' => $assessment, 'contractual' => [['period' => 1, 'amount' => '100'], ['period' => 2, 'amount' => '100']], 'scenarios' => [['weight' => '1', 'defaults' => $defaults]]];
    expect(fn () => $this->storage->connection->selectOne('SELECT ledger_finance_stage_loss(?::jsonb, 0, 2)', [json_encode($model, JSON_THROW_ON_ERROR)]))->toThrow(QueryException::class, $message);
})->with([['horizon', 'twelve months'], ['probability', 'probabilities cannot exceed one'], ['before-default', 'cannot precede their default time'], ['low-risk', 'low-credit-risk election conflicts']]);

it('refuses to infer a twelve-month default horizon across a shorter first interest stub', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'stub-horizon', 'STUB-HORIZON', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), 'opening' => '200', 'rate_per_slot' => '0', 'rate_period_months' => 12,
            'payments' => [['due_on' => '2026-06-30', 'payment' => '100', 'interest_paid' => '0'], ['due_on' => '2027-06-30', 'payment' => '100', 'interest_paid' => '0']]]);
    expect(fn () => $this->ledger->finance->assessCredit($this->book, $contract, 'stub-model', '2026-01-01', reviewedStageAssessment(),
        [['period' => 1, 'amount' => '100'], ['period' => 2, 'amount' => '100']], [['weight' => '1', 'defaults' => [['period' => 1, 'probability' => '0.1', 'receipts' => ['100', '50']]]]], 'Do not confuse a six-month first slot with twelve months'))
        ->toThrow(QueryException::class, 'cannot infer stub periods');
});

it('rejects inconsistent origination amounts and market resets without changing state', function (string $case, string $message) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    if ($case === 'fee-basis') {
        $inputs = [...registeredLoanInputs(), 'opening' => '950', 'origination' => ['principal' => '1000', 'integral_fee' => '40', 'transaction_cost' => '0', 'fee_reference' => 'Fee review', 'cost_reference' => 'No costs',
            'rate_reference' => 'Rate review', 'fee_cash_flow' => 'investing', 'cost_cash_flow' => 'investing']];
        expect(fn () => $this->ledger->finance->open($this->book, 'invalid-fees', 'INVALID-FEES', '2026-01-01', 'loan_asset', $configuration, $inputs))->toThrow(QueryException::class, $message);

        return;
    }
    $contract = $this->ledger->finance->open($this->book, 'invalid-reset', 'INVALID-RESET', '2026-01-01', 'loan_asset', $configuration,
        [...registeredLoanInputs(), ...($case === 'fixed' ? [] : ['floating' => ['policy_version' => 'floating:1', 'reference' => 'Benchmark review', 'benchmark_rate' => '0.06', 'contractual_spread' => '0.04', 'amortization' => 'fixed_principal']])]);
    $this->ledger->finance->period($this->book, $contract, 'invalid-reset-before', '2026-06-30', 1, 'Original first period');
    $before = $this->ledger->events->cutoff($this->book);
    expect(fn () => $this->ledger->finance->resetRate($this->book, $contract, 'invalid-reset-attempt', '2026-06-30', [['due_on' => $case === 'dates' ? '2026-11-30' : '2026-12-31', 'payment' => '1058.40', 'interest_paid' => $case === 'coupon' ? '100' : '113.40']],
        $case === 'margin' ? '0.13' : '0.12', '0.08', 'Proposed reset', 'Invalid reset'))->toThrow(QueryException::class, $message)
        ->and($this->ledger->events->cutoff($this->book))->toBe($before);
})->with([['fee-basis', 'correct asset/liability signs'], ['fixed', 'registered floating instrument'], ['margin', 'benchmark plus contractual spread'],
    ['coupon', 'benchmark plus contractual spread on outstanding principal'], ['dates', 'preserve remaining dates']]);

it('commits revenue transfers and replacement instruments with linked corrections under forced tenant security', function () {
    $connection = Database::connection();
    $scope = new Scope((string) Str::uuid7(), 'reviewed-finance');
    $ledger = (new Ledger($connection, new PermitAll))->for($scope);
    $entity = $ledger->catalog->entity('Committed contract accounting');
    $book = $ledger->catalog->book($entity, 'Statutory', $this->kes);
    $ledger->periods->open($book, '2026', '2026-01-01', '2026-12-31');
    $ledger->catalog->account($book, '1000', 'Finance cash', AccountType::Asset, cashFlow: 'cash');
    $fixture = contractRevenueFixture($ledger, $book, $entity, single: true);
    $ledger->finance->recognize($book, $fixture['contract'], 'committed-earned', '2026-01-02', 'service', '1', 'Earn contract asset');
    $invoice = $ledger->finance->invoiceRevenue($book, $fixture['contract'], 'committed-invoice', '2026-01-03', '100', 'INV-COMMITTED', '2026-02-01', 'Unconditional right', 'Transfer to receivable');
    expect($ledger->reports->openItems($ledger->reports->snapshot($book, '2026-01-31'), $fixture['party'])[0]->outstanding->decimal())->toBe('100.00');
    $evidence = $ledger->finance->evidence($ledger->reports->snapshot($book, '2026-01-31'), $fixture['contract'])['events'][1];
    expect(fn () => $ledger->journals->reverse($book, text($evidence->journalId), 'detached-revenue', '2026-01-04', 'Detached reversal'))->toThrow(PDOException::class, 'linked contract evidence');
    $ledger->finance->reverse($book, $invoice, 'linked-revenue-correction', '2026-01-04', 'Restore contract asset');
    expect($ledger->finance->revenuePosition($ledger->reports->snapshot($book, '2026-01-31'), $fixture['contract'])['asset']->decimal())->toBe('100.00');
    $loan = $ledger->finance->open($book, 'committed-asset', 'COMMITTED-ASSET', '2026-01-01', 'loan_asset', $fixture['configuration'], registeredLoanInputs());
    $ledger->finance->period($book, $loan, 'committed-loan-period', '2026-06-30', 1, 'Original period');
    $payments = [['due_on' => '2026-12-31', 'payment' => '840', 'interest_paid' => '40']];
    $replacement = $ledger->finance->replaceLoan($book, $loan, 'committed-replacement', '2026-06-30', $payments, '800', '0.05',
        $ledger->configurations->account($book, $fixture['configuration'], 'loan.gain'), $ledger->configurations->account($book, $fixture['configuration'], 'loan.loss'),
        ['policy_version' => 'derecognition:1', 'reference' => 'Reviewed non-POCI asset derecognition', 'qualitative_substantial' => true, 'asset_derecognition' => true, 'credit_impaired_at_recognition' => false],
        'Reviewed replacement fair value', 'Replace instrument', '0', '0', '0', 'investing', 'investing', modifiedLoanModel($payments));
    $replacementJournal = text($ledger->finance->evidence($ledger->reports->snapshot($book, '2026-06-30'), $loan)['events'][1]->journalId);
    expect(fn () => $ledger->journals->reverse($book, $replacementJournal, 'detached-replacement', '2026-06-30', 'Detached replacement reversal'))->toThrow(PDOException::class, 'linked contract evidence');
    $ledger->finance->reverse($book, $replacement, 'committed-restore-loan', '2026-06-30', 'Restore whole original instrument');
    expect($ledger->finance->position($ledger->reports->snapshot($book, '2026-06-30'), $loan)['carrying']->decimal())->toBe('945.00');
    $storage = new ScopedDatabase($connection, $scope, new PermitAll);
    expect(fn () => $storage->run(Permission::Finance, fn () => $storage->connection->table('ledger_finance_events')->where('id', $replacement)->update(['calculation' => '{}']), $book))
        ->toThrow(QueryException::class, 'append-only');
});

it('posts a measured lease plan through its last cent and reverses its exact cash effects', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $inputs = ['rate_per_slot' => '0.1', 'in_advance' => false, 'payments' => [
        ['due_on' => '2026-01-31', 'payment' => '100'], ['due_on' => '2026-02-28', 'payment' => '100'], ['due_on' => '2026-03-31', 'payment' => '100'],
    ]];
    $commencement = $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'lease-start', '2026-01-01', [...$inputs, 'operation' => 'lease.commence']));
    foreach (['2026-01-31' => '24.86', '2026-02-28' => '17.36', '2026-03-31' => '9.09'] as $date => $interest) {
        $period = $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'lease-period-'.$date, $date, [...$inputs,
            'operation' => 'lease.period', 'interest_paid' => $interest, 'interest_cash_flow' => 'operating']));
    }
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-03-31');
    $liability = $this->ledger->configurations->account($this->book, $configuration, 'lease.liability');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$liability]->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('51.31');
    $flow = $this->ledger->reports->cashFlow($snapshot, '2026-01-01');
    expect($flow->financing->decimal())->toBe('-248.69')->and($flow->operating->decimal())->toBe('-51.31');
    $depreciation = $this->ledger->schedules->create($this->book, 'lease-depreciation', 'Right-of-use depreciation',
        $this->ledger->configurations->account($this->book, $configuration, 'lease.depreciation'),
        $this->ledger->configurations->account($this->book, $configuration, 'lease.accumulated'),
        Schedule::equal(Money::of('248.69', $this->kes), ['2026-01-31', '2026-02-28', '2026-03-31']), 'lease-depreciation:1');
    expect($this->ledger->schedules->postDue($this->book, $depreciation, '2026-03-31'))->toHaveCount(3)
        ->and($this->ledger->schedules->postDue($this->book, $depreciation, '2026-03-31'))->toBe([])
        ->and($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-03-31'), '2026-01-01')['expenses']->decimal())->toBe('300.00');
    $this->ledger->journals->reverse($this->book, $period->id, 'lease-last-correction', '2026-04-01', 'Correct final payment');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-04-01')));
    expect($balances[$liability]->closing->decimal())->toBe('-90.91');
    $this->storage->run(Permission::Read, function () use ($commencement): void {
        $metadata = json_decode(text($this->storage->find('ledger_journals', $commencement->id, $this->book)->metadata), true, 512, JSON_THROW_ON_ERROR);
        expect(data_get($metadata, 'finance.liability.amount'))->toBe('248.69')
            ->and(data_get($metadata, 'finance.rows.0.rounding_adjustment.amount'))->toBe('-0.01');
    }, $this->book);
});

it('measures and releases ECL against the actual allowance balance without settling debt', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $inputs = ['operation' => 'ecl.measure', 'rate_per_slot' => '0.1', 'contractual' => [['period' => 1, 'amount' => '1100']],
        'scenarios' => [['weight' => '0.7', 'receipts' => ['1100']], ['weight' => '0.3', 'receipts' => ['550']]]];
    $first = $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'ecl-first', '2026-01-31', $inputs));
    $inputs['scenarios'] = [['weight' => '0.9', 'receipts' => ['1100']], ['weight' => '0.1', 'receipts' => ['550']]];
    $second = $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'ecl-improves', '2026-02-28', $inputs));
    $allowance = $this->ledger->configurations->account($this->book, $configuration, 'ecl.allowance');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')));
    expect($balances[$allowance]->closing->decimal())->toBe('-50.00');
    $this->storage->run(Permission::Read, function () use ($second): void {
        $metadata = json_decode(text($this->storage->find('ledger_journals', $second->id, $this->book)->metadata), true, 512, JSON_THROW_ON_ERROR);
        expect(data_get($metadata, 'finance.previous_allowance.amount'))->toBe('150.00')
            ->and(data_get($metadata, 'finance.change.amount'))->toBe('-100.00');
    }, $this->book);
    $this->ledger->journals->reverse($this->book, $second->id, 'ecl-restore', '2026-03-01', 'Correct recoverability evidence');
    $this->ledger->journals->reverse($this->book, $first->id, 'ecl-clear', '2026-03-01', 'Correct first assessment');
    expect($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-03-01'), '2026-01-01')['expenses']->decimal())->toBe('0.00');
});

it('recognizes only the allocated consideration for satisfied performance obligations', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->configurations->account($this->book, $configuration, 'contract.liability');
    $this->ledger->journals->post(new JournalRequest($this->book, 'contract-advance', '2026-01-01', 'Contract advance', [
        new Posting($this->cash, Side::Debit, Money::of('100', $this->kes)), new Posting($contract, Side::Credit, Money::of('100', $this->kes)),
    ]));
    $inputs = ['operation' => 'revenue.recognize', 'contract_reference' => 'contract-42', 'transaction_price' => '100', 'obligations' => [
        ['reference' => 'equipment', 'standalone_price' => '80'], ['reference' => 'service', 'standalone_price' => '40'],
    ], 'satisfied' => ['equipment']];
    $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'equipment-delivered', '2026-01-31', $inputs));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    expect($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('66.67');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$contract]->closing->decimal())->toBe('-33.33');
    try {
        $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'equipment-duplicate', '2026-02-01', $inputs));
        $this->fail('A new transport identity must not recognize the same obligation twice.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('already recognized');
    }
    $inputs['satisfied'] = ['service'];
    $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'service-complete', '2026-02-28', $inputs));
    expect($this->ledger->reports->incomeStatement($this->ledger->reports->snapshot($this->book, '2026-02-28'), '2026-01-01')['income']->decimal())->toBe('100.00');
});

it('rolls back finance treatments with an incompatible carrying value or invalid ECL model', function (string $case) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $before = $this->ledger->events->cutoff($this->book);
    $inputs = $case === 'carrying' ? ['operation' => 'loan.period', 'position' => 'asset', 'opening' => '950', 'payment' => '100', 'rate_per_slot' => '0.1', 'interest_cash_flow' => 'operating']
        : ['operation' => 'ecl.measure', 'rate_per_slot' => '0', 'contractual' => [['period' => 1, 'amount' => '100']], 'scenarios' => [['weight' => '0.9', 'receipts' => ['0']]]];
    try {
        $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'invalid-finance', '2026-01-31', $inputs));
        $this->fail('Invalid finance treatment must fail.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain($case === 'carrying' ? 'carrying-account' : 'sum to exactly one');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before)
        ->and($this->ledger->reports->generalLedger($this->ledger->reports->snapshot($this->book, '2026-12-31')))->toBe([]);
})->with(['carrying', 'probabilities']);

it('keeps ECL portfolios separate and rejects backdating through their actual carrying history', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $this->ledger->catalog->dimensionValue($this->entity, 'portfolio', 'school');
    $this->ledger->catalog->dimensionValue($this->entity, 'portfolio', 'hospital');
    $inputs = ['operation' => 'ecl.measure', 'rate_per_slot' => '0', 'contractual' => [['period' => 1, 'amount' => '100']],
        'scenarios' => [['weight' => '1', 'receipts' => ['0']]], 'dimensions' => ['portfolio' => 'school']];
    $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'school-loss', '2026-02-28', $inputs));
    $inputs['dimensions'] = ['portfolio' => 'hospital'];
    $inputs['contractual'][0]['amount'] = '200';
    $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'hospital-loss', '2026-01-31', $inputs));
    $allowance = $this->ledger->configurations->account($this->book, $configuration, 'ecl.allowance');
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')));
    expect($balances[$allowance]->closing->decimal())->toBe('-300.00');
    $inputs['dimensions'] = ['portfolio' => 'school'];
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'backdated-school', '2026-01-31', $inputs));
        $this->fail('A valuation cannot precede its bucket history.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('cannot precede');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

/** @return array<string, mixed> */
function registeredLoanInputs(): array
{
    return ['opening' => '950', 'rate_per_slot' => '0.1', 'interest_cash_flow' => 'operating',
        'policy_version' => 'loan-policy:1', 'basis_reference' => 'signed-loan:42', 'payments' => [
            ['due_on' => '2026-06-30', 'payment' => '100', 'interest_paid' => '95'],
            ['due_on' => '2026-12-31', 'payment' => '1039.50', 'interest_paid' => '94.50'],
        ]];
}

it('registers protected lending and borrowing with exact period state and snapshot continuity', function (string $kind) {
    $configuration = financeConfiguration($this->ledger, $this->book, $kind === 'loan_asset' ? 'asset' : 'liability');
    $inputs = registeredLoanInputs();
    $contract = $this->ledger->finance->open($this->book, 'registered-loan', 'LOAN-42', '2026-01-01', $kind, $configuration, $inputs);
    expect($this->ledger->finance->open($this->book, 'registered-loan', 'LOAN-42', '2026-01-01', $kind, $configuration, $inputs))->toBe($contract);
    $opening = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $first = $this->ledger->finance->period($this->book, $contract, 'period-1', '2026-06-30', 1, 'Recognize first period');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract)['carrying']->decimal())->toBe('945.00');
    $second = $this->ledger->finance->period($this->book, $contract, 'period-2', '2026-12-31', 2, 'Recognize final period');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->finance->position($opening, $contract)['carrying']->decimal())->toBe('950.00')
        ->and($this->ledger->finance->evidence($snapshot, $contract)['events'])->toHaveCount(2)
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
    $this->ledger->finance->reverse($this->book, $second, 'undo-last', '2026-12-31', 'Correct final period');
    $this->ledger->finance->reverse($this->book, $first, 'undo-first', '2026-12-31', 'Correct first period');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying']->decimal())->toBe('950.00');
    $this->ledger->periods->close($this->book, $this->period, 'Close contract year');
    expect($this->ledger->finance->period($this->book, $contract, 'period-1', '2026-06-30', 1, 'Recognize first period'))->toBe($first);
})->with(['loan_asset', 'loan_liability']);

it('protects measured lease liability and preserves the present-value rounding through correction', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $inputs = ['rate_per_slot' => '0.1', 'commencement_payment' => '100', 'interest_cash_flow' => 'operating', 'policy_version' => 'lease-policy:1',
        'basis_reference' => 'signed-lease:42', 'payments' => [
            ['due_on' => '2026-01-31', 'payment' => '100', 'interest_paid' => '17.36'],
            ['due_on' => '2026-02-28', 'payment' => '100', 'interest_paid' => '9.09'],
        ]];
    $contract = $this->ledger->finance->open($this->book, 'lease', 'LEASE-42', '2026-01-01', 'lease', $configuration, $inputs);
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-01'), $contract)['carrying']->decimal())->toBe('173.55');
    $this->ledger->finance->period($this->book, $contract, 'lease-1', '2026-01-31', 1, 'Pay January lease');
    $last = $this->ledger->finance->period($this->book, $contract, 'lease-2', '2026-02-28', 2, 'Pay February lease');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('26.45');
    $this->ledger->finance->reverse($this->book, $last, 'lease-reverse', '2026-03-01', 'Correct lease payment');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-03-01'), $contract)['carrying']->decimal())->toBe('90.91');
});

it('stores ECL stages and no-change evidence while recognizing credit-impaired interest on the net asset', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $inputs = [...registeredLoanInputs(), 'opening' => '100', 'payments' => [
        ['due_on' => '2026-06-30', 'payment' => '0', 'interest_paid' => '0'],
        ['due_on' => '2026-12-31', 'payment' => '121', 'interest_paid' => '21'],
    ]];
    $contract = $this->ledger->finance->open($this->book, 'credit-impaired', 'LOAN-ECL', '2026-01-01', 'loan_asset', $configuration, $inputs);
    $model = ['stage' => 3, 'model_reference' => 'approved-default-assessment:1', 'contractual' => [['period' => 0, 'amount' => '100']],
        'scenarios' => [['weight' => '1', 'receipts' => ['60']]]];
    $this->ledger->finance->measureLoss($this->book, $contract, 'default', '2026-01-31', $model, 'Recognize default loss');
    $zero = $this->ledger->finance->measureLoss($this->book, $contract, 'default-confirmed', '2026-02-28', $model, 'Confirm unchanged assessment');
    $this->storage->run(Permission::Read, function () use ($zero): void {
        expect($this->storage->find('ledger_finance_events', $zero, $this->book)->journal_id)->toBeNull();
    }, $this->book);
    $this->ledger->finance->period($this->book, $contract, 'impaired-interest', '2026-06-30', 1, 'Accrete net interest');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $position = $this->ledger->finance->position($snapshot, $contract);
    expect($position['carrying']->decimal())->toBe('110.00')->and($position['allowance']->decimal())->toBe('44.00')
        ->and($position['net']->decimal())->toBe('66.00')->and($position['stage'])->toBe(3)
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('6.00');
    $writeoff = $this->ledger->finance->writeOff($this->book, $contract, 'writeoff', '2026-07-01', 'No reasonable expectation of recovery', ['approval' => 'writeoff-42']);
    $recovery = $this->ledger->finance->recover($this->book, $contract, 'recovery', '2026-08-01', '20', 'Unexpected recovery');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-08-01'), $contract);
    expect($position['carrying']->decimal())->toBe('0.00')->and($position['allowance']->decimal())->toBe('0.00')
        ->and($position['written_off']->decimal())->toBe('110.00')->and($position['recovered']->decimal())->toBe('20.00');
    $this->ledger->finance->reverse($this->book, $recovery, 'undo-recovery', '2026-09-01', 'Correct recovery');
    $this->ledger->finance->reverse($this->book, $writeoff, 'undo-writeoff', '2026-09-01', 'Correct writeoff');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-09-01'), $contract)['net']->decimal())->toBe('66.00');
});

it('releases remaining ECL when the contractual balance is fully settled', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'settle-ecl', 'LOAN-SETTLE', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->measureLoss($this->book, $contract, 'provision', '2026-01-31', ['stage' => 2, 'model_reference' => 'lifetime-model:1',
        'contractual' => [['period' => 0, 'amount' => '950']], 'scenarios' => [['weight' => '1', 'receipts' => ['800']]]], 'Lifetime credit deterioration');
    $this->ledger->finance->period($this->book, $contract, 'first-ecl-payment', '2026-06-30', 1, 'First payment');
    $this->ledger->finance->period($this->book, $contract, 'last-ecl-payment', '2026-12-31', 2, 'Full settlement');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['allowance']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['expenses']->decimal())->toBe('0.00');
});

it('tracks cumulative contract progress without duplicate revenue or lost allocation cents', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'contract', 'CONTRACT-42', '2026-01-01', 'revenue', $configuration,
        ['transaction_price' => '100', 'policy_version' => 'revenue-policy:1', 'basis_reference' => 'approved-contract:42', 'obligations' => [
            ['reference' => 'equipment', 'standalone_price' => '80'], ['reference' => 'service', 'standalone_price' => '40'],
        ]]);
    $equipment = $this->ledger->finance->recognize($this->book, $contract, 'equipment', '2026-01-31', 'equipment', '1', 'Equipment delivered');
    $half = $this->ledger->finance->recognize($this->book, $contract, 'service-half', '2026-02-28', 'service', '0.5', 'Reviewed halfway progress');
    $noChange = $this->ledger->finance->recognize($this->book, $contract, 'service-half-confirmed', '2026-02-28', 'service', '0.5', 'Confirm progress');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-02-28');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('16.67')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('83.33');
    $final = $this->ledger->finance->recognize($this->book, $contract, 'service-full', '2026-03-31', 'service', '1', 'Service complete');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract)['carrying']->decimal())->toBe('0.00');
    foreach ([$final, $noChange, $half, $equipment] as $index => $event) {
        $this->ledger->finance->reverse($this->book, $event, 'contract-correct-'.$index, '2026-04-01', 'Correct contract evidence');
    }
    $this->ledger->finance->cancel($this->book, $contract, 'cancel-contract', '2026-04-01', 'Cancel original advance');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-04-01');
    expect($this->ledger->finance->position($snapshot, $contract)['cancelled'])->toBeTrue()
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('0.00');
});

it('rejects skipped and duplicate registered payment slots with independent database validation', function (int $line) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'guard-loan', 'GUARD-LOAN', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    if ($line === 1) {
        $this->ledger->finance->period($this->book, $contract, 'first', '2026-06-30', 1, 'First period');
    }
    $this->ledger->finance->period($this->book, $contract, 'invalid', '2026-12-31', $line, 'Invalid order');
})->with([1, 2])->throws(QueryException::class, 'cannot be skipped, duplicated');

it('rejects out-of-order finance corrections atomically', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'correct-order', 'CORRECT-ORDER', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $first = $this->ledger->finance->period($this->book, $contract, 'first-order', '2026-06-30', 1, 'First period');
    $this->ledger->finance->period($this->book, $contract, 'second-order', '2026-12-31', 2, 'Second period');
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->finance->reverse($this->book, $first, 'wrong-order', '2026-12-31', 'Incorrect dependency order');
        $this->fail('Dependent finance periods must be corrected first.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('dependency order');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
});

it('keeps registered finance separate from inventory regardless of registration order', function (bool $financeFirst) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $account = $this->ledger->configurations->account($this->book, $configuration, 'loan');
    if ($financeFirst) {
        $this->ledger->finance->open($this->book, 'loan-first', 'LOAN-FIRST', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
        $this->ledger->inventory->pool($this->book, 'STORE', 'SKU', $account, ValuationMethod::Fifo);
    } else {
        $this->ledger->inventory->pool($this->book, 'STORE', 'SKU', $account, ValuationMethod::Fifo);
        $this->ledger->finance->open($this->book, 'stock-first', 'STOCK-FIRST', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    }
})->with([false, true])->throws(QueryException::class, 'register');

it('allows exact finance corrections after retirement while blocking even zero-change new assessments', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'retired-finance', 'RETIRED-FINANCE', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $period = $this->ledger->finance->period($this->book, $contract, 'retired-first', '2026-06-30', 1, 'First installment');
    $loan = $this->ledger->configurations->account($this->book, $configuration, 'loan');
    $this->ledger->catalog->deleteAccount($this->book, $loan);
    try {
        $this->ledger->finance->measureLoss($this->book, $contract, 'retired-noop', '2026-07-31', ['stage' => 1, 'model_reference' => 'unchanged-zero-loss',
            'contractual' => [['period' => 0, 'amount' => '945']], 'scenarios' => [['weight' => '1', 'receipts' => ['945']]]], 'Zero-change model');
        $this->fail('Zero financial effect must not bypass retired references.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('Restore finance accounts');
    }
    $this->ledger->finance->reverse($this->book, $period, 'retired-correction', '2026-07-31', 'Correct historical installment');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-07-31'), $contract)['carrying']->decimal())->toBe('950.00');
});

it('requires finance permission independently of ordinary posting access', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $denied = (new Ledger($this->storage->connection, new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Finance;
        }
    }))->for($this->scope);
    $denied->finance->open($this->book, 'denied-finance', 'DENIED-FINANCE', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
})->throws(LedgerException::class, 'ledger.finance');

it('agrees across PostgreSQL and rational lease measurement beyond floating-point precision', function (string $rate) {
    $values = [
        ['due_on' => '2026-01-31', 'payment' => '9007199254740993.01'],
        ['due_on' => '2026-02-28', 'payment' => '9007199254740993.02'],
        ['due_on' => '2026-03-31', 'payment' => '9007199254740993.03'],
    ];
    $php = (new FinancialCalculator)->lease($rate, array_map(fn (array $row): array => ['due_on' => $row['due_on'], 'payment' => Money::of($row['payment'], $this->kes)], $values));
    $sql = row($this->storage->connection->selectOne('SELECT ledger_finance_pv(?::jsonb, ?::numeric, 2)::text AS amount', [json_encode($values, JSON_THROW_ON_ERROR), $rate]));
    expect($sql->amount)->toBe((string) $php['liability']->minor);
})->with(['0', '0.1', '0.123456789012345678']);

it('shares chart accounts across isolated instrument buckets without sharing period or allowance state', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $this->ledger->catalog->dimensionValue($this->entity, 'instrument', 'loan-a');
    $this->ledger->catalog->dimensionValue($this->entity, 'instrument', 'loan-b');
    $loan = $this->ledger->configurations->account($this->book, $configuration, 'loan');
    $this->ledger->catalog->requireDimension($this->book, $loan, 'instrument');
    $first = $this->ledger->finance->open($this->book, 'bucket-a', 'LOAN-A', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs(), ['instrument' => 'loan-a']);
    $second = $this->ledger->finance->open($this->book, 'bucket-b', 'LOAN-B', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs(), ['instrument' => 'loan-b']);
    $this->ledger->finance->period($this->book, $first, 'bucket-a-period', '2026-06-30', 1, 'First instrument installment');
    $this->ledger->finance->measureLoss($this->book, $second, 'bucket-b-ecl', '2026-06-30', ['stage' => 2, 'model_reference' => 'instrument-b-model',
        'contractual' => [['period' => 0, 'amount' => '950']], 'scenarios' => [['weight' => '1', 'receipts' => ['900']]]], 'Instrument B deterioration');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect($this->ledger->finance->position($snapshot, $first)['carrying']->decimal())->toBe('945.00')
        ->and($this->ledger->finance->position($snapshot, $first)['allowance']->decimal())->toBe('0.00')
        ->and($this->ledger->finance->position($snapshot, $second)['carrying']->decimal())->toBe('950.00')
        ->and($this->ledger->finance->position($snapshot, $second)['allowance']->decimal())->toBe('50.00');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($balances[$loan]->closing->decimal())->toBe('1895.00');
});

/** @param non-empty-list<array{due_on: string, payment: string, interest_paid: string}> $payments
 * @param  list<string>|null  $receipts
 * @return array<string, mixed>
 */
function modifiedLoanModel(array $payments, int $stage = 1, ?array $receipts = null): array
{
    $contractual = [];
    foreach ($payments as $index => $payment) {
        $contractual[] = ['period' => $index + 1, 'amount' => $payment['payment']];
    }

    return ['stage' => $stage, 'model_reference' => 'reviewed-modified-credit-model:1', 'contractual' => $contractual,
        'scenarios' => [['weight' => '1', 'receipts' => $receipts ?? array_column($payments, 'payment')]]];
}

it('remeasures lender and borrower modifications at the original rate and preserves prior snapshots', function (string $kind) {
    $asset = $kind === 'loan_asset';
    $configuration = financeConfiguration($this->ledger, $this->book, $asset ? 'asset' : 'liability');
    $contract = $this->ledger->finance->open($this->book, 'modify-loan', 'MODIFY-LOAN', '2026-01-01', $kind, $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'modify-original-period', '2026-06-30', 1, 'Original first installment');
    $before = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    $payments = [['due_on' => '2026-12-31', 'payment' => '990', 'interest_paid' => '90']];
    $gain = $this->ledger->configurations->account($this->book, $configuration, 'loan.gain');
    $loss = $this->ledger->configurations->account($this->book, $configuration, 'loan.loss');
    $change = $this->ledger->finance->modify($this->book, $contract, 'concession', '2026-06-30', $payments, $gain, $loss,
        'Reviewed non-derecognition without upfront fees', 'Revise the remaining contractual payment', $asset ? modifiedLoanModel($payments) : null);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('900.00')
        ->and($this->ledger->finance->position($before, $contract)['carrying']->decimal())->toBe('945.00')
        ->and($this->ledger->finance->plan($snapshot, $contract)['plan_id'])->toBe($change)
        ->and($this->ledger->finance->plan($before, $contract)['plan_id'])->toBe($contract);
    $this->ledger->finance->period($this->book, $contract, 'modified-final-period', '2026-12-31', 1, 'Pay modified final installment', planId: $change);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe($asset ? '140.00' : '-140.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
})->with(['loan_asset', 'loan_liability']);

it('records missed loan cash without inventing receipts and remeasures the revised ECL separately', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'arrears-loan', 'ARREARS-LOAN', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->measureLoss($this->book, $contract, 'arrears-initial-ecl', '2026-01-01', ['stage' => 3, 'model_reference' => 'reviewed-default',
        'contractual' => [['period' => 0, 'amount' => '950']], 'scenarios' => [['weight' => '1', 'receipts' => ['760']]]], 'Initial impairment assessment');
    $this->ledger->finance->period($this->book, $contract, 'missed-installment', '2026-06-30', 1, 'Recognize unpaid contractual slot', paidAmount: '0', interestPaid: '0');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('1045.00')
        ->and($this->ledger->finance->position($snapshot, $contract)['allowance']->decimal())->toBe('209.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['income']->decimal())->toBe('76.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->closing->decimal())->toBe('-950.00');
    $payments = [['due_on' => '2026-12-31', 'payment' => '990', 'interest_paid' => '90']];
    $gain = $this->ledger->configurations->account($this->book, $configuration, 'loan.gain');
    $loss = $this->ledger->configurations->account($this->book, $configuration, 'loan.loss');
    $change = $this->ledger->finance->modify($this->book, $contract, 'arrears-restructure', '2026-06-30', $payments, $gain, $loss,
        'Reviewed credit-impaired non-derecognition', 'Restructure unpaid cash flows', modifiedLoanModel($payments, 3, ['660']));
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $position = $this->ledger->finance->position($snapshot, $contract);
    expect($position['carrying']->decimal())->toBe('900.00')->and($position['allowance']->decimal())->toBe('300.00')
        ->and($position['net']->decimal())->toBe('600.00')->and($position['stage'])->toBe(3)
        ->and($this->ledger->finance->plan($snapshot, $contract)['requires_actual_payment'])->toBeFalse();
    $this->storage->run(Permission::Read, function () use ($change): void {
        $event = $this->storage->find('ledger_finance_events', $change, $this->book);
        $metadata = json_decode(text($this->storage->find('ledger_journals', text($event->journal_id), $this->book)->metadata), true, 512, JSON_THROW_ON_ERROR);
        expect(data_get($metadata, 'finance_calculation.modification_effect_minor'))->toBe('-14500')
            ->and(data_get($metadata, 'finance_calculation.allowance_before_minor'))->toBe('20900')
            ->and(data_get($metadata, 'finance_calculation.allowance_after_minor'))->toBe('30000');
    }, $this->book);
});

it('extends a monthly loan without collapsing original discount slots or losing rounding cents', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $inputs = [...registeredLoanInputs(), 'rate_period_months' => 1, 'payments' => [
        ['due_on' => '2026-01-31', 'payment' => '100', 'interest_paid' => '95'],
        ['due_on' => '2026-02-28', 'payment' => '1039.50', 'interest_paid' => '94.50'],
    ]];
    $contract = $this->ledger->finance->open($this->book, 'monthly-modification', 'MONTHLY-MODIFICATION', '2026-01-01', 'loan_asset', $configuration, $inputs);
    $this->ledger->finance->period($this->book, $contract, 'monthly-original-period', '2026-01-31', 1, 'First monthly installment');
    $payments = [
        ['due_on' => '2026-02-28', 'payment' => '100', 'interest_paid' => '24.86'],
        ['due_on' => '2026-03-31', 'payment' => '100', 'interest_paid' => '17.36'],
        ['due_on' => '2026-04-30', 'payment' => '100', 'interest_paid' => '9.09'],
    ];
    $change = $this->ledger->finance->modify($this->book, $contract, 'monthly-concession', '2026-01-31', $payments,
        $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
        'Reviewed fixed-rate monthly concession without derecognition', 'Extend remaining installments', modifiedLoanModel($payments));
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-01-31'), $contract)['carrying']->decimal())->toBe('248.69');
    foreach ($payments as $index => $payment) {
        $this->ledger->finance->period($this->book, $contract, 'extended-period-'.$index, $payment['due_on'], $index + 1, 'Pay revised installment', planId: $change);
    }
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-04-30');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->finance->plan($snapshot, $contract)['next_line'])->toBe(4);
    $this->storage->run(Permission::Read, function (): void {
        $event = row($this->storage->table('ledger_finance_events')->where('book_id', $this->book)->where('idempotency_key', 'extended-period-0')->first());
        $metadata = json_decode(text($this->storage->find('ledger_journals', text($event->journal_id), $this->book)->metadata), true, 512, JSON_THROW_ON_ERROR);
        expect(data_get($metadata, 'finance_calculation.rounding_adjustment_minor'))->toBe('-1');
    }, $this->book);
});

it('settles lenders and borrowers early with explicit cash classifications and exact reopening', function (string $kind, string $cash, string $profit) {
    $asset = $kind === 'loan_asset';
    $configuration = financeConfiguration($this->ledger, $this->book, $asset ? 'asset' : 'liability');
    $contract = $this->ledger->finance->open($this->book, 'early-settlement', 'EARLY-SETTLEMENT', '2026-01-01', $kind, $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'before-early-settlement', '2026-06-30', 1, 'Pay scheduled installment');
    if ($asset) {
        $this->ledger->finance->measureLoss($this->book, $contract, 'before-settlement-ecl', '2026-06-30', ['stage' => 2, 'model_reference' => 'reviewed-lifetime-loss',
            'contractual' => [['period' => 0, 'amount' => '945']], 'scenarios' => [['weight' => '1', 'receipts' => ['895']]]], 'Record allowance before payoff');
    }
    $before = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $event = $this->ledger->finance->settle($this->book, $contract, 'pay-off', '2026-06-30', $cash, $cash === '1000' ? '55' : '0',
        $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
        'Reviewed complete contractual discharge at carrying date', 'Early payoff');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $position = $this->ledger->finance->position($snapshot, $contract);
    expect($position['settled'])->toBeTrue()->and($position['carrying']->decimal())->toBe('0.00')->and($position['allowance']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe($profit)
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue()
        ->and($this->ledger->finance->position($before, $contract)['settled'])->toBeFalse();
    $this->ledger->finance->reverse($this->book, $event, 'undo-pay-off', '2026-06-30', 'Correct payoff evidence');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract);
    expect($position['settled'])->toBeFalse()->and($position['carrying']->decimal())->toBe('945.00')
        ->and($position['allowance']->decimal())->toBe($asset ? '50.00' : '0.00');
})->with([['loan_asset', '900', '50.00'], ['loan_asset', '1000', '150.00'], ['loan_liability', '900', '-50.00'], ['loan_liability', '1000', '-150.00']]);

it('restores previous payment-plan versions only after dependent modified periods are corrected', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'plan-corrections', 'PLAN-CORRECTIONS', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'plan-corrections-first', '2026-06-30', 1, 'First period');
    $payments = [['due_on' => '2026-12-31', 'payment' => '990', 'interest_paid' => '90']];
    $change = $this->ledger->finance->modify($this->book, $contract, 'plan-corrections-change', '2026-06-30', $payments,
        $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
        'Reviewed revised payments', 'Modify remaining term', modifiedLoanModel($payments));
    try {
        $this->ledger->finance->period($this->book, $contract, 'stale-plan-period', '2026-12-31', 1, 'Attempt original plan token', planId: $contract);
        $this->fail('The original plan cannot consume a modified period.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('current payment plan');
    }
    $period = $this->ledger->finance->period($this->book, $contract, 'correct-modified-period', '2026-12-31', 1, 'Pay revised period', planId: $change);
    try {
        $this->ledger->finance->reverse($this->book, $change, 'premature-modification-reversal', '2026-12-31', 'Attempt premature plan restoration');
        $this->fail('Later financial activity must be corrected first.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('dependency order');
    }
    $this->ledger->finance->reverse($this->book, $period, 'reverse-modified-period', '2026-12-31', 'Correct revised payment');
    $this->ledger->finance->reverse($this->book, $change, 'restore-original-plan', '2026-12-31', 'Restore original contract');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->plan($snapshot, $contract)['plan_id'])->toBe($contract)
        ->and($this->ledger->finance->plan($snapshot, $contract)['next_line'])->toBe(2)
        ->and($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('945.00');
});

it('requires explicit actual receipts after arrears instead of assuming the next installment arrived', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'actual-cash', 'ACTUAL-CASH', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'unpaid-first-slot', '2026-06-30', 1, 'Missed first installment', paidAmount: '0', interestPaid: '0');
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->finance->period($this->book, $contract, 'do-not-assume-second-cash', '2026-12-31', 2, 'Unspecified actual cash');
        $this->fail('An unpaid schedule cannot silently assume later cash arrived.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('explicit actual cash');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
    $this->ledger->finance->period($this->book, $contract, 'actual-second-cash', '2026-12-31', 2, 'Record actual partial collection', paidAmount: '50', interestPaid: '50');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('1099.50')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->closing->decimal())->toBe('-900.00')
        ->and($this->ledger->finance->plan($snapshot, $contract)['requires_actual_payment'])->toBeTrue();
});

it('rejects unsupported modification bases and inconsistent revised ECL models atomically', function (string $case, string $message) {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'invalid-change', 'INVALID-CHANGE', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'invalid-change-first', '2026-06-30', 1, 'First installment');
    $payments = [['due_on' => $case === 'dates' ? '2026-11-30' : '2026-12-31', 'payment' => '990', 'interest_paid' => '90']];
    $model = $case === 'missing' ? null : modifiedLoanModel($payments);
    if ($model !== null && $case === 'model') {
        $model['contractual'] = [['period' => 1, 'amount' => '991']];
    }
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->finance->modify($this->book, $contract, 'invalid-change-attempt', $case === 'basis' ? '2026-07-01' : '2026-06-30', $payments,
            $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
            'Reviewed proposed change', 'Attempt unsupported modification', $model);
        $this->fail('Unsupported loan changes must fail atomically.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($message);
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before);
})->with([['missing', 'explicit revised ECL model'], ['model', 'must match the revised payments'], ['dates', 'preserve the original rate-slot dates'], ['basis', 'recognized carrying date']]);

it('uses unrounded discounted amounts for the liability ten-percent threshold', function (string $payment, bool $accepted) {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $inputs = [...registeredLoanInputs(), 'opening' => '1000.50', 'payments' => [['due_on' => '2026-06-30', 'payment' => '1100.55', 'interest_paid' => '100.05']]];
    $contract = $this->ledger->finance->open($this->book, 'threshold-loan', 'THRESHOLD-LOAN', '2026-01-01', 'loan_liability', $configuration, $inputs);
    $payments = [['due_on' => '2026-06-30', 'payment' => $payment, 'interest_paid' => '90.05']];
    try {
        $change = $this->ledger->finance->modify($this->book, $contract, 'threshold-modification', '2026-01-01', $payments,
            $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
            'Reviewed fixed-rate terms without fees', 'Test exact threshold');
        expect($accepted)->toBeTrue();
        $this->ledger->finance->period($this->book, $contract, 'threshold-payment', '2026-06-30', 1, 'Pay revised amount', planId: $change);
        expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract)['carrying']->decimal())->toBe('0.00');
    } catch (QueryException $exception) {
        expect($accepted)->toBeFalse()->and($exception->getMessage())->toContain('Substantial liability changes require derecognition');
    }
})->with([['990.50', true], ['990.49', false]]);

it('retains zero-effect plan changes and forbids activity after explicit legal settlement', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'zero-change', 'ZERO-CHANGE', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'zero-change-first', '2026-06-30', 1, 'First installment');
    $payments = [['due_on' => '2026-12-31', 'payment' => '1039.50', 'interest_paid' => '94.50']];
    $gain = $this->ledger->configurations->account($this->book, $configuration, 'loan.gain');
    $loss = $this->ledger->configurations->account($this->book, $configuration, 'loan.loss');
    $change = $this->ledger->finance->modify($this->book, $contract, 'same-value-plan', '2026-06-30', $payments, $gain, $loss, 'Reviewed administrative plan renewal', 'No monetary change', modifiedLoanModel($payments));
    $this->storage->run(Permission::Read, function () use ($change): void {
        expect($this->storage->find('ledger_finance_events', $change, $this->book)->journal_id)->toBeNull();
    }, $this->book);
    $this->ledger->finance->settle($this->book, $contract, 'forgive-loan', '2026-06-30', '0', '0', $gain, $loss, 'Reviewed full legal discharge', 'Forgive the remaining loan');
    $position = $this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-06-30'), $contract);
    expect($position['settled'])->toBeTrue()->and($position['written_off']->decimal())->toBe('0.00');
    $this->ledger->finance->period($this->book, $contract, 'do-not-resurrect', '2026-12-31', 1, 'Attempt to resume legally discharged loan', planId: $change);
})->throws(QueryException::class, 'Settled finance contracts require correction');

it('records partial prepayments with a re-planned balance at the original rate and a fee as gain or loss', function (string $kind) {
    $asset = $kind === 'loan_asset';
    $configuration = financeConfiguration($this->ledger, $this->book, $asset ? 'asset' : 'liability');
    $contract = $this->ledger->finance->open($this->book, 'prepay-loan', 'PREPAY-LOAN', '2026-01-01', $kind, $configuration, registeredLoanInputs());
    $this->ledger->finance->period($this->book, $contract, 'prepay-first', '2026-06-30', 1, 'First installment');
    $gain = $this->ledger->configurations->account($this->book, $configuration, 'loan.gain');
    $loss = $this->ledger->configurations->account($this->book, $configuration, 'loan.loss');
    $payments = [['due_on' => '2026-12-31', 'payment' => '599.50', 'interest_paid' => '54.50']];
    $change = $this->ledger->finance->prepay($this->book, $contract, 'prepay', '2026-06-30', '410', '0', $payments, $gain, $loss,
        'Contractual prepayment with a 10 fee', 'Borrower prepaid principal', $asset ? modifiedLoanModel($payments) : null);
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('545.00')
        ->and($balances[$asset ? $gain : $loss]->closing->decimal())->toBe($asset ? '-10.00' : '10.00')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();

    $this->ledger->finance->period($this->book, $contract, 'prepay-final', '2026-12-31', 1, 'Final installment', planId: $change);
    $year = $this->ledger->reports->snapshot($this->book, '2026-12-31');
    expect($this->ledger->finance->position($year, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($year, '2026-01-01')['profit']->decimal())->toBe($asset ? '159.50' : '-159.50');
})->with(['loan_asset', 'loan_liability']);

it('partially writes off a loan asset against its allowance and then requires actual cash', function () {
    $configuration = financeConfiguration($this->ledger, $this->book);
    $contract = $this->ledger->finance->open($this->book, 'partial-loan', 'PARTIAL-LOAN', '2026-01-01', 'loan_asset', $configuration, registeredLoanInputs());
    $this->ledger->finance->measureLoss($this->book, $contract, 'ecl', '2026-01-01', ['stage' => 2, 'model_reference' => 'reviewed-credit-model:1',
        'contractual' => [['period' => 1, 'amount' => '1045']], 'scenarios' => [['weight' => '1', 'receipts' => ['990']]]], 'Significant increase in credit risk');
    $this->ledger->finance->period($this->book, $contract, 'partial-first', '2026-06-30', 1, 'First installment');
    $this->ledger->finance->partialWriteOff($this->book, $contract, 'partial-writeoff', '2026-06-30', '300', 'No reasonable expectation of recovering part of the balance');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-06-30');
    $position = $this->ledger->finance->position($snapshot, $contract);
    expect($position['carrying']->decimal())->toBe('645.00')
        ->and($position['allowance']->decimal())->toBe('0.00')
        ->and($position['written_off']->decimal())->toBe('300.00')
        ->and(fn () => $this->ledger->finance->period($this->book, $contract, 'partial-scheduled', '2026-12-31', 2, 'Scheduled'))->toThrow(QueryException::class, 'actual cash')
        ->and(fn () => $this->ledger->finance->partialWriteOff($this->book, $contract, 'too-much', '2026-06-30', '645', 'Whole balance'))->toThrow(QueryException::class, 'writeOff()');

    $this->ledger->finance->period($this->book, $contract, 'partial-final', '2026-12-31', 2, 'Final cash', paidAmount: '709.50', interestPaid: '64.50');
    expect($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-12-31'), $contract)['carrying']->decimal())->toBe('0.00');
});

it('remeasures a lease liability against the right-of-use asset and takes excess reductions to profit or loss', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $inputs = ['rate_per_slot' => '0.1', 'commencement_payment' => '100', 'interest_cash_flow' => 'operating', 'policy_version' => 'lease-policy:1',
        'basis_reference' => 'signed-lease:42', 'payments' => [
            ['due_on' => '2026-01-31', 'payment' => '100', 'interest_paid' => '17.36'],
            ['due_on' => '2026-02-28', 'payment' => '100', 'interest_paid' => '9.09'],
        ]];
    $contract = $this->ledger->finance->open($this->book, 'lease', 'LEASE-42', '2026-01-01', 'lease', $configuration, $inputs);
    $this->ledger->finance->period($this->book, $contract, 'lease-1', '2026-01-31', 1, 'Pay January lease');
    $gain = $this->ledger->configurations->account($this->book, $configuration, 'loan.gain');
    $loss = $this->ledger->configurations->account($this->book, $configuration, 'loan.loss');
    $rou = $this->ledger->configurations->account($this->book, $configuration, 'lease.asset');

    $up = $this->ledger->finance->remeasureLease($this->book, $contract, 'index-up', '2026-01-31', [['due_on' => '2026-02-28', 'payment' => '110', 'interest_paid' => '10']],
        $gain, $loss, '0', 'CPI uplift', 'Index-linked payment increase');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('100.00')
        ->and($balances[$rou]->closing->decimal())->toBe('282.64');
    $this->ledger->finance->reverse($this->book, $up, 'undo-up', '2026-01-31', 'Wrong index');

    $this->ledger->finance->remeasureLease($this->book, $contract, 'index-down', '2026-01-31', [['due_on' => '2026-02-28', 'payment' => '55', 'interest_paid' => '5']],
        $gain, $loss, '20', 'Rent concession agreed', 'Reduced final payment');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('50.00')
        ->and($balances[$rou]->closing->decimal())->toBe('253.55')
        ->and($balances[$gain]->closing->decimal())->toBe('-20.91');
});

it('terminates a lease early by derecognizing the liability and the depreciated right-of-use asset', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'liability');
    $inputs = ['rate_per_slot' => '0.1', 'commencement_payment' => '100', 'interest_cash_flow' => 'operating', 'policy_version' => 'lease-policy:1',
        'basis_reference' => 'signed-lease:42', 'payments' => [
            ['due_on' => '2026-01-31', 'payment' => '100', 'interest_paid' => '17.36'],
            ['due_on' => '2026-02-28', 'payment' => '100', 'interest_paid' => '9.09'],
        ]];
    $contract = $this->ledger->finance->open($this->book, 'lease', 'LEASE-42', '2026-01-01', 'lease', $configuration, $inputs);
    $role = fn (string $name) => $this->ledger->configurations->account($this->book, $configuration, $name);
    $this->ledger->journals->post(new JournalRequest($this->book, 'rou-depreciation', '2026-01-31', 'Right-of-use depreciation', [
        new Posting($role('lease.depreciation'), Side::Debit, Money::of('50', $this->kes)), new Posting($role('lease.accumulated'), Side::Credit, Money::of('50', $this->kes)),
    ]));
    $this->ledger->finance->period($this->book, $contract, 'lease-1', '2026-01-31', 1, 'Pay January lease');
    $this->ledger->finance->settle($this->book, $contract, 'terminate', '2026-01-31', '80', '0', $role('loan.gain'), $role('loan.loss'), 'Termination agreement', 'Early termination');

    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = byAccount($this->ledger->reports->trialBalance($snapshot));
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('0.00')
        ->and($balances[$role('lease.asset')]->closing->decimal())->toBe('0.00')
        ->and($balances[$role('lease.accumulated')]->closing->decimal())->toBe('0.00')
        ->and($balances[$role('loan.loss')]->closing->decimal())->toBe('212.64')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
});

it('accrues interest between payment dates by actual days as an auto-reversing entry', function (string $kind) {
    $asset = $kind === 'loan_asset';
    $configuration = financeConfiguration($this->ledger, $this->book, $asset ? 'asset' : 'liability');
    $contract = $this->ledger->finance->open($this->book, 'accrue-loan', 'ACCRUE-LOAN', '2026-01-01', $kind, $configuration, registeredLoanInputs());
    $accrued = $this->ledger->catalog->account($this->book, $asset ? '1320' : '2320', 'Accrued interest', $asset ? AccountType::Asset : AccountType::Liability);
    $journal = $this->ledger->finance->accrueInterest($this->book, $contract, 'accrue-march', '2026-03-31', $accrued, 'March interest accrual');
    $lines = $this->ledger->journals->lines($this->book, $journal->id ?? '');
    $interest = $this->ledger->configurations->account($this->book, $configuration, 'interest');

    expect($lines[0]->amount->decimal())->toBe('46.97')
        ->and($lines[0]->accountId)->toBe($asset ? $accrued : $interest)
        ->and($journal?->metadata['accrual'])->toEqualCanonicalizing(['elapsed_days' => 89, 'slot_days' => 180, 'slot_interest_minor' => '9500'])
        ->and($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract)['carrying']->decimal())->toBe('950.00')
        ->and(fn () => $this->ledger->finance->accrueInterest($this->book, $contract, 'accrue-due', '2026-06-30', $accrued, 'On the due date'))->toThrow(LedgerException::class, 'strictly between');
})->with(['loan_asset', 'loan_liability']);

it('rejects unknown finance operations and posts nothing when an accrual rounds to zero', function () {
    $configuration = financeConfiguration($this->ledger, $this->book, 'asset');
    expect(fn () => $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'unknown', '2026-01-31', ['operation' => 'loan.unknown'])))
        ->toThrow(LedgerException::class, 'Unknown finance operation.');
    $contract = $this->ledger->finance->open($this->book, 'free-loan', 'FREE-LOAN', '2026-01-01', 'loan_asset', $configuration, [...registeredLoanInputs(), 'rate_per_slot' => '0', 'payments' => [
        ['due_on' => '2026-06-30', 'payment' => '950', 'interest_paid' => '0'],
    ]]);
    $accrued = $this->ledger->catalog->account($this->book, '1320', 'Accrued interest', AccountType::Asset);
    expect($this->ledger->finance->accrueInterest($this->book, $contract, 'accrue-free', '2026-03-31', $accrued, 'No interest due'))->toBeNull();
});
