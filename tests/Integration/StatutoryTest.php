<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
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
use Noria\Ledger\Tax\Kenya\EtimsAdapter;
use Noria\Ledger\Tax\Kenya\EtimsClient;
use Noria\Ledger\Tax\Kenya\EtimsDevice;
use Noria\Ledger\Tax\Kenya\EtimsSale;
use Noria\Ledger\Tests\Support\Database;
use Noria\Ledger\Tests\Support\PermitAll;

final class FixtureStatutoryAdapter implements Adapter
{
    /**
     * @param  Closure(Submission): Outcome  $send
     * @param  (Closure(Submission): Outcome)|null  $lookup
     * @param  (Closure(Submission): void)|null  $validation
     */
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
    $first = $this->ledger->statutory->claim($this->book, $id, 'worker-a', 1) ?? throw new RuntimeException('The submission could not be claimed.');
    expect($this->ledger->statutory->claim($this->book, $id, 'worker-a', 1))->toBeNull()
        ->and($this->ledger->statutory->claim($this->book, $id, 'worker-b'))->toBeNull();
    $this->deliveryConnection->select('SELECT pg_sleep(1.05)');
    $second = $this->ledger->statutory->claim($this->book, $id, 'worker-b') ?? throw new RuntimeException('The submission could not be claimed.');
    expect($second->mode)->toBe('lookup');
    try {
        $this->ledger->statutory->resolve($this->book, $id, $first->claimId, 'old-worker-result', new Outcome('accepted', 'Late first worker response', receiptReference: 'STALE-RECEIPT'));
        $this->fail('A superseded worker cannot commit its result.');
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
        $this->fail('Freeze a credit payload only after original acceptance is known.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('reference its invoice');
    }
    $this->ledger->statutory->dispatch($this->book, $parent, new FixtureStatutoryAdapter(fn (): Outcome => new Outcome('accepted', 'Invoice accepted', receiptReference: 'PARENT-ACCEPTED')));
    $child = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $credit->id, 'credit', 'credit_note', $parent));
    $claim = $this->ledger->statutory->claim($this->book, $child, 'credit-worker') ?? throw new RuntimeException('The submission could not be claimed.');
    expect($claim->originalReceipt)->toBe('PARENT-ACCEPTED')->and($claim->source['gross_minor'])->toBe('7000');
    $other = $this->ledger->documents->issue(new DocumentRequest($this->book, 'other-credit', '2026-01-03', new Source('statutory-fixture', 'CR-2'), DocumentKind::SalesCredit,
        $this->receivable, [new Obligation($this->party, Money::of('40', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('40', $this->kes), 'Other partial credit')]));
    $this->ledger->statutory->enqueue(deliveryRequest($this->book, $other->id, 'over-credit', 'credit_note', $parent));
})->throws(QueryException::class, 'exceed the referenced invoice');

it('blocks fresh delivery of a source reversed after queuing but still permits authoritative lookup', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $claim = $this->ledger->statutory->claim($this->book, $id, 'before-reversal') ?? throw new RuntimeException('The submission could not be claimed.');
    $this->ledger->statutory->resolve($this->book, $id, $claim->claimId, 'unknown-before-reversal', new Outcome('uncertain', 'Request may have been accepted'));
    $this->ledger->journals->reverse($this->book, $this->invoice->id, 'financial-reversal', '2026-01-02', 'Correct source invoice');
    $lookup = $this->ledger->statutory->claim($this->book, $id, 'lookup-reversed-source') ?? throw new RuntimeException('The submission could not be claimed.');
    expect($lookup->mode)->toBe('lookup');
    $this->ledger->statutory->resolve($this->book, $id, $lookup->claimId, 'reversed-source-absence', new Outcome('not_found', 'Authoritative absence'));
    expect($this->ledger->statutory->claim($this->book, $id, 'do-not-resend-reversal'))->toBeNull()
        ->and($this->ledger->statutory->pending($this->book))->toBe([]);
    $cancellation = $this->ledger->statutory->cancel($this->book, $id, 'cancel-reversed-source', 'No provider acceptance and source was corrected');
    expect($this->ledger->statutory->cancel($this->book, $id, 'cancel-reversed-source', 'No provider acceptance and source was corrected'))->toBe($cancellation);
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
            $this->fail('External delivery cannot precede the outermost commit.');
        } catch (LedgerException $exception) {
            expect($exception->getMessage())->toContain('outermost transaction');
        }
    });
    expect($calls)->toBe(0);
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    $evidence = $this->ledger->statutory->evidence($this->book, $id);
    $event = $evidence['events'][1];
    expect($calls)->toBe(1)->and($evidence['state']['status'])->toBe('uncertain')
        ->and((new DateTimeImmutable(text($event->available_at)))->getTimestamp() - (new DateTimeImmutable(text($event->recorded_at)))->getTimestamp())->toBe(30);
});

it('rejects changed retry content and duplicate source identities without consuming evidence sequences', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $cutoff = $this->ledger->events->cutoff($this->book);
    try {
        $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, payload: '{"changed":true}'));
        $this->fail('A retry must retain the exact wire payload.');
    } catch (LedgerException $exception) {
        expect($exception->getMessage())->toContain('different inputs');
    }
    try {
        $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, 'different-key'));
        $this->fail('A new transport key cannot create a second original submission.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('already has a statutory submission');
    }
    expect($this->ledger->events->cutoff($this->book))->toBe($cutoff)
        ->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('queued');
});

it('cannot cancel an ambiguous request or replace accepted provider evidence', function () {
    $id = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $claim = $this->ledger->statutory->claim($this->book, $id, 'send-unknown') ?? throw new RuntimeException('The submission could not be claimed.');
    $this->ledger->statutory->resolve($this->book, $id, $claim->claimId, 'record-unknown', new Outcome('uncertain', 'Provider may have accepted'));
    try {
        $this->ledger->statutory->cancel($this->book, $id, 'unsafe-cancel', 'Attempt to discard ambiguity');
        $this->fail('Unknown acceptance must be reconciled before cancellation.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('definitively unsent or unaccepted');
    }
    $lookup = $this->ledger->statutory->claim($this->book, $id, 'resolve-unknown') ?? throw new RuntimeException('The submission could not be claimed.');
    $this->ledger->statutory->resolve($this->book, $id, $lookup->claimId, 'accept-unknown', new Outcome('accepted', 'Authoritative acceptance', receiptReference: 'DO-NOT-REPLACE'));
    $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id, 'replace-accepted', replaces: $id));
})->throws(QueryException::class, 'definitively unaccepted submission');

it('retains acceptance after book retirement and preserves exact financial reversal credit links', function () {
    $parent = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $this->invoice->id));
    $claim = $this->ledger->statutory->claim($this->book, $parent, 'before-retirement') ?? throw new RuntimeException('The submission could not be claimed.');
    $this->ledger->catalog->deleteBook($this->book);
    $this->ledger->statutory->resolve($this->book, $parent, $claim->claimId, 'accepted-after-retirement', new Outcome('accepted', 'Delayed provider receipt', receiptReference: 'ACCEPTED-AFTER-RETIREMENT'));
    expect($this->ledger->statutory->evidence($this->book, $parent)['state']['status'])->toBe('accepted');
    $this->ledger->catalog->restoreBook($this->book);
    $reversal = $this->ledger->journals->reverse($this->book, $this->invoice->id, 'full-correction', '2026-01-02', 'Cancel the original financial invoice');
    $credit = $this->ledger->statutory->enqueue(deliveryRequest($this->book, $reversal->id, 'exact-credit', 'credit_note', $parent));
    $creditClaim = $this->ledger->statutory->claim($this->book, $credit, 'exact-credit-worker') ?? throw new RuntimeException('The submission could not be claimed.');
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
        $this->fail('Provider receipt insertion must retain the worker claim chain.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('current worker claim');
    }
    $this->deliveryStorage->run(Permission::Deliver, fn () => $this->deliveryStorage->table('ledger_statutory_submissions')->where('id', $id)->update(['payload' => '{"altered":true}']), $this->book);
})->throws(QueryException::class, 'append-only');

/** @return array{string, string} */
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

function etimsDevice(string $branch = '00'): EtimsDevice
{
    return new EtimsDevice('P051234567A', $branch, 'sandbox-communication-key');
}

/**
 * @param  list<Request>  $sent
 * @param  list<array<string, mixed>>  $responses
 */
function etimsAdapter(array &$sent, array $responses, string $branch = '00'): EtimsAdapter
{
    $http = new Factory;
    $http->fake(function (Request $request) use (&$sent, &$responses) {
        $sent[] = $request;

        return Factory::response(array_shift($responses) ?? ['resultCd' => '999', 'resultMsg' => 'Unexpected call']);
    });

    return new EtimsAdapter(new EtimsClient($http, etimsDevice($branch)));
}

function etimsRequest(string $book, string $source, EtimsSale $sale, string $key = 'etims-invoice', string $kind = 'invoice', ?string $original = null, string $branch = '00', ?string $replaces = null): SubmissionRequest
{
    return new SubmissionRequest($book, $key, $kind, $source, 'ke.etims.P051234567A-00', 'kra.etims.oscu', 'oscu-2.0', 'sandbox', 'etims-'.$key, $sale->json(etimsDevice($branch)),
        originalId: $original, replacesId: $replaces);
}

function etimsInvoice(int $number = 1): EtimsSale
{
    return EtimsSale::invoice($number, 'INV-'.$number, '2026-01-01 10:15:00', [
        ['code' => 'KE1NTXU0000001', 'class_code' => '5020230000', 'name' => 'Standard supply', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '2', 'price' => '116', 'tax_type' => 'B'],
        ['code' => 'KE1NTXU0000002', 'class_code' => '5020230000', 'name' => 'Exempt supply', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '1', 'price' => '50', 'tax_type' => 'A'],
        ['code' => 'KE1NTXU0000003', 'class_code' => '5020230000', 'name' => 'Reduced supply', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '1', 'price' => '100', 'discount_rate' => '10', 'tax_type' => 'E'],
    ], 'A123456789B', 'Registered customer', '06');
}

it('builds OSCU sale payloads with tax-inclusive per-type totals and discounts', function () {
    $payload = etimsInvoice()->payload(etimsDevice());
    expect($payload['tin'])->toBe('P051234567A')->and($payload['bhfId'])->toBe('00')->and($payload['rcptTyCd'])->toBe('S')->and($payload['pmtTyCd'])->toBe('06')
        ->and($payload['cfmDt'])->toBe('20260101101500')->and($payload['salesDt'])->toBe('20260101')->and($payload['totItemCnt'])->toBe(3)
        ->and($payload['taxblAmtA'])->toBe(50)->and($payload['taxblAmtB'])->toBe(232)->and($payload['taxblAmtE'])->toBe(90)
        ->and($payload['taxRtB'])->toBe(16)->and($payload['taxRtE'])->toBe(8)
        ->and($payload['taxAmtB'])->toBe(32)->and($payload['taxAmtE'])->toBe(6.67)->and($payload['taxAmtA'])->toBe(0)
        ->and($payload['totTaxblAmt'])->toBe(372)->and($payload['totTaxAmt'])->toBe(38.67)->and($payload['totAmt'])->toBe(372)
        ->and(data_get($payload, 'itemList.2'))->toMatchArray(['itemSeq' => 3, 'splyAmt' => 100, 'dcRt' => 10, 'dcAmt' => 10, 'taxblAmt' => 90, 'taxAmt' => 6.67, 'taxTyCd' => 'E'])
        ->and(data_get($payload, 'receipt.custTin'))->toBe('A123456789B')->and($payload)->not->toHaveKey('cmcKey');
    $credit = EtimsSale::creditNote(2, 'CR-1', 1, '06', '2026-01-02 09:00:00', [
        ['code' => 'KE1NTXU0000001', 'class_code' => '5020230000', 'name' => 'Standard supply', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '1', 'price' => '116', 'tax_type' => 'B'],
    ])->payload(etimsDevice());
    expect($credit['rcptTyCd'])->toBe('R')->and($credit['orgInvcNo'])->toBe(1)->and($credit['rfdRsnCd'])->toBe('06')->and($credit['rfdDt'])->toBe('20260102090000')->and($credit['taxAmtB'])->toBe(16);
    expect(fn () => EtimsSale::creditNote(3, 'CR-2', 0, '06', '2026-01-02 09:00:00', [['tax_type' => 'B']]))->toThrow(LedgerException::class, 'original invoice number')
        ->and(fn () => EtimsSale::invoice(4, 'INV-4', '2026-01-02', [['tax_type' => 'B']]))->toThrow(LedgerException::class, 'Y-m-d H:i:s')
        ->and(fn () => EtimsSale::invoice(5, 'INV-5', '2026-01-02 09:00:00', [['code' => 'X', 'class_code' => 'Y', 'name' => 'Z', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '1', 'price' => '1', 'tax_type' => 'Q']]))
        ->toThrow(LedgerException::class, 'tax type')
        ->and(fn () => new EtimsDevice('P05123', '00', 'key'))->toThrow(LedgerException::class);
});

it('submits eTIMS sales through the statutory pipeline and pins the signed receipt', function () {
    $sent = [];
    $adapter = etimsAdapter($sent, [['resultCd' => '000', 'resultMsg' => 'It is succeeded', 'resultDt' => '20260101101502',
        'data' => ['curRcptNo' => 5, 'totRcptNo' => 9, 'intrlData' => 'INTERNALDATA', 'rcptSign' => 'SIGNATURE', 'sdcDateTime' => '20260101101502']]]);
    $id = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice()));
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    $evidence = $this->ledger->statutory->evidence($this->book, $id);
    expect($evidence['state']['status'])->toBe('accepted')->and($sent)->toHaveCount(1)
        ->and($sent[0]->url())->toBe('https://etims-api-sbx.kra.go.ke/etims-api/saveTrnsSalesOsdc')
        ->and($sent[0]->header('cmcKey'))->toBe(['sandbox-communication-key'])->and($sent[0]->header('bhfId'))->toBe(['00'])
        ->and($sent[0]->data()['invcNo'])->toBe(1)->and($sent[0]->data()['taxAmtB'])->toBe(32);
    expect(json_encode($evidence))->toContain('P051234567A-00-1-5')->toContain('SIGNATURE');
});

it('confirms an eTIMS duplicate by lookup instead of reporting a second sale', function () {
    $sent = [];
    $adapter = etimsAdapter($sent, [['resultCd' => '994', 'resultMsg' => 'Overlapped data'], ['resultCd' => '994', 'resultMsg' => 'Overlapped data']]);
    $id = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice()));
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    expect($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('uncertain');
    $this->ledger->statutory->dispatch($this->book, $id, $adapter);
    $evidence = $this->ledger->statutory->evidence($this->book, $id);
    expect($evidence['state']['status'])->toBe('accepted')->and($sent)->toHaveCount(2)->and(json_encode($evidence))->toContain('P051234567A-00-1');
});

it('maps eTIMS validation codes to rejection, rate limits to retry and unknown faults to lookup', function () {
    $sent = [];
    $id = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice()));
    $this->ledger->statutory->dispatch($this->book, $id, etimsAdapter($sent, [['resultCd' => '910', 'resultMsg' => 'Request parameter error']]));
    $evidence = $this->ledger->statutory->evidence($this->book, $id);
    expect($evidence['state']['status'])->toBe('rejected')->and(json_encode($evidence))->toContain('Request parameter error');
    $retry = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice(2), 'etims-corrected', replaces: $id));
    $this->ledger->statutory->dispatch($this->book, $retry, etimsAdapter($sent, [['resultCd' => '990', 'resultMsg' => 'Maximum views exceeded']]));
    expect($this->ledger->statutory->evidence($this->book, $retry)['state']['status'])->toBe('retry');
    $second = $this->ledger->documents->issue(new DocumentRequest($this->book, 'etims-second', '2026-01-01', new Source('statutory-fixture', 'INV-3'), DocumentKind::SalesInvoice,
        $this->receivable, [new Obligation($this->party, Money::of('100', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('100', $this->kes), 'Second supply')]));
    $unknown = $this->ledger->statutory->enqueue(etimsRequest($this->book, $second->id, etimsInvoice(3), 'etims-unknown'));
    $this->ledger->statutory->dispatch($this->book, $unknown, etimsAdapter($sent, [['resultCd' => '999', 'resultMsg' => 'Unknown error']]));
    expect($this->ledger->statutory->evidence($this->book, $unknown)['state']['status'])->toBe('uncertain');
});

it('refuses eTIMS payloads for another branch and sends credit notes against the accepted invoice', function () {
    $sent = [];
    $id = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice(), branch: '01'));
    $this->ledger->statutory->dispatch($this->book, $id, etimsAdapter($sent, []));
    expect($sent)->toBe([])->and($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('rejected');
    $parent = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice(7), 'etims-parent', replaces: $id));
    $this->ledger->statutory->dispatch($this->book, $parent, etimsAdapter($sent, [['resultCd' => '000', 'resultMsg' => 'It is succeeded', 'data' => ['curRcptNo' => 1]]]));
    $credit = $this->ledger->documents->issue(new DocumentRequest($this->book, 'etims-credit', '2026-01-02', new Source('statutory-fixture', 'CR-ETIMS'), DocumentKind::SalesCredit,
        $this->receivable, [new Obligation($this->party, Money::of('50', $this->kes), '2026-01-31')], [new Charge($this->income, Money::of('50', $this->kes), 'Returned goods')]));
    $note = EtimsSale::creditNote(8, 'CR-ETIMS', 7, '06', '2026-01-02 09:00:00', [
        ['code' => 'KE1NTXU0000001', 'class_code' => '5020230000', 'name' => 'Standard supply', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '1', 'price' => '58', 'tax_type' => 'B'],
    ]);
    $child = $this->ledger->statutory->enqueue(etimsRequest($this->book, $credit->id, $note, 'etims-credit', 'credit_note', $parent));
    $this->ledger->statutory->dispatch($this->book, $child, etimsAdapter($sent, [['resultCd' => '000', 'resultMsg' => 'It is succeeded', 'data' => ['curRcptNo' => 2]]]));
    $last = end($sent) ?: throw new RuntimeException('No eTIMS request was sent.');
    expect($this->ledger->statutory->evidence($this->book, $child)['state']['status'])->toBe('accepted')
        ->and($last->data()['rcptTyCd'])->toBe('R')->and($last->data()['orgInvcNo'])->toBe(7);
});

it('calls every OSCU endpoint with the device identity', function () {
    $sent = [];
    $http = new Factory;
    $http->fake(function (Request $request) use (&$sent) {
        $sent[] = $request;

        return Factory::response(['resultCd' => '000', 'resultMsg' => 'It is succeeded', 'data' => ['info' => ['cmcKey' => 'issued-key']]]);
    });
    $client = new EtimsClient($http, new EtimsDevice('P051234567A', '00', 'production-key', 'production'));
    expect(data_get($client->initialize('SERIAL-1'), 'data.info.cmcKey'))->toBe('issued-key');
    $client->codes();
    $client->itemClassifications();
    $client->customer('A123456789B');
    $client->notices();
    $client->branches();
    $client->items();
    $client->saveItem(['itemCd' => 'KE1NTXU0000001']);
    $client->saveBranchCustomer(['custNo' => '1']);
    $client->saveBranchUser(['userId' => 'clerk']);
    $client->importedItems();
    $client->updateImportedItem(['taskCd' => '1']);
    $client->saveSale(['invcNo' => 1]);
    $client->purchases('20260101000000');
    $client->savePurchase(['invcNo' => 1]);
    $client->stockMovements();
    $client->saveStockMovement(['sarNo' => 1, 'sarTyCd' => '11', 'itemList' => []]);
    $client->saveStockMaster(['itemCd' => 'KE1NTXU0000001', 'rsdQty' => 4]);
    expect(array_map(fn (Request $request): string => Str::after($request->url(), 'https://etims-api.kra.go.ke/etims-api/'), $sent))->toBe([
        'selectInitOsdcInfo', 'selectCodeList', 'selectItemClsList', 'selectCustomer', 'selectNoticeList', 'selectBhfList', 'selectItemList', 'saveItem', 'saveBhfCustomer',
        'saveBhfUser', 'selectImportItemList', 'updateImportItem', 'saveTrnsSalesOsdc', 'selectTrnsPurchaseSalesList', 'insertTrnsPurchase', 'selectStockMoveList', 'insertStockIO', 'saveStockMaster',
    ])->and($sent[0]->hasHeader('cmcKey'))->toBeFalse()->and($sent[0]->data())->toBe(['tin' => 'P051234567A', 'bhfId' => '00', 'dvcSrlNo' => 'SERIAL-1'])
        ->and(data_get($sent[1]->data(), 'lastReqDt'))->toBe('20180101000000')->and(data_get($sent[3]->data(), 'custmTin'))->toBe('A123456789B')
        ->and($sent[16]->header('cmcKey'))->toBe(['production-key'])->and(data_get($sent[16]->data(), 'sarTyCd'))->toBe('11')->and(data_get($sent[17]->data(), 'tin'))->toBe('P051234567A');
    $failing = new Factory;
    $failing->fake(fn () => Factory::response('Bad gateway', 502));
    expect(fn () => (new EtimsClient($failing, etimsDevice()))->codes())->toThrow(LedgerException::class, 'HTTP 502');
});

it('rejects malformed eTIMS payloads before transmission and keeps a lookup retry uncertain', function () {
    $sent = [];
    $payload = etimsInvoice()->payload(etimsDevice());
    $missing = $payload;
    unset($missing['totAmt']);
    $adapter = etimsAdapter($sent, []);
    $submission = fn (string $bytes): Submission => new Submission($this->scope->tenantId, $this->book, (string) Str::uuid7(), (string) Str::uuid7(), 'send', 'ke.etims.P051234567A-00',
        'sandbox', 'kra.etims.oscu', 'oscu-2.0', 'etims-malformed', 'application/json', $bytes, hash('sha256', $bytes), hash('sha256', 'source'), [], null, 0);
    expect(fn () => $adapter->validate($submission(json_encode($missing, JSON_THROW_ON_ERROR))))->toThrow(LedgerException::class, 'The eTIMS payload is missing totAmt.')
        ->and(fn () => $adapter->validate($submission('[]')))->toThrow(LedgerException::class, 'An eTIMS payload must be a JSON object.')
        ->and(fn () => $adapter->validate($submission(json_encode([...$payload, 'rcptTyCd' => 'R'], JSON_THROW_ON_ERROR))))->toThrow(LedgerException::class, 'must reference its accepted original invoice');

    $rejected = $this->ledger->statutory->enqueue(new SubmissionRequest($this->book, 'etims-missing', 'invoice', $this->invoice->id, 'ke.etims.P051234567A-00', 'kra.etims.oscu', 'oscu-2.0',
        'sandbox', 'etims-missing', json_encode($missing, JSON_THROW_ON_ERROR)));
    $this->ledger->statutory->dispatch($this->book, $rejected, $adapter);
    expect($this->ledger->statutory->evidence($this->book, $rejected)['state']['status'])->toBe('rejected')->and($sent)->toBe([]);

    $id = $this->ledger->statutory->enqueue(etimsRequest($this->book, $this->invoice->id, etimsInvoice(), 'etims-lookup', replaces: $rejected));
    expect(array_map(fn (stdClass $row): mixed => $row->id, $this->ledger->statutory->pending($this->book)))->toBe([$id]);
    $lookup = etimsAdapter($sent, [['resultCd' => '994', 'resultMsg' => 'Overlapped data'], ['resultCd' => '990', 'resultMsg' => 'Maximum views exceeded']]);
    $this->ledger->statutory->dispatch($this->book, $id, $lookup);
    $this->ledger->statutory->dispatch($this->book, $id, $lookup);
    expect($this->ledger->statutory->evidence($this->book, $id)['state']['status'])->toBe('uncertain')->and($sent)->toHaveCount(2);
});

it('serializes eTIMS sale inputs, validates stock release times and rejects unknown client endpoints', function () {
    $items = [['code' => 'KE1NTXU0000001', 'class_code' => '5020230000', 'name' => 'Standard supply', 'package_unit' => 'NT', 'quantity_unit' => 'U', 'quantity' => '1', 'price' => '116', 'tax_type' => 'B']];
    $sale = EtimsSale::invoice(9, 'INV-9', '2026-01-01 10:15:00', $items, stockReleasedAt: '2026-01-01 10:20:00');
    expect(json_decode(json_encode($sale, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))->toMatchArray(['invoice_number' => 9, 'trader_invoice_number' => 'INV-9', 'receipt_type' => 'S'])
        ->and(fn () => EtimsSale::invoice(10, 'INV-10', '2026-01-01 10:15:00', $items, stockReleasedAt: '2026-01-01'))->toThrow(LedgerException::class, 'Y-m-d H:i:s')
        ->and(fn () => (new EtimsClient(new Factory, etimsDevice()))->post('save-sale', []))->toThrow(LedgerException::class, 'Unknown eTIMS endpoint.');
});
