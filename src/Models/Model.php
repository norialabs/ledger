<?php

declare(strict_types=1);

namespace Noria\Ledger\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Money\Currency as MoneyCurrency;
use Noria\Ledger\Money\Money;
use Noria\Ledger\Support\Assert;

abstract class Model extends Eloquent
{
    protected static bool $writable = false;

    /** @var array<string, int> */
    private static array $scales = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function boot(): void
    {
        parent::boot();
        $guard = static function (Eloquent $model): void {
            if (! $model instanceof self || ! $model::isWritable()) {
                throw new LedgerException(class_basename($model).' is read-only; change ledger data through the ledger services.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    public static function isWritable(): bool
    {
        return static::$writable;
    }

    public function money(string $column, ?string $currencyCode = null): Money
    {
        $minor = $this->getAttribute($column);
        Assert::that(is_string($minor) || is_int($minor), 'Unknown or empty money column: '.$column);
        $code = $currencyCode ?? $this->moneyCurrencyCode();

        return Money::minor((string) $minor, new MoneyCurrency($code, $this->scale($code)));
    }

    /** @template T of Model
     * @param  class-string<T>  $default
     * @return class-string<T>
     */
    public static function modelClass(string $key, string $default): string
    {
        $configured = config('ledger.models.'.$key);
        if ($configured === null) {
            return $default;
        }
        Assert::that(is_string($configured) && is_a($configured, $default, true), 'ledger.models.'.$key.' must extend '.$default.'.');

        return $configured;
    }

    protected function moneyCurrencyCode(): string
    {
        $own = $this->getAttribute('currency_code');
        if (is_string($own)) {
            return $own;
        }
        $bookId = $this->getAttribute('book_id');
        Assert::that(is_string($bookId), 'Pass a currency code for '.class_basename($this).' amounts.');
        $book = self::modelClass('book', Book::class)::query()->find($bookId);
        Assert::that($book instanceof Book, 'The record\'s book is not visible in the current ledger scope.');

        return $book->currency_code;
    }

    private function scale(string $code): int
    {
        if (! isset(self::$scales[$code])) {
            $currency = self::modelClass('currency', Currency::class)::query()->where('code', $code)->first();
            Assert::that($currency instanceof Currency, 'Currency '.$code.' is not registered in the current ledger scope.');
            self::$scales[$code] = $currency->scale;
        }

        return self::$scales[$code];
    }
}
