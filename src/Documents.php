<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger;

use NoriaLabs\Ledger\Access\Permission;
use NoriaLabs\Ledger\Accounting\Journal;
use NoriaLabs\Ledger\Accounting\JournalRequest;
use NoriaLabs\Ledger\Accounting\Posting;
use NoriaLabs\Ledger\Accounting\Side;
use NoriaLabs\Ledger\Documents\DocumentRequest;
use NoriaLabs\Ledger\Documents\PaymentRequest;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Storage\ScopedDatabase;
use NoriaLabs\Ledger\Support\Assert;
use NoriaLabs\Ledger\Support\Row;

final readonly class Documents
{
    public function __construct(private ScopedDatabase $db, private Journals $journals) {}

    public function issue(DocumentRequest $request): Journal
    {
        return $this->db->run(Permission::Post, function () use ($request): Journal {
            $book = $this->db->lockBook($request->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $account = $this->db->find('ledger_accounts', $request->controlAccountId, $request->bookId);
            Assert::that(Row::boolean($account, 'is_control'), 'Document counterparty account must be a control account.');
            Assert::that(Row::text($account, 'type') === $request->kind->controlType()->value, 'Sales documents require an asset control account; purchase documents require a liability control account.');
            $currency = $request->obligations[0]->amount->currency;
            $totalObligations = Money::minor('0', $currency);
            $totalCharges = Money::minor('0', $currency);
            $postings = [];

            foreach ($request->obligations as $obligation) {
                $totalObligations = $totalObligations->plus($obligation->amount);
                $postings[] = new Posting($request->controlAccountId, $request->kind->controlSide(), $obligation->amount,
                    $request->exchangeRate, $obligation->partyId, $obligation->dueOn, $obligation->dimensions);
            }

            $controlFunctional = Money::minor('0', $functional);

            foreach ($request->obligations as $obligation) {
                $controlFunctional = $controlFunctional->plus($request->exchangeRate?->convert($obligation->amount) ?? $obligation->amount);
            }

            $chargeFunctional = Money::minor('0', $functional);

            foreach ($request->charges as $charge) {
                $totalCharges = $totalCharges->plus($charge->amount);
                $counter = $this->db->find('ledger_accounts', $charge->accountId, $request->bookId);
                Assert::that(! Row::boolean($counter, 'is_control'), 'A document charge cannot target another control account.');
                $converted = $request->exchangeRate?->convert($charge->amount) ?? $charge->amount;
                $chargeFunctional = $chargeFunctional->plus($converted);
                $counterCurrency = Row::nullable($counter, 'currency_code') ?? $functional->code;
                $postings[] = new Posting($charge->accountId, $request->kind->controlSide()->opposite(),
                    $counterCurrency === $currency->code ? $charge->amount : $converted,
                    $counterCurrency === $currency->code ? $request->exchangeRate : null,
                    dimensions: $charge->dimensions, description: $charge->description);
            }

            Assert::that($totalCharges->minor->isEqualTo($totalObligations->minor), 'Document obligations and charges must have the same total.');
            Assert::that($controlFunctional->minor->isEqualTo($chargeFunctional->minor), 'Document FX rounding differs between charges and obligations; provide an explicit rounding charge or split the document.');

            return $this->journals->post(new JournalRequest($request->bookId, $request->key, $request->effectiveOn, $request->kind->value.' '.$request->source->reference, $postings,
                ['document' => ['kind' => $request->kind->value, 'beneficiary_reference' => $request->beneficiaryReference,
                    'obligations' => $request->obligations, 'charges' => $request->charges, 'policy' => 'document:v1'], 'custom' => $request->metadata], source: $request->source));
        }, $request->bookId);
    }

    public function payment(PaymentRequest $request): Journal
    {
        return $this->db->run(Permission::Post, function () use ($request): Journal {
            $book = $this->db->lockBook($request->bookId);
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $control = $this->db->find('ledger_accounts', $request->controlAccountId, $request->bookId);
            $bank = $this->db->find('ledger_accounts', $request->cashAccountId, $request->bookId);
            Assert::that(Row::boolean($control, 'is_control') && Row::nullable($bank, 'cash_flow') === 'cash' && ! Row::boolean($bank, 'is_control'), 'Payment requires a control account and a cash account.');
            $bankCurrency = Row::nullable($bank, 'currency_code') ?? $functional->code;
            Assert::that(in_array($bankCurrency, [$request->amount->currency->code, $functional->code], true), 'Use an explicit journal for a payment involving a third currency.');
            $bankAmount = $bankCurrency === $request->amount->currency->code ? $request->amount : $request->exchangeRate?->convert($request->amount);
            Assert::that($bankAmount !== null, 'A cross-currency payment needs an exchange rate.');

            return $this->journals->post(new JournalRequest($request->bookId, $request->key, $request->effectiveOn,
                ($request->receipt ? 'Receipt ' : 'Payment ').$request->source->reference, [
                    new Posting($request->cashAccountId, $request->receipt ? Side::Debit : Side::Credit, $bankAmount ?? $request->amount,
                        $bankCurrency === $request->amount->currency->code ? $request->exchangeRate : null, dimensions: $request->dimensions),
                    new Posting($request->controlAccountId, $request->receipt ? Side::Credit : Side::Debit, $request->amount,
                        $request->exchangeRate, $request->partyId, dimensions: $request->dimensions),
                ], ['payment' => ['payer_reference' => $request->payerReference, 'policy' => 'payment:v1']], source: $request->source));
        }, $request->bookId);
    }
}
