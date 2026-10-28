<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Noria\Ledger\Access\Permission;
use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\JournalRequest;
use Noria\Ledger\Accounting\Posting;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency;
use Noria\Ledger\Money\ExchangeRate;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Money\RateType;
use Noria\Ledger\Money\Rounding;

beforeEach(function () {
    $this->usd = new Currency('USD', 2);
    $this->usdBank = $this->ledger->catalog->account($this->book, '1010', 'USD bank', AccountType::Asset, $this->usd, cashFlow: 'cash');
});

it('records dated rates by type and finds the latest rate on or before a date', function () {
    $this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130.00', 'CBK');
    $this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-05', '131.25', 'CBK');
    $this->ledger->rates->record($this->usd, $this->kes, RateType::Closing, '2026-01-31', '132', 'CBK');

    $found = $this->ledger->rates->find($this->usd, $this->kes, RateType::Spot, '2026-01-10');
    expect($found->rate)->toBe('131.25')
        ->and($found->asOf)->toBe('2026-01-05')
        ->and($found->source)->toStartWith('ledger-rate:')
        ->and($this->ledger->rates->find($this->usd, $this->kes, RateType::Closing, '2026-02-15')->rate)->toBe('132')
        ->and($this->ledger->rates->get(substr($found->source, 12))->source)->toBe('CBK')
        ->and(fn () => $this->ledger->rates->find($this->usd, $this->kes, RateType::Spot, '2025-12-31'))->toThrow(LedgerException::class, 'No spot rate')
        ->and(fn () => $this->ledger->rates->find($this->usd, $this->kes, RateType::Spot, '2026-01-10', maxAgeDays: 3))->toThrow(LedgerException::class, 'older than 3 days')
        ->and(fn () => $this->ledger->rates->find($this->usd, $this->kes, RateType::Average, '2026-01-10'))->toThrow(LedgerException::class, 'No average rate');
});

it('replays identical rates and requires explicit, audited corrections', function () {
    $first = $this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130.000', 'CBK');
    expect($this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130', 'CBK')->id)->toBe($first->id)
        ->and(fn () => $this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-01', '131', 'CBK'))->toThrow(LedgerException::class, 'correct it explicitly');

    $corrected = $this->ledger->rates->correct($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130.5', 'CBK', 'Published correction');
    expect($corrected->revision)->toBe(2)
        ->and($this->ledger->rates->correct($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130.5', 'CBK', 'Published correction')->id)->toBe($corrected->id)
        ->and($this->ledger->rates->find($this->usd, $this->kes, RateType::Spot, '2026-01-01')->rate)->toBe('130.5')
        ->and(array_map(fn ($rate) => $rate->rate, $this->ledger->rates->history($this->usd, $this->kes, RateType::Spot, '2026-01-01', '2026-01-31')))->toBe(['130.5'])
        ->and(array_map(fn ($rate) => $rate->revision, $this->ledger->rates->history($this->usd, $this->kes, RateType::Spot, '2026-01-01', '2026-01-31', includeCorrected: true)))->toBe([1, 2])
        ->and(fn () => $this->ledger->rates->correct($this->usd, $this->kes, RateType::Spot, '2026-02-01', '1', 'CBK', 'Nothing to correct'))->toThrow(LedgerException::class, 'no recorded rate');
});

it('posts with stored rate evidence and rejects stale, forged or corrected stored rates', function () {
    $stored = $this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130', 'CBK');
    $post = fn (string $key, ExchangeRate $rate) => $this->ledger->journals->post(new JournalRequest($this->book, $key, '2026-01-02', 'USD receipt', [
        new Posting($this->usdBank, Side::Debit, Money::of('10', $this->usd), $rate),
        new Posting($this->income, Side::Credit, Money::of('1300', $this->kes)),
    ]));
    $journal = $post('stored', $this->ledger->rates->find($this->usd, $this->kes, RateType::Spot, '2026-01-02'));
    expect($this->ledger->journals->lines($this->book, $journal->id)[0]->rateSource)->toBe('ledger-rate:'.$stored->id);

    $forged = new ExchangeRate($this->usd, $this->kes, '130', '2026-01-01', 'ledger-rate:'.$stored->id, Rounding::HalfEven);
    expect(fn () => $post('forged', new ExchangeRate($this->usd, $this->kes, '131', '2026-01-01', 'ledger-rate:'.$stored->id, Rounding::HalfEven)))
        ->toThrow(QueryException::class, 'does not match the posting');
    $this->ledger->rates->correct($this->usd, $this->kes, RateType::Spot, '2026-01-01', '129', 'CBK', 'Typo');
    expect(fn () => $post('stale', $forged))->toThrow(QueryException::class, 'corrected exchange rate');
    expect($this->ledger->journals->reverse($this->book, $journal->id, 'reverse-stored', '2026-01-03', 'Correct the rate')->reversesId)->toBe($journal->id);
});

it('rejects direct edits of stored rates', function () {
    $stored = $this->ledger->rates->record($this->usd, $this->kes, RateType::Spot, '2026-01-01', '130', 'CBK');
    $this->storage->run(Permission::Configure, fn () => $this->storage->table('ledger_exchange_rates')->where('id', $stored->id)->update(['rate' => '1']));
})->throws(QueryException::class, 'append-only');
