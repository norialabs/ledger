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
use Noria\Ledger\Configuration\Pack;
use Noria\Ledger\Documents\Charge;
use Noria\Ledger\Documents\DocumentKind;
use Noria\Ledger\Documents\DocumentRequest;
use Noria\Ledger\Documents\Obligation;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Ledger;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Session;
use Noria\Ledger\Statutory\Adapter;
use Noria\Ledger\Statutory\Outcome;
use Noria\Ledger\Statutory\Submission;
use Noria\Ledger\Statutory\SubmissionRequest;
use Noria\Ledger\Storage\ScopedDatabase;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;

final class FixtureStatutoryAdapter implements Adapter
{
    public function __construct(private Closure $send, private ?Closure $lookup = null, private ?Closure $validation = null) {}

    public function name(): string
    {
        return 'fixture.etims';
    }

    public function version(): string
    {
        return '1';
    }

    public function validate(Submission $submission): void
    {
        if ($this->validation !== null) {
            ($this->validation)($submission);
        }
    }

    public function submit(Submission $submission): Outcome
    {
        return ($this->send)($submission);
    }

    public function lookup(Submission $submission): Outcome
    {
        return $this->lookup === null ? new Outcome('uncertain', 'Remote state remains unknown') : ($this->lookup)($submission);
    }
}

beforeEach(function () {
    $this->deliveryConnection = Database::connection();
    $this->scope = new Scope((string) Str::uuid7(), 'statutory-fixture');
    $this->ledger = (new Ledger($this->deliveryConnection, new PermitAll))->for($this->scope);
    $this->deliveryStorage = new ScopedDatabase($this->deliveryConnection, $this->scope, new PermitAll);
    $this->entity = $this->ledger->catalog->entity('Statutory taxpayer', 'KE');
    $this->book = $this->ledger->catalog->book($this->entity, 'Statutory', $this->kes);
    $this->period = $this->ledger->periods->open($this->book, '2026', '2026-01-01', '2026-12-31');
    $this->income = $this->ledger->catalog->account($this->book, '4000', 'Revenue', AccountType::Income);
    $this->receivable = $this->ledger->catalog->account($this->book, '1100', 'Receivables', AccountType::Asset, control: true);
    $this->party = $this->ledger->catalog->party($this->entity, 'customer', 'Customer');
    $this->invoice = $this->ledger->documents->issue(new DocumentRequest($this->book, 'invoice', '2026-01-01', new Source('statutory-fixture', 'INV-1'), DocumentKind::SalesInvoice,
        $this->receivable, [new Obligation($this->party, Money::of('100', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('100', $this->kes), 'Taxpayer supply')]));
});

function deliveryRequest(string $book, string $source, string $key = 'invoice', string $kind = 'invoice', ?string $original = null, ?string $replaces = null,
    string $payload = '{ "invoice": "INV-1", "gross": "100.00" }', int $maxAttempts = 10): SubmissionRequest
{
    return new SubmissionRequest($book, $key, $kind, $source, 'ke.etims.branch-001', 'fixture.etims', '1', 'sandbox', 'request-'.$key, $payload,
        originalId: $original, replacesId: $replaces, maxAttempts: $maxAttempts);
}

it('pins exact wire bytes and dispatches once outside financial transactions', function () {
    $request = deliveryRequest($this->book, $this->invoice->id);
    $id = $this->ledger->statutory->enqueue($request);
    expect($this->ledger->statutory->enqueue($request))->toBe($id);
    $calls = 0;
    $adapter = new FixtureStatutoryAdapter(function (Submission $submission) use (&$calls, $request): Outcome {
        $calls++;
        expect($this->deliveryConnection->transactionLevel())->toBe(0)
            ->and($submission->payload)->toBe($request->payload)->and($submission->payloadHash)->toBe(hash('sha256', $request->payload))
            ->and($submission->requestReference)->toBe('request-invoice')->and($submission->source['gross_minor'])->toBe('10000');

        return new Outcome('accepted', 'Provider accepted the invoice', ['response_code' => 'OK'], 'INV-ACCEPTED-1');
    });
    expect($this->ledger->statutory->dispatch($this->book, $id, $adapter))->not->toBeNull()
        ->and($this->ledger->statutory->dispatch($this->book, $id, $adapter))->toBeNull()->and($calls)->toBe(1);
    $evidence = $this->ledger->statutory->evidence($this->book, $id);
    expect($evidence['state']['status'])->toBe('accepted')->and($evidence['state']['attempts'])->toBe(1)
        ->and($evidence['events'])->toHaveCount(2)->and($evidence['definition']->payload)->toBe($request->payload)
        ->and($this->ledger->reports->generalLedger($this->ledger->reports->snapshot($this->book, '2026-12-31')))->toHaveCount(2);
});

it('retries only definitively unaccepted transport with the same pinned identity and bytes', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $calls = [];
    $adapter = new FixtureStatutoryAdapter(function (Submission $submission) use (&$calls): Outcome {
        $calls[] = [$submission->requestReference, $submission->payloadHash];

        return count($calls) === 1 ? new Outcome('retry', 'No request was transmitted') : new Outcome('accepted', 'Provider accepted retry', receiptReference: 'RETRY-ACCEPTED');
    });
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('retry');
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($calls[0])->toBe($calls[1])->and($this->ledger->statutory->evidence($this->book, $id)['state']['attempts'])->toBe(2);
});

it('looks up uncertain acceptance instead of blindly sending another invoice', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $sends = 0;
    $lookups = 0;
    $adapter = new FixtureStatutoryAdapter(function () use (&$sends): Outcome {
        $sends++;

        return new Outcome('uncertain', 'Response was lost after transmission');
    }, function (Submission $submission) use (&$lookups): Outcome {
        $lookups++;
        expect($submission->mode)->toBe('lookup')->and($submission->requestReference)->toBe('request-invoice');

        return new Outcome('accepted', 'Remote reconciliation found the invoice', receiptReference: 'FOUND-ACCEPTED');
    });
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($sends)->toBe(1)->and($lookups)->toBe(1)->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('accepted');
});

it('permits resend only after authoritative lookup confirms no prior acceptance', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $sends = 0;
    $adapter = new FixtureStatutoryAdapter(function () use (&$sends): Outcome {
        $sends++;

        return $sends === 1 ? new Outcome('uncertain', 'Unknown transmission outcome') : new Outcome('accepted', 'Accepted after absence confirmation', receiptReference: 'AFTER-ABSENCE');
    }, fn (): Outcome => new Outcome('not_found', 'Provider authoritatively confirmed this request was not accepted'));
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($sends)->toBe(1)->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('retry');
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($sends)->toBe(2)->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('accepted');
});

it('expires a worker into lookup and fences the superseded worker outcome', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $first = $this->ledger->statutory->claim($this->book, $id, 'worker-a', 1);
    expect($this->ledger->statutory->claim($this->book, $id, 'worker-a', 1))->toBeNull()
        ->and($this->ledger->statutory->claim($this->book, $id, 'worker-b'))->toBeNull();
    $this->deliveryConnection->select('SELECT pg_sleep(1.05)');
    $second = $this->ledger->statutory->claim($this->book, $id, 'worker-b');
    expect($second->mode)->toBe('lookup');
    try {
        $this->ledger->statutory->resolve($this->book, $id, $first->claimId, 'old-worker-result', new Outcome('accepted', 'Late first worker response', receiptReference: 'STALE-RECEIPT'));
        test()->fail('A superseded worker cannot commit its result.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('superseded');
    }
    $outcome = new Outcome('accepted', 'Lookup confirmed the actual provider receipt', receiptReference: 'CURRENT-RECEIPT');
    $result = $this->ledger->statutory->resolve($this->book, $id, $second->claimId, 'lookup-result', $outcome);
    expect($this->ledger->statutory->resolve($this->book, $id, $second->claimId, 'lookup-result', $outcome))->toBe($result)
        ->and($this->ledger->statutory->evidence($this->book, $id)['state']['receipt_reference'])->toBe('CURRENT-RECEIPT');
});

it('records local validation failures before transmission and requires an immutable replacement', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $sends = 0;
    $adapter = new FixtureStatutoryAdapter(function () use (&$sends): Outcome {
        $sends++;

        return new Outcome('accepted', 'Accepted corrected invoice', receiptReference: 'CORRECTED-ACCEPTED');
    }, validation: fn () => throw new LedgerException('Missing statutory item classification'));
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($sends)->toBe(0)->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('rejected');
    $replacement = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, 'corrected-invoice', replaces: $id, payload: '{"invoice":"INV-1","classification":"reviewed"}'));
    $this->ledger->statutory->dispatch($this->book, $replacement, new FixtureStatutoryAdapter(fn (): Outcome => new Outcome('accepted', 'Accepted replacement', receiptReference: 'REPLACEMENT-ACCEPTED')));
    expect($this->ledger->statutory->evidence($this->book, $replacement)['definition']->replaces_id)->toBe($id)
        ->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('rejected');
});

it('bounds send attempts without turning unknown outcomes into blind retries', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, maxAttempts: 1));
    $this->ledger->statutory->dispatch($this->book, $id, new FixtureStatutoryAdapter(fn (): Outcome => new Outcome('retry', 'Definitely not transmitted')));
    expect($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('exhausted')
        ->and($this->ledger->statutory->claim($this->book, $id, 'over-budget'))->toBeNull()
        ->and($this->ledger->statutory->pending($this->book))->toBe([]);
});

it('links credit notes to accepted invoices and independently caps reserved credits', function () {
    $parent = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $credit = $this->ledger->documents->issue(new DocumentRequest($this->book, 'credit', '2026-01-02', new Source('statutory-fixture', 'CR-1'), DocumentKind::SalesCredit,
        $this->receivable, [new Obligation($this->party, Money::of('70', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('70', $this->kes), 'Partial credit')]));
    try {
        $this->ledger->statutory->enqueue(deliveryRequest($this->book, $credit->id, 'early-credit', 'credit_note', $parent));
        test()->fail('Freeze a credit payload only after original acceptance is known.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('reference its invoice');
    }
    $this->ledger->statutory->dispatch($this->book, $parent, new FixtureStatutoryAdapter(fn (): Outcome => new Outcome('accepted', 'Invoice accepted', receiptReference: 'PARENT-ACCEPTED')));
    $child = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $credit->id, 'credit', 'credit_note', $parent));
    $claim = $this->ledger->statutory->claim($this->book, $child, 'credit-worker');
    expect($claim->originalReceipt)->toBe('PARENT-ACCEPTED')->and($claim->source['gross_minor'])->toBe('7000');
    $other = $this->ledger->documents->issue(new DocumentRequest($this->book, 'other-credit', '2026-01-03', new Source('statutory-fixture', 'CR-2'), DocumentKind::SalesCredit,
        $this->receivable, [new Obligation($this->party, Money::of('40', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('40', $this->kes), 'Other partial credit')]));
    $this->ledger->statutory->enqueue(deliveryRequest($this->book, $other->id, 'over-credit', 'credit_note', $parent));
})->throws(QueryException::class, 'exceed the referenced invoice');

it('blocks fresh delivery of a source reversed after queuing but still permits authoritative lookup', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $claim = $this->ledger->statutory->claim($this->book, $id, 'before-reversal');
    $this->ledger->statutory->resolve($this->book, $id, $claim->claimId, 'unknown-before-reversal', new Outcome('uncertain', 'Request may have been accepted'));
    $this->ledger->journals->reverse($this->book, $this->invoice->id, 'financial-reversal', '2026-01-02', 'Correct source invoice');
    $lookup = $this->ledger->statutory->claim($this->book, $id, 'lookup-reversed-source');
    expect($lookup->mode)->toBe('lookup');
    $this->ledger->statutory->resolve($this->book, $id, $lookup->claimId, 'reversed-source-absence', new Outcome('not_found', 'Authoritative absence'));
    expect($this->ledger->statutory->claim($this->book, $id, 'do-not-resend-reversal'))->toBeNull()
        ->and($this->ledger->statutory->pending($this->book))->toBe([]);
    $this->ledger->statutory->cancel($this->book, $id, 'cancel-reversed-source', 'No provider acceptance and source was corrected');
});

it('treats transport exceptions as uncertain and does not invoke HTTP inside an enclosing transaction', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $calls = 0;
    $adapter = new FixtureStatutoryAdapter(function () use (&$calls): Outcome {
        $calls++;
        throw new RuntimeException('Connection lost after sending');
    });
    $this->deliveryConnection->transaction(function () use ($id, $adapter): void {
        try {
            $this->ledger->statutory->dispatch($this->book, $id, $adapter);
            test()->fail('External delivery cannot precede the outermost commit.');
        } catch (LedgerException $exception) {
            expect($exception->getMessage())->toContain('outermost transaction');
        }
    });
    expect($calls)->toBe(0);
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    $evidence = $this->ledger->statutory->evidence($this->book, $id);
    $event = $evidence['events'][1];
    expect($calls)->toBe(1)->and($evidence['state']['status'])->toBe('uncertain')
        ->and((new DateTimeImmutable($event->available_at))->getTimestamp() - (new DateTimeImmutable($event->recorded_at))->getTimestamp())->toBe(30);
});

it('rejects changed retry content and duplicate source identities without consuming evidence sequences', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $cutoff = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, payload: '{"changed":true}'));
        test()->fail('A retry must retain the exact wire payload.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('different inputs');
    }
    try {
        $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, 'different-key'));
        test()->fail('A new transport key cannot create a second original submission.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('already has a statutory submission');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff)
        ->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('queued');
});

it('cannot cancel an ambiguous request or replace accepted provider evidence', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $claim = $this->ledger->statutory->claim($this->book, $id, 'send-unknown');
    $this->ledger->statutory->resolve($this->book, $id, $claim->claimId, 'record-unknown', new Outcome('uncertain', 'Provider may have accepted'));
    try {
        $this->ledger->statutory->cancel($this->book, $id, 'unsafe-cancel', 'Attempt to discard ambiguity');
        test()->fail('Unknown acceptance must be reconciled before cancellation.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('definitively unsent or unaccepted');
    }
    $lookup = $this->ledger->statutory->claim($this->book, $id, 'resolve-unknown');
    $this->ledger->statutory->resolve($this->book, $id, $lookup->claimId, 'accept-unknown', new Outcome('accepted', 'Authoritative acceptance', receiptReference: 'DO-NOT-REPLACE'));
    $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, 'replace-accepted', replaces: $id));
})->throws(QueryException::class, 'definitively unaccepted submission');

it('retains acceptance after book retirement and preserves exact financial reversal credit links', function () {
    $parent = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $claim = $this->ledger->statutory->claim($this->book, $parent, 'before-retirement');
    $this->ledger->catalog->deleteBook($this->book);
    $this->ledger->statutory->resolve($this->book, $parent, $claim->claimId, 'accepted-after-retirement', new Outcome('accepted', 'Delayed provider receipt', receiptReference: 'ACCEPTED-AFTER-RETIREMENT'));
    expect($this->ledger->statutory->evidence($this->book, $parent)['state']['status'])->toBe('accepted');
    $this->ledger->catalog->restoreBook($this->book);
    $reversal = $this->ledger->journals->reverse($this->book, $this->invoice->id, 'full-correction', '2026-01-02', 'Cancel the original financial invoice');
    $credit = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $reversal->id, 'exact-credit', 'credit_note', $parent));
    $creditClaim = $this->ledger->statutory->claim($this->book, $credit, 'exact-credit-worker');
    expect($creditClaim->originalReceipt)->toBe('ACCEPTED-AFTER-RETIREMENT')->and($creditClaim->source['reverses_id'])->toBe($this->invoice->id);
});

it('enqueues atomically with source work and rejects the wrong adapter before any grant', function () {
    $before = $this->ledger->events->cutoff($this->book);
    try {
        $this->deliveryConnection->transaction(function (): void {
            $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
            throw new RuntimeException('Roll back the host transaction');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Roll back the host transaction');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($before)->and($this->ledger->statutory->pending($this->book))->toBe([]);
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $this->ledger->statutory->claim($this->book, $id, 'wrong-adapter', adapter: 'some.other.provider', adapterVersion: '1');
})->throws(LedgerException::class, 'pinned submission');

it('independently rejects unclaimed provider receipts and immutable-payload edits', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    try {
        $this->deliveryStorage->run(Permission::Deliver, function () use ($id): void {
            $this->deliveryStorage->insert('ledger_statutory_events', ['book_id' => $this->book, 'submission_id' => $id, 'idempotency_key' => 'forged-acceptance',
                'fingerprint' => str_repeat('a', 64), 'kind' => 'accepted', 'receipt_reference' => 'FORGED', 'evidence' => '{}', 'reason' => 'Forged receipt without a worker claim']);
        }, $this->book);
        test()->fail('Provider receipt insertion must retain the worker claim chain.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('current worker claim');
    }
    $this->deliveryStorage->run(Permission::Deliver, fn () => $this->deliveryStorage->table('ledger_statutory_submissions')->where('id', $id)->update(['payload' => '{"altered":true}']), $this->book);
})->throws(QueryException::class, 'append-only');

function statutoryReturnFixture(Session $ledger, string $book): array
{
    $configuration = $ledger->configurations->install($book, new Pack('delivery-return', '1', accounts: [['code' => '2200', 'name' => 'Output tax', 'type' => 'liability']],
        roles: ['tax.output' => '2200'], settings: ['tax_returns' => ['vat' => ['accounts' => ['tax.output'], 'codes' => ['EXAMPLE' => []],
            'payable_terms' => ['output_tax' => '1'], 'reconciliations' => ['settlement' => ['roles' => ['tax.output'], 'coefficient' => '0']]]]]));
    $return = $ledger->taxes->prepare($ledger->reports->snapshot($book, '2026-01-31'), 'delivery-tax-return', $configuration, 'vat', '2026-01-01');
    $ledger->taxes->finalize($book, $return, 'delivery-tax-finalize', '2026-02-01', 'Reviewed return for delivery');

    return [$configuration, $return];
}

it('pins approved tax return evidence without confusing transport acceptance with actual filing', function () {
    [, $return] = statutoryReturnFixture($this->ledger, $this->book);
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $return, 'return-submission', 'tax_return', payload: '{"return":"VAT-2026-01"}'));
    $this->ledger->statutory->dispatch($this->book, $id, new FixtureStatutoryAdapter(function (Submission $submission) use ($return): Outcome {
        expect($submission->source['return_id'])->toBe($return)->and($submission->source['result_hash'])->toBe($this->ledger->taxes->evidence($this->book, $return)['result_hash']);

        return new Outcome('accepted', 'Transport accepted the pinned artifact', receiptReference: 'ARTIFACT-ACCEPTED');
    }));
    expect($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('accepted')
        ->and($this->ledger->taxes->evidence($this->book, $return)['status'])->toBe('finalized');
});

it('does not transmit a finalized return that became stale while queued', function () {
    [$configuration, $return] = statutoryReturnFixture($this->ledger, $this->book);
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $return, 'stale-return-submission', 'tax_return', payload: '{"return":"VAT-2026-01"}'));
    $tax = $this->ledger->configurations->account($this->book, $configuration, 'tax.output');
    $this->ledger->journals->post(new JournalRequest($this->book, 'late-tax-entry', '2026-01-31', 'Late tax adjustment', [
        new Posting($this->receivable, Side::Debit, Money::of('1', $this->kes), partyId: $this->party), new Posting($tax, Side::Credit, Money::of('1', $this->kes)),
    ]));
    expect($this->ledger->statutory->claim($this->book, $id, 'do-not-send-stale-return'))->toBeNull()
        ->and($this->ledger->statutory->pending($this->book))->toBe([]);
});

it('requires delivery permission independently of financial read access', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $denied = (new Ledger($this->deliveryConnection, new class implements Authorizer
    {
        public function allows(Scope $scope, Permission $permission, ?string $bookId = null): bool
        {
            return $permission !== Permission::Deliver;
        }
    }))->for($this->scope);
    expect($denied->statutory->evidence($this->book, $id)['state']['status'])->toBe('queued');
    $denied->statutory->claim($this->book, $id, 'unauthorized-worker');
})->throws(LedgerException::class, 'ledger.deliver');
