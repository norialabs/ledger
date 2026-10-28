<?php

declare(strict_types=1);

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Imports\ImportFormat;
use Noria\Ledger\Imports\ImportKind;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\RateType;
use Spatie\SimpleExcel\SimpleExcelWriter;

function importFile(string $name, string $content): string
{
    $path = sys_get_temp_dir().'/ledger-import-'.bin2hex(random_bytes(4)).'-'.$name;
    file_put_contents($path, $content);

    return $path;
}

beforeEach(function () {
    $this->mpesa = $this->ledger->catalog->account($this->book, '1020', 'M-Pesa paybill', AccountType::Asset, cashFlow: 'cash');
});

it('previews an M-Pesa statement export newest first, skips failed transactions and derives opening and closing balances', function () {
    $path = importFile('mpesa.csv', implode("\n", [
        'Organization Name,BillGrid Water',
        'Statement Period,01-01-2026 - 31-01-2026',
        '',
        'Receipt No.,Completion Time,Initiation Time,Details,Transaction Status,Paid In,Withdrawn,Balance,Balance Confirmed,Reason Type,Other Party Info,Linked Transaction ID,A/C No.',
        'QAB3,2026-01-20 09:15:00,2026-01-20 09:15:00,Pay Bill Online,Completed,,"-1,200.00","4,300.00",true,Business Payment,Supplier,,',
        'QAB9,2026-01-15 11:00:00,2026-01-15 11:00:00,Pay Bill Online,Failed,500.00,,"5,500.00",true,Pay Bill,254700000003 - JANE,,ACC-9',
        'QAB2,2026-01-15 10:00:00,2026-01-15 10:00:00,Pay Bill Online,Completed,"2,500.00",,"5,500.00",true,Pay Bill,254700000002 - PETER,,ACC-2',
        'QAB1,2026-01-05 08:00:00,2026-01-05 08:00:00,Pay Bill Online,Completed,"1,000.00",,"3,000.00",true,Pay Bill,254700000001 - MARY,,ACC-1',
    ]));
    $preview = $this->ledger->imports->preview(ImportKind::BankStatement, $path, ImportFormat::Mpesa, ['book_id' => $this->book, 'account_id' => $this->mpesa, 'reference' => 'MPESA-2026-01']);

    expect($preview->isValid())->toBeTrue()
        ->and(array_column($preview->rows, 'external_id'))->toBe(['QAB1', 'QAB2', 'QAB3'])
        ->and(array_column($preview->rows, 'amount'))->toBe(['1000.00', '2500.00', '-1200.00'])
        ->and(array_column($preview->rows, 'account_reference'))->toBe(['ACC-1', 'ACC-2', null])
        ->and($preview->rows[0]['description'])->toBe('Pay Bill Online - 254700000001 - MARY')
        ->and($preview->summary)->toMatchArray(['opening' => '2000.00', 'closing' => '4300.00', 'starts_on' => '2026-01-05', 'ends_on' => '2026-01-20', 'count' => 3, 'skipped' => 1, 'money_in' => '3500.00', 'money_out' => '-1200.00']);

    $result = $this->ledger->imports->submit(ImportKind::BankStatement, $preview->rows, [...$preview->summary, 'book_id' => $this->book, 'account_id' => $this->mpesa]);
    expect($this->ledger->banking->lines($this->book, $result['created'][0]))->toHaveCount(3)
        ->and($this->ledger->imports->validate(ImportKind::BankStatement, $preview->rows, [...$preview->summary, 'reference' => 'MPESA-AGAIN'])->errorsFor(0))
        ->toBe(['Reference QAB1 was already imported for this account.']);
});

it('reads a generic Excel statement with mapped columns and lets edited rows be revalidated before submission', function () {
    $path = sys_get_temp_dir().'/ledger-import-'.bin2hex(random_bytes(4)).'.xlsx';
    SimpleExcelWriter::create($path)->addRows([
        ['Txn Ref' => 'B-1', 'Value Date' => '05/01/2026', 'Narrative' => 'Customer deposit', 'Money In' => '1500', 'Money Out' => ''],
        ['Txn Ref' => 'B-2', 'Value Date' => '06/01/2026', 'Narrative' => 'Bank charges', 'Money In' => '', 'Money Out' => '25.50'],
        ['Txn Ref' => 'B-2', 'Value Date' => '31/02/2026', 'Narrative' => 'Duplicate', 'Money In' => '0', 'Money Out' => ''],
    ])->close();
    $options = ['book_id' => $this->book, 'account_id' => $this->cash, 'reference' => 'EQUITY-2026-01', 'opening' => '100', 'columns' => ['external_id' => 'Txn Ref']];
    $preview = $this->ledger->imports->preview(ImportKind::BankStatement, $path, null, $options);

    expect($preview->isValid())->toBeFalse()
        ->and($preview->errorsFor(2))->toContain('Reference B-2 repeats in this file.', 'Invalid date.', 'A non-zero amount is required.')
        ->and($preview->summary['closing'])->toBe('1574.50');

    $rows = array_slice($preview->rows, 0, 2);
    $fixed = $this->ledger->imports->validate(ImportKind::BankStatement, $rows, $options);
    expect($fixed->isValid())->toBeTrue()
        ->and(fn () => $this->ledger->imports->submit(ImportKind::BankStatement, $preview->rows, $options))->toThrow(LedgerException::class, 'validation errors');
    $this->ledger->imports->submit(ImportKind::BankStatement, $rows, $options);
    expect(data_get(json_decode(json_encode($fixed, JSON_THROW_ON_ERROR), true), 'valid'))->toBeTrue();
});

it('imports MT940 and CAMT.053 statements with their own balances', function () {
    $mt940 = importFile('statement.sta', implode("\n", [':20:STMT-0001', ':25:KE001/123456', ':28C:1/1', ':60F:C260101KES1000,00',
        ':61:2601050105C250,00NTRFINV-42//BANKREF1', ':86:Payment from customer INV-42', ':61:2601060106D40,50NMSCNONREF//BANKREF2', ':86:Monthly fee',
        ':62F:C260131KES1209,50', '-}']));
    $preview = $this->ledger->imports->preview(ImportKind::BankStatement, $mt940, ImportFormat::Mt940, ['book_id' => $this->book, 'account_id' => $this->cash]);
    expect($preview->isValid())->toBeTrue()
        ->and($preview->summary)->toMatchArray(['reference' => 'STMT-0001', 'opening' => '1000.00', 'closing' => '1209.50', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31'])
        ->and(array_column($preview->rows, 'external_id'))->toBe(['BANKREF1', 'BANKREF2'])
        ->and($preview->rows[0]['description'])->toBe('Payment from customer INV-42');

    $camt = importFile('camt.xml', '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Id>CAMT-77</Id>
        <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="KES">500.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-02-01</Dt></Dt></Bal>
        <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="KES">650.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-02-28</Dt></Dt></Bal>
        <Ntry><Amt Ccy="KES">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-02-03</Dt></BookgDt><AcctSvcrRef>C-1</AcctSvcrRef>
            <NtryDtls><TxDtls><RmtInf><Ustrd>Water bill ACC-7</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>
        <Ntry><Amt Ccy="KES">50.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-02-10</Dt></BookgDt><AcctSvcrRef>C-2</AcctSvcrRef><AddtlNtryInf>Charges</AddtlNtryInf></Ntry>
        </Stmt></BkToCstmrStmt></Document>');
    $preview = $this->ledger->imports->preview(ImportKind::BankStatement, $camt, ImportFormat::Camt053, ['book_id' => $this->book, 'account_id' => $this->cash]);
    expect($preview->isValid())->toBeTrue()
        ->and(array_column($preview->rows, 'amount'))->toBe(['200.00', '-50.00'])
        ->and($preview->summary)->toMatchArray(['reference' => 'CAMT-77', 'opening' => '500.00', 'closing' => '650.00']);
});

it('imports customers, a chart of accounts and exchange rates from CSV and JSON with existing rows recognized', function () {
    $this->ledger->catalog->party($this->entity, 'ACC-1', 'Mary Wanjiku');
    $parties = $this->ledger->imports->preview(ImportKind::Parties, importFile('customers.csv', "Account Number,Customer Name\nACC-1,Mary Wanjiku\nACC-2,Peter Otieno\nACC-3,\n"), null, ['entity_id' => $this->entity]);
    expect(array_column($parties->rows, 'status'))->toBe(['existing', 'new', 'new'])
        ->and($parties->errorsFor(2))->toBe(['A name of up to 256 characters is required.']);
    $created = $this->ledger->imports->submit(ImportKind::Parties, array_slice($parties->rows, 0, 2), ['entity_id' => $this->entity]);
    expect($created['created'])->toHaveCount(1)->and($created['existing'])->toBe(1);

    $chart = $this->ledger->imports->preview(ImportKind::Accounts, importFile('chart.json', json_encode(['rows' => [
        ['code' => '5', 'name' => 'Operating expenses', 'type' => 'expense', 'group' => 'yes'],
        ['code' => '5100', 'name' => 'Repairs and maintenance', 'type' => 'expense', 'parent' => '5', 'cash_flow' => 'operating'],
        ['code' => '1000', 'name' => 'Bank', 'type' => 'asset'],
    ]], JSON_THROW_ON_ERROR)), ImportFormat::Json, ['book_id' => $this->book]);
    expect($chart->isValid())->toBeTrue()->and($chart->summary)->toBe(['new' => 2, 'existing' => 1]);
    $this->ledger->imports->submit(ImportKind::Accounts, $chart->rows, ['book_id' => $this->book]);
    expect($this->ledger->catalog->accountByCode($this->book, '5100')->parentId)->toBe($this->ledger->catalog->accountByCode($this->book, '5')->id);

    $rates = $this->ledger->imports->preview(ImportKind::ExchangeRates, importFile('rates.csv', "Base,Quote,Type,Date,Rate,Source\nUSD,KES,spot,2026-01-02,129.50,CBK\nUSD,KES,spot,2026-01-02,130,CBK\n"));
    expect($rates->errorsFor(1))->toBe(['This rate repeats in the file.']);
    $this->ledger->imports->submit(ImportKind::ExchangeRates, [$rates->rows[0]]);
    expect($this->ledger->rates->find(Currency::iso('USD'), $this->kes, RateType::Spot, '2026-01-03')->rate)->toBe('129.5');
});

it('imports balanced opening-balance journals with parties and dimensions and rejects unbalanced ones', function () {
    $receivable = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $equity = $this->ledger->catalog->account($this->book, '3000', 'Opening equity', AccountType::Equity);
    $this->ledger->catalog->party($this->entity, 'ACC-1', 'Mary');
    $this->ledger->catalog->dimensionValue($this->entity, 'zone', 'north');
    $csv = "Journal,Date,Description,Account,Debit,Credit,Party,Due Date,Dimensions\n"
        ."OB-1,2026-01-01,Opening balances,1000,5000,,,,zone=north\n"
        ."OB-1,2026-01-01,Opening balances,1100,1200,,ACC-1,2026-01-31,zone=north\n"
        ."OB-1,2026-01-01,Opening balances,3000,,6200,,,zone=north\n"
        ."OB-2,2026-01-01,Broken,1000,10,,,,\n"
        ."OB-2,2026-01-01,Broken,3000,,9,,,\n";
    $preview = $this->ledger->imports->preview(ImportKind::Journals, importFile('opening.csv', $csv), null, ['book_id' => $this->book]);
    expect($preview->errorsFor(3))->toBe(['Journal OB-2 is out of balance by 1.00 KES.'])
        ->and($preview->summary)->toMatchArray(['journals' => 2, 'lines' => 5]);

    $result = $this->ledger->imports->submit(ImportKind::Journals, array_slice($preview->rows, 0, 3), ['book_id' => $this->book]);
    $balances = byAccount($this->ledger->reports->trialBalance($this->ledger->reports->snapshot($this->book, '2026-01-31'), dimensions: ['zone' => 'north']));
    expect($result['created'])->toHaveCount(1)
        ->and($balances[$receivable]->closing->decimal())->toBe('1200.00')
        ->and($balances[$equity]->closing->decimal())->toBe('-6200.00')
        ->and($this->ledger->reports->openItems($this->ledger->reports->snapshot($this->book, '2026-01-31'))[0]->dueOn)->toBe('2026-01-31')
        ->and($this->ledger->imports->validate(ImportKind::Journals, array_slice($preview->rows, 0, 3), ['book_id' => $this->book])->errorsFor(0))->toBe(['Journal OB-1 was already imported.']);
});

it('previews and commits an import from artisan', function () {
    $path = importFile('customers.csv', "Reference,Name\nACC-10,Grace\nACC-11,Hassan\n");
    $this->command('ledger:import', ['kind' => 'parties', 'path' => $path, '--tenant' => $this->scope->tenantId, '--option' => ['entity_id='.$this->entity]])->assertExitCode(0);
    expect($this->ledger->catalog->parties($this->entity))->toBe([]);
    $this->command('ledger:import', ['kind' => 'parties', 'path' => $path, '--tenant' => $this->scope->tenantId, '--option' => ['entity_id='.$this->entity], '--commit' => true])->assertExitCode(0);
    expect(array_map(fn ($party) => $party->reference, $this->ledger->catalog->parties($this->entity)))->toBe(['ACC-10', 'ACC-11']);
});
