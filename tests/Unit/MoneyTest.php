<?php

declare(strict_types=1);

use Brick\Math\BigRational;
use Brick\Math\Exception\RoundingNecessaryException;
use NoriaLabs\Ledger\Exceptions\LedgerException;
use NoriaLabs\Ledger\Money\Currency;
use NoriaLabs\Ledger\Money\ExchangeRate;
use NoriaLabs\Ledger\Money\Money;
use NoriaLabs\Ledger\Money\Rounding;
use NoriaLabs\Ledger\Support\Canonical;

it('retains amounts larger than native integer and float precision', function () {
    $currency = new Currency('KES', 2);
    $amount = Money::of('900719925474099312345.67', $currency);
    expect($amount->plus(Money::of('0.01', $currency))->decimal())->toBe('900719925474099312345.68');
    expect(json_encode($amount))->toBe('{"amount":"900719925474099312345.67","currency":"KES","scale":2}');
});

it('supports zero, three, and twelve decimal currencies', function (string $code, int $scale, string $amount) {
    expect(Money::of($amount, new Currency($code, $scale))->decimal())->toBe($amount);
})->with([['JPY', 0, '123'], ['KWD', 3, '123.456'], ['TOKEN', 12, '0.000000000001']]);

it('rejects silent rounding', function () {
    Money::of('1.001', new Currency('KES', 2));
})->throws(RoundingNecessaryException::class);

it('rejects non-decimal amount encodings', function (string $amount) {
    Money::of($amount, new Currency('KES', 2));
})->with(['1e3', 'NaN', '1,000.00', ' 1.00', '.5', '1.'])->throws(LedgerException::class);

it('rejects floats at the public boundary', function () {
    Money::of(1.01, new Currency('KES', 2));
})->throws(TypeError::class);

it('requires currency and scale equality', function () {
    Money::of('1', new Currency('KES', 2))->plus(Money::of('1', new Currency('KES', 3)));
})->throws(LedgerException::class);

it('loads official currency precision from Brick Money', function () {
    expect(Currency::iso('JPY')->scale)->toBe(0);
    expect(Currency::iso('KWD')->scale)->toBe(3);
    expect(Currency::iso('KES')->scale)->toBe(2);
});

it('rejects storage overflow', function () {
    Money::minor(str_repeat('9', 38), new Currency('KES', 2))->plus(Money::minor('1', new Currency('KES', 2)));
})->throws(LedgerException::class);

it('rejects exchange rates that silently change a custom currency scale', function () {
    new ExchangeRate(new Currency('TOKEN', 6), new Currency('TOKEN', 12), '1', '2026-01-01', 'invalid-scale-change', Rounding::HalfEven);
})->throws(LedgerException::class, 'cannot redefine');

it('rounds ties explicitly and symmetrically', function (string $input, Rounding $rounding, string $output) {
    expect(Money::rounded(BigRational::of($input), new Currency('KES', 2), $rounding)->decimal())->toBe($output);
})->with([
    ['1.005', Rounding::HalfEven, '1.00'], ['1.015', Rounding::HalfEven, '1.02'],
    ['-1.005', Rounding::HalfEven, '-1.00'], ['1.005', Rounding::HalfUp, '1.01'],
    ['-1.005', Rounding::HalfUp, '-1.01'], ['-1.009', Rounding::Down, '-1.00'],
]);

it('conserves every minor unit when allocating positive and negative amounts', function () {
    $currency = new Currency('KES', 2);

    for ($minor = -250; $minor <= 250; $minor++) {
        $amount = Money::minor((string) $minor, $currency);
        $parts = $amount->allocate([0, 2, 3, 1]);
        $sum = Money::minor('0', $currency);

        foreach ($parts as $part) {
            $sum = $sum->plus($part);
        }

        expect($sum->decimal())->toBe($amount->decimal());
        expect($parts[0]->decimal())->toBe('0.00');
    }
});

it('converts exactly at the final rounding boundary', function () {
    $usd = new Currency('USD', 2);
    $kes = new Currency('KES', 2);
    $rate = new ExchangeRate($usd, $kes, '129.125', '2026-01-01', 'approved-treasury-rate:v1', Rounding::HalfEven);
    expect($rate->convert(Money::of('0.04', $usd))->decimal())->toBe('5.16');
});

it('canonicalizes maps and rejects floating point metadata', function () {
    expect(Canonical::hash(['b' => '2', 'a' => '1']))->toBe(Canonical::hash(['a' => '1', 'b' => '2']));
    Canonical::json(['amount' => 0.1]);
})->throws(LedgerException::class);
