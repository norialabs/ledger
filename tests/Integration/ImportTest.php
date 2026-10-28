<?php

declare(strict_types=1);

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Imports\ImportFormat;
use Noria\Ledger\Imports\ImportKind;
use Noria\Ledger\Imports\Sources;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\RateType;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
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
    $invalid = importFile('invalid-customers.csv', "Reference,Name\nACC-12,\n");
    $this->command('ledger:import', ['kind' => 'parties', 'path' => $invalid, '--tenant' => $this->scope->tenantId, '--option' => ['entity_id='.$this->entity], '--commit' => true])
        ->expectsOutputToContain('Row 1:')->assertExitCode(1);
    expect($this->ledger->catalog->parties($this->entity))->toHaveCount(2);
});

it('rejects statement imports into the wrong account and statements that do not reconcile or fit their period', function () {
    $rows = [['external_id' => 'S-1', 'value_on' => '2026-01-05', 'amount' => '10.005', 'description' => 'Fraction'], ['external_id' => 'S-2', 'value_on' => '2026-01-06', 'amount' => '20'],
        ['external_id' => 'S-3', 'value_on' => '2026-01-06', 'amount' => 'twenty']];
    $wrong = $this->ledger->imports->validate(ImportKind::BankStatement, $rows, ['book_id' => $this->book, 'account_id' => $this->income, 'reference' => 'S', 'opening' => '0']);
    $preview = $this->ledger->imports->validate(ImportKind::BankStatement, $rows,
        ['book_id' => $this->book, 'account_id' => $this->cash, 'reference' => 'S', 'opening' => '100', 'closing' => '999', 'starts_on' => '2026-01-06']);
    expect($wrong->errorsFor('_'))->toContain('Statements import into a cash or bank posting account.')
        ->and($preview->errorsFor(0))->toBe(['Amount has more decimals than KES allows.'])
        ->and($preview->errorsFor(2))->toBe(['A non-zero amount is required.'])
        ->and($preview->errorsFor('_'))->toBe(['Opening 100 plus movements 20 does not equal closing 999.', 'Movements fall outside the statement period.'])
        ->and(fn () => $this->ledger->imports->validate(ImportKind::BankStatement, $rows, ['account_id' => $this->cash]))->toThrow(LedgerException::class, 'The import option book_id is required.')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, importFile('statement.sta', ':20:X'), ImportFormat::Mt940))->toThrow(LedgerException::class, 'bank statements only')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, importFile('people.csv', "Reference,Name\nP-1,Pat\n"), null, ['entity_id' => $this->entity, 'columns' => ['name' => 'Full name']]))
        ->toThrow(LedgerException::class, 'Mapped column "Full name" is not in the file.');
});

it('validates imported charts and rates against currencies, parents, existing classifications and recorded rates', function () {
    $chart = $this->ledger->imports->validate(ImportKind::Accounts, [
        ['code' => '1200', 'name' => 'Euro bank', 'type' => 'asset', 'currency' => 'ZZZ'],
        ['code' => '1300', 'name' => 'Orphan', 'type' => 'asset', 'parent' => '9'],
        ['code' => '1000', 'name' => 'Bank', 'type' => 'liability'],
    ], ['book_id' => $this->book]);
    expect($chart->errorsFor(0))->toBe(['Unknown currency ZZZ.'])
        ->and($chart->errorsFor(1))->toBe(['Parent 9 is neither in the file nor in the chart.'])
        ->and($chart->errorsFor(2))->toBe(['Account 1000 already exists with a different classification.']);

    $ordered = $this->ledger->imports->submit(ImportKind::Accounts, [
        ['code' => '6100', 'name' => 'Rent', 'type' => 'expense', 'parent' => '6'], ['code' => '6', 'name' => 'Premises', 'type' => 'expense', 'group' => 'yes'],
    ], ['book_id' => $this->book]);
    expect($ordered['created'] ?? $ordered)->toHaveCount(2)
        ->and($this->ledger->catalog->accountByCode($this->book, '6100')->parentId)->toBe($this->ledger->catalog->accountByCode($this->book, '6')->id)
        ->and(fn () => $this->ledger->imports->submit(ImportKind::Accounts, [
            ['code' => '7', 'name' => 'Loop A', 'type' => 'expense', 'group' => 'yes', 'parent' => '8'], ['code' => '8', 'name' => 'Loop B', 'type' => 'expense', 'group' => 'yes', 'parent' => '7'],
        ], ['book_id' => $this->book]))->toThrow(LedgerException::class, 'Account parents form a cycle.');

    $this->ledger->rates->record(Currency::iso('USD'), $this->kes, RateType::Spot, '2026-01-02', '129.5', 'CBK');
    $rates = $this->ledger->imports->validate(ImportKind::ExchangeRates, [
        ['base' => 'USD', 'quote' => 'KES', 'type' => 'spot', 'date' => '2026-01-02', 'rate' => '129.50', 'source' => 'CBK'],
        ['base' => 'USD', 'quote' => 'KES', 'type' => 'closing', 'date' => '2026-01-02', 'rate' => '1', 'source' => 'CBK'],
        ['base' => 'ZZZ', 'quote' => 'KES', 'type' => 'spot', 'date' => '2026-01-03', 'rate' => '1'],
    ]);
    $this->ledger->rates->record(Currency::iso('USD'), $this->kes, RateType::Closing, '2026-01-02', '130', 'CBK');
    $changed = $this->ledger->imports->validate(ImportKind::ExchangeRates, [['base' => 'USD', 'quote' => 'KES', 'type' => 'closing', 'date' => '2026-01-02', 'rate' => '131', 'source' => 'CBK']]);
    expect(array_column($rates->rows, 'status'))->toBe(['existing', 'new', 'new'])
        ->and($rates->errorsFor(0))->toBe([])
        ->and($rates->errorsFor(2))->toBe(['Unknown currency ZZZ.'])
        ->and($changed->errorsFor(0))->toBe(['A different closing rate exists for 2026-01-02; correct it explicitly.']);
});

it('rejects journal dimensions that are not name=value pairs', function (mixed $dimensions) {
    $preview = $this->ledger->imports->validate(ImportKind::Journals, [
        ['journal' => 'D-1', 'date' => '2026-01-01', 'account' => '1000', 'debit' => '1', 'dimensions' => $dimensions],
        ['journal' => 'D-1', 'date' => '2026-01-01', 'account' => '4000', 'credit' => '1'],
    ], ['book_id' => $this->book]);
    expect($preview->errorsFor(0))->toContain('Dimensions use name=value pairs separated by semicolons.');
})->with([[['zone' => ['north']]], [7], ['zone']]);

it('reads delimited, multi-sheet, numeric and dated spreadsheet cells and refuses unreadable or oversized files', function () {
    $semicolon = $this->ledger->imports->preview(ImportKind::Parties, importFile('people.csv', "Reference;Name\n\n;\nP-1;Pat\n"), null, ['entity_id' => $this->entity, 'delimiter' => ';']);
    $path = sys_get_temp_dir().'/ledger-import-'.bin2hex(random_bytes(4)).'.xlsx';
    $writer = SimpleExcelWriter::create($path)->noHeaderRow();
    $writer->addRow(['Ignored', 'first sheet']);
    $writer->addNewSheetAndMakeItCurrent('Statement');
    $writer->addRow(['Reference', 'Date', 'Description', 'Amount']);
    $writer->addRow(Row::fromValuesWithStyles(['X-1', new DateTimeImmutable('2026-01-05'), 'Deposit', 1500.25], [1 => (new Style)->withFormat('yyyy-mm-dd')]));
    $writer->addRow(['X-2', '2026-01-06', 'Fee', -25]);
    $writer->close();
    $sheet = $this->ledger->imports->preview(ImportKind::BankStatement, $path, null, ['book_id' => $this->book, 'account_id' => $this->cash, 'reference' => 'X', 'opening' => '0', 'sheet' => 'Statement']);
    expect(array_column($semicolon->rows, 'reference'))->toBe(['P-1'])
        ->and(array_column($sheet->rows, 'amount'))->toBe(['1500.25', '-25'])
        ->and(array_column($sheet->rows, 'value_on'))->toBe(['2026-01-05', '2026-01-06'])
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, importFile('blank.csv', "Nothing,Here\n1,2\n"), null, ['entity_id' => $this->entity]))
        ->toThrow(LedgerException::class, 'No header row with recognizable columns was found.')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, sys_get_temp_dir().'/ledger-import-missing.csv', null, ['entity_id' => $this->entity]))
        ->toThrow(LedgerException::class, 'The import file cannot be read.')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, importFile('huge.csv', "Reference,Name\n".str_repeat("P,Pat\n", Sources::MAX_ROWS + 1)), null, ['entity_id' => $this->entity]))
        ->toThrow(LedgerException::class, 'Imports are limited to');
});

it('guesses formats from extensions and rejects malformed JSON, MT940 and CAMT.053 files', function () {
    $statement = ['book_id' => $this->book, 'account_id' => $this->cash];
    $camt = fn (string $statements): string => importFile('camt.xml', '<?xml version="1.0"?><Document><BkToCstmrStmt>'.$statements.'</BkToCstmrStmt></Document>');
    $entry = '<Stmt><Id>C-9</Id><Bal><Tp><CdOrPrtry><Cd>ITBD</Cd></CdOrPrtry></Tp><Amt Ccy="KES">1.00</Amt></Bal>'
        .'<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="KES">0.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-01</Dt></Dt></Bal>'
        .'<Ntry><Amt Ccy="KES">5.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-03-02</Dt></BookgDt><NtryDtls><TxDtls><Refs><EndToEndId>E2E-1</EndToEndId></Refs></TxDtls></NtryDtls></Ntry></Stmt>';
    expect(array_map(fn (string $name): ImportFormat => ImportFormat::fromPath($name), ['a.json', 'a.sta', 'a.mt940', 'a.txt', 'a.xml', 'a.csv']))
        ->toBe([ImportFormat::Json, ImportFormat::Mt940, ImportFormat::Mt940, ImportFormat::Mt940, ImportFormat::Camt053, ImportFormat::Csv])
        ->and(array_column($this->ledger->imports->preview(ImportKind::BankStatement, $camt($entry), null, $statement)->rows, 'external_id'))->toBe(['E2E-1'])
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, importFile('rows.json', '{"rows": "none"}'), null, ['entity_id' => $this->entity]))->toThrow(LedgerException::class, 'list of row objects')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::Parties, importFile('rows.json', '[["P-1", "Pat"]]'), null, ['entity_id' => $this->entity]))->toThrow(LedgerException::class, 'must be an object')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::BankStatement, importFile('two.sta', ":20:A\n:20:B\n"), null, $statement))->toThrow(LedgerException::class, 'Split multi-statement')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::BankStatement, importFile('bal.sta', ":20:A\n:60F:X\n"), null, $statement))->toThrow(LedgerException::class, 'Malformed MT940 balance field :60F:')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::BankStatement, importFile('line.sta', ":20:A\n:61:garbage\n"), null, $statement))->toThrow(LedgerException::class, 'Malformed MT940 statement line')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::BankStatement, importFile('date.sta', ":20:A\n:60F:C261332KES1,00\n"), null, $statement))->toThrow(LedgerException::class, 'Invalid statement date 261332')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::BankStatement, importFile('bad.xml', '<Document>'), null, $statement))->toThrow(LedgerException::class, 'not valid XML')
        ->and(fn () => $this->ledger->imports->preview(ImportKind::BankStatement, $camt($entry.$entry), null, $statement))->toThrow(LedgerException::class, 'exactly one statement');
});
