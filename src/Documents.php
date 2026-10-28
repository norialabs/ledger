<?php

declare(strict_types=1);

namespace Noria\Ledger;

use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\Journal;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\PaymentRequest;
use Noria\Ledger\Documents\TaxedDocumentRequest;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Support\Assert;
use Noria\Ledger\Support\Canonical;
use Noria\Ledger\Support\Row;
use Noria\Ledger\Tax\TaxCalculator;
use stdClass;

readonly class Documents
{
    public function __construct(private ScopedDatabase $db, private Journals $journals, private ?Configurations $configurations = null) {}

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
                    new Posting($request->cashAccountId, $request->receipt ? Side::Debit : Side::Credit, $bankAmount,
                        $bankCurrency === $request->amount->currency->code ? $request->exchangeRate : null, dimensions: $request->dimensions),
                    new Posting($request->controlAccountId, $request->receipt ? Side::Credit : Side::Debit, $request->amount,
                        $request->exchangeRate, $request->partyId, dimensions: $request->dimensions),
                ], ['payment' => ['payer_reference' => $request->payerReference, 'policy' => 'payment:v1']], source: $request->source));
        }, $request->bookId);
    }

    public function issueTaxed(TaxedDocumentRequest $request): Journal
    {
        Assert::that($this->configurations !== null, 'Taxed documents require a configuration service.');

        return $this->db->run(Permission::Post, function () use ($request): Journal {
            $book = $this->db->lockBook($request->bookId);
            $configuration = $this->configurations->get($request->bookId, $request->configurationId, $request->effectiveOn);
            $roles = $configuration['roles'];
            $requestHash = Canonical::hash($request);
            $existing = $this->db->table('ledger_journals')->where('book_id', $request->bookId)->where('idempotency_key', $request->key)->first();
            if ($existing instanceof stdClass) {
                $evidence = Row::json($existing, 'metadata')['tax_document'] ?? null;
                Assert::that(is_array($evidence) && ($evidence['request_hash'] ?? null) === $requestHash, 'Taxed document retry key already contains different inputs.');

                return Journal::fromRow($existing);
            }
            Assert::that(isset($roles[$request->controlRole]), 'Unknown taxed document control role.');
            $control = $this->db->find('ledger_accounts', $roles[$request->controlRole], $request->bookId);
            Assert::that(Row::boolean($control, 'is_control') && Row::text($control, 'type') === $request->kind->controlType()->value, 'Taxed document control account has the wrong accounting type.');
            $functional = $this->db->currency(Row::text($book, 'currency_code'));
            $currency = $request->obligations[0]->amount->currency;
            $total = Money::minor('0', $currency);
            $controlFunctional = Money::minor('0', $functional);
            $counterFunctional = Money::minor('0', $functional);
            $gross = Money::minor('0', $currency);
            $postings = [];
            foreach ($request->obligations as $obligation) {
                $total = $total->plus($obligation->amount);
                $controlFunctional = $controlFunctional->plus($request->exchangeRate?->convert($obligation->amount) ?? $obligation->amount);
                $postings[] = new Posting($roles[$request->controlRole], $request->kind->controlSide(), $obligation->amount, $request->exchangeRate,
                    $obligation->partyId, $obligation->dueOn, $obligation->dimensions);
            }
            $availableRules = [];
            foreach ($this->configurations->taxRules($request->bookId, $request->configurationId, $request->effectiveOn) as $rule) {
                $availableRules[$rule->code] = $rule;
            }
            $calculator = new TaxCalculator;
            $lines = [];
            foreach ($request->lines as $line) {
                $rules = [];
                foreach ($line->taxCodes as $code) {
                    Assert::that(isset($availableRules[$code]) && isset($request->taxRoles[$code]), 'A selected tax requires a configured rule and explicit posting role: '.$code);
                    $rules[] = $availableRules[$code];
                }
                $result = $calculator->calculate($line->price, $line->quantity, $currency, $request->effectiveOn, $rules, $request->rounding, $line->discountFraction);
                $gross = $gross->plus($result->gross);
                $components = [[$line->accountRole, $result->net]];
                foreach ($result->taxes as $code => $amount) {
                    $components[] = [$request->taxRoles[$code], $amount];
                }
                if (! $result->roundingAdjustment->minor->isZero()) {
                    Assert::that($request->roundingRole !== null, 'Tax rounding requires an explicit rounding account role.');
                    $components[] = [$request->roundingRole ?? '', $result->roundingAdjustment];
                }
                foreach ($components as [$role, $amount]) {
                    if ($amount->minor->isZero()) {
                        continue;
                    }
                    Assert::that(isset($roles[$role]), 'Unknown taxed document posting role: '.$role);
                    $account = $this->db->find('ledger_accounts', $roles[$role], $request->bookId);
                    Assert::that(! Row::boolean($account, 'is_control'), 'A tax document charge cannot target a control account.');
                    if ($role === $request->roundingRole) {
                        $this->validateRoundingAccount($account, $functional);
                    }
                    $converted = $request->exchangeRate?->convert($amount) ?? $amount;
                    $counterFunctional = $counterFunctional->plus($converted);
                    $postings[] = $this->taxPosting($account, $amount, $converted, $functional, $request->kind->controlSide()->opposite(),
                        $request->exchangeRate, $line->dimensions, $line->description);
                }
                $lines[] = ['input' => $line, 'rules' => $rules, 'result' => $result];
            }
            Assert::that($total->minor->isEqualTo($gross->minor), 'Taxed document obligations must equal the calculated gross amount.');
            $fxRounding = $controlFunctional->minus($counterFunctional);
            if (! $fxRounding->minor->isZero()) {
                Assert::that($request->roundingRole !== null && isset($roles[$request->roundingRole]), 'Document FX rounding requires an explicit rounding account role.');
                $account = $this->db->find('ledger_accounts', $roles[$request->roundingRole ?? ''], $request->bookId);
                $this->validateRoundingAccount($account, $functional);
                $postings[] = $this->taxPosting($account, $fxRounding, $fxRounding, $functional, $request->kind->controlSide()->opposite(), null, [], 'Document FX rounding');
            }

            return $this->journals->post(new JournalRequest($request->bookId, $request->key, $request->effectiveOn, $request->kind->value.' '.$request->source->reference,
                $postings, ['tax_document' => ['request_hash' => $requestHash, 'request' => $request, 'configuration_hash' => $configuration['content_hash'],
                    'lines' => $lines, 'fx_rounding' => $fxRounding, 'policy' => 'tax-document:v1']], source: $request->source));
        }, $request->bookId);
    }

    private function validateRoundingAccount(stdClass $account, Currency $functional): void
    {
        Assert::that(in_array(Row::text($account, 'type'), ['income', 'expense'], true) && ! Row::boolean($account, 'is_group')
            && (Row::nullable($account, 'currency_code') ?? $functional->code) === $functional->code, 'Rounding requires a functional income or expense account.');
    }

    /** @param array<string, string> $dimensions */
    private function taxPosting(stdClass $account, Money $amount, Money $converted, Currency $functional, Side $side, ?ExchangeRate $rate, array $dimensions, string $description): Posting
    {
        $sameCurrency = (Row::nullable($account, 'currency_code') ?? $functional->code) === $amount->currency->code;
        $posted = $sameCurrency ? $amount : $converted;

        return new Posting(Row::text($account, 'id'), $amount->minor->isPositive() ? $side : $side->opposite(),
            Money::minor((string) $posted->minor->abs(), $posted->currency), $sameCurrency ? $rate : null, dimensions: $dimensions, description: $description);
    }
}
