<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
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

function financeConfiguration(Session $ledger, string $book, string $position = 'asset'): string
{
    return $ledger->configurations->install($book, new Pack('example-finance', '1', accounts: [
        ['code' => '1300', 'name' => 'Loan amortized cost', 'type' => $position],
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
        'contract.liability' => '2600', 'revenue.equipment' => '4200', 'revenue.service' => '4300', 'loan.gain' => '4500', 'loan.loss' => '5500']));
}

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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$loan]->closing->decimal())->toBe('0.00')
        ->and($this->ledger->reports->incomeStatement($snapshot, '2026-01-01')['profit']->decimal())->toBe($lender ? '189.50' : '-189.50')
        ->and($this->ledger->reports->cashFlow($snapshot, '2026-01-01')->isFullyClassified())->toBeTrue();
    $this->ledger->periods->close($this->book, $this->period, 'Close measured lending');
    expect($this->ledger->treatments->post($request)->id)->toBe($journal->id);
})->with(['asset', 'liability']);

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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
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
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-04-01')))->keyBy('accountId');
    expect($balances[$liability]->closing->decimal())->toBe('-90.91');
    $this->storage->run(Permission::Read, function () use ($commencement): void {
        $metadata = json_decode($this->storage->find('ledger_journals', $commencement->id, $this->book)->metadata, true, 512, JSON_THROW_ON_ERROR);
        expect($metadata['finance']['liability']['amount'])->toBe('248.69')
            ->and($metadata['finance']['rows'][0]['rounding_adjustment']['amount'])->toBe('-0.01');
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
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')))->keyBy('accountId');
    expect($balances[$allowance]->closing->decimal())->toBe('-50.00');
    $this->storage->run(Permission::Read, function () use ($second): void {
        $metadata = json_decode($this->storage->find('ledger_journals', $second->id, $this->book)->metadata, true, 512, JSON_THROW_ON_ERROR);
        expect($metadata['finance']['previous_allowance']['amount'])->toBe('150.00')
            ->and($metadata['finance']['change']['amount'])->toBe('-100.00');
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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($balances[$contract]->closing->decimal())->toBe('-33.33');
    try {
        $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'equipment-duplicate', '2026-02-01', $inputs));
        test()->fail('A new transport identity must not recognize the same obligation twice.');
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
        test()->fail('Invalid finance treatment must fail.');
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
    $balances = collect($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-02-28')))->keyBy('accountId');
    expect($balances[$allowance]->closing->decimal())->toBe('-300.00');
    $inputs['dimensions'] = ['portfolio' => 'school'];
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->treatments->post(financeRequest($this->book, $configuration, 'backdated-school', '2026-01-31', $inputs));
        test()->fail('A valuation cannot precede its bucket history.');
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
        test()->fail('Dependent finance periods must be corrected first.');
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
        test()->fail('Zero financial effect must not bypass retired references.');
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
    $sql = $this->storage->connection->selectOne('SELECT ledger_finance_pv(?::jsonb, ?::numeric, 2)::text AS amount', [json_encode($values, JSON_THROW_ON_ERROR), $rate]);
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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
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
        $metadata = json_decode($this->storage->find('ledger_journals', $event->journal_id, $this->book)->metadata, true, 512, JSON_THROW_ON_ERROR);
        expect($metadata['finance_calculation']['modification_effect_minor'])->toBe('-14500')
            ->and($metadata['finance_calculation']['allowance_before_minor'])->toBe('20900')
            ->and($metadata['finance_calculation']['allowance_after_minor'])->toBe('30000');
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
        $event = $this->storage->table('ledger_finance_events')->where('book_id', $this->book)->where('idempotency_key', 'extended-period-0')->first();
        $metadata = json_decode($this->storage->find('ledger_journals', $event->journal_id, $this->book)->metadata, true, 512, JSON_THROW_ON_ERROR);
        expect($metadata['finance_calculation']['rounding_adjustment_minor'])->toBe('-1');
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
        test()->fail('The original plan cannot consume a modified period.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('current payment plan');
    }
    $period = $this->ledger->finance->period($this->book, $contract, 'correct-modified-period', '2026-12-31', 1, 'Pay revised period', planId: $change);
    try {
        $this->ledger->finance->reverse($this->book, $change, 'premature-modification-reversal', '2026-12-31', 'Attempt premature plan restoration');
        test()->fail('Later financial activity must be corrected first.');
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
        test()->fail('An unpaid schedule cannot silently assume later cash arrived.');
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
    if ($case === 'model') {
        $model['contractual'][0]['amount'] = '991';
    }
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->finance->modify($this->book, $contract, 'invalid-change-attempt', $case === 'basis' ? '2026-07-01' : '2026-06-30', $payments,
            $this->ledger->configurations->account($this->book, $configuration, 'loan.gain'), $this->ledger->configurations->account($this->book, $configuration, 'loan.loss'),
            'Reviewed proposed change', 'Attempt unsupported modification', $model);
        test()->fail('Unsupported loan changes must fail atomically.');
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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
    expect($this->ledger->finance->position($snapshot, $contract)['carrying']->decimal())->toBe('100.00')
        ->and($balances[$rou]->closing->decimal())->toBe('282.64');
    $this->ledger->finance->reverse($this->book, $up, 'undo-up', '2026-01-31', 'Wrong index');

    $this->ledger->finance->remeasureLease($this->book, $contract, 'index-down', '2026-01-31', [['due_on' => '2026-02-28', 'payment' => '55', 'interest_paid' => '5']],
        $gain, $loss, '20', 'Rent concession agreed', 'Reduced final payment');
    $snapshot = $this->ledger->reports->snapshot($this->book, '2026-01-31');
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
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
    $balances = collect($this->ledger->reports->trialBalance($snapshot))->keyBy('accountId');
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
    $lines = $this->ledger->journals->lines($this->book, $journal?->id ?? '');
    $interest = $this->ledger->configurations->account($this->book, $configuration, 'interest');

    expect($lines[0]->amount->decimal())->toBe('46.97')
        ->and($lines[0]->accountId)->toBe($asset ? $accrued : $interest)
        ->and($journal?->metadata['accrual'])->toEqualCanonicalizing(['elapsed_days' => 89, 'slot_days' => 180, 'slot_interest_minor' => '9500'])
        ->and($this->ledger->finance->position($this->ledger->reports->snapshot($this->book, '2026-03-31'), $contract)['carrying']->decimal())->toBe('950.00')
        ->and(fn () => $this->ledger->finance->accrueInterest($this->book, $contract, 'accrue-due', '2026-06-30', $accrued, 'On the due date'))->toThrow(LedgerException::class, 'strictly between');
})->with(['loan_asset', 'loan_liability']);
