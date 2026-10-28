<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax\Kenya;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use JsonSerializable;
use Noria\Ledger\Support\Assert;

final readonly class EtimsSale implements JsonSerializable
{
    public const TAX_TYPES = ['A' => '0', 'B' => '16', 'C' => '0', 'D' => '0', 'E' => '8'];

    public const PAYMENT_TYPES = ['01' => 'cash', '02' => 'credit', '03' => 'cash/credit', '04' => 'bank check', '05' => 'card', '06' => 'mobile money', '07' => 'other'];

    public const CREDIT_NOTE_REASONS = ['01' => 'missing quantity', '02' => 'missing data', '03' => 'damaged', '04' => 'wasted', '05' => 'raw material shortage', '06' => 'refund'];

    /** @param list<array<string, mixed>> $items
     * @param  array<string, string>  $taxRates
     */
    private function __construct(public int $invoiceNumber, public string $traderInvoiceNumber, public int $originalInvoiceNumber, public string $receiptType,
        public string $confirmedAt, public array $items, public ?string $customerPin, public ?string $customerName, public string $paymentType, public string $salesType,
        public ?string $creditNoteReason, public ?string $stockReleasedAt, public array $taxRates, public ?string $remark, public ?string $customerMobile) {}

    /** @param list<array<string, mixed>> $items
     * @param  array<string, string>  $taxRates
     */
    public static function invoice(int $invoiceNumber, string $traderInvoiceNumber, string $confirmedAt, array $items, ?string $customerPin = null, ?string $customerName = null,
        string $paymentType = '01', string $salesType = 'N', ?string $stockReleasedAt = null, array $taxRates = self::TAX_TYPES, ?string $remark = null, ?string $customerMobile = null): self
    {
        return self::make($invoiceNumber, $traderInvoiceNumber, 0, 'S', $confirmedAt, $items, $customerPin, $customerName, $paymentType, $salesType, null, $stockReleasedAt, $taxRates, $remark, $customerMobile);
    }

    /** @param list<array<string, mixed>> $items
     * @param  array<string, string>  $taxRates
     */
    public static function creditNote(int $invoiceNumber, string $traderInvoiceNumber, int $originalInvoiceNumber, string $reason, string $confirmedAt, array $items,
        ?string $customerPin = null, ?string $customerName = null, string $paymentType = '01', ?string $stockReleasedAt = null, array $taxRates = self::TAX_TYPES, ?string $remark = null): self
    {
        Assert::that($originalInvoiceNumber > 0 && isset(self::CREDIT_NOTE_REASONS[$reason]), 'A credit note needs its original invoice number and a reason code 01 to 06.');

        return self::make($invoiceNumber, $traderInvoiceNumber, $originalInvoiceNumber, 'R', $confirmedAt, $items, $customerPin, $customerName, $paymentType, 'N', $reason, $stockReleasedAt, $taxRates, $remark, null);
    }

    /** @return array<string, mixed> */
    public function payload(EtimsDevice $device, string $registrarId = 'ledger', string $registrarName = 'Ledger'): array
    {
        $confirmed = self::timestamp($this->confirmedAt);
        $lines = [];
        $taxable = array_fill_keys(array_keys(self::TAX_TYPES), BigDecimal::zero());
        $taxes = $taxable;
        foreach ($this->items as $index => $item) {
            $line = $this->line($index + 1, $item);
            $lines[] = $line['json'];
            $taxable[$line['type']] = $taxable[$line['type']]->plus($line['taxable']);
            $taxes[$line['type']] = $taxes[$line['type']]->plus($line['tax']);
        }
        $payload = ['tin' => $device->pin, 'bhfId' => $device->branchId, 'trdInvcNo' => $this->traderInvoiceNumber, 'invcNo' => $this->invoiceNumber,
            'orgInvcNo' => $this->originalInvoiceNumber, 'custTin' => $this->customerPin, 'custNm' => $this->customerName, 'salesTyCd' => $this->salesType,
            'rcptTyCd' => $this->receiptType, 'pmtTyCd' => $this->paymentType, 'salesSttsCd' => '02', 'cfmDt' => $confirmed, 'salesDt' => substr($confirmed, 0, 8),
            'stockRlsDt' => $this->stockReleasedAt === null ? null : self::timestamp($this->stockReleasedAt), 'cnclReqDt' => null, 'cnclDt' => null,
            'rfdDt' => $this->receiptType === 'R' ? $confirmed : null, 'rfdRsnCd' => $this->creditNoteReason, 'totItemCnt' => count($lines)];
        foreach (array_keys(self::TAX_TYPES) as $type) {
            $payload['taxblAmt'.$type] = self::number($taxable[$type]);
        }
        foreach (array_keys(self::TAX_TYPES) as $type) {
            $payload['taxRt'.$type] = self::number(BigDecimal::of($this->taxRates[$type] ?? '0'));
        }
        foreach (array_keys(self::TAX_TYPES) as $type) {
            $payload['taxAmt'.$type] = self::number($taxes[$type]);
        }
        $totalTaxable = array_reduce($taxable, fn (BigDecimal $sum, BigDecimal $value): BigDecimal => $sum->plus($value), BigDecimal::zero());
        $totalTax = array_reduce($taxes, fn (BigDecimal $sum, BigDecimal $value): BigDecimal => $sum->plus($value), BigDecimal::zero());

        return [...$payload, 'totTaxblAmt' => self::number($totalTaxable), 'totTaxAmt' => self::number($totalTax), 'totAmt' => self::number($totalTaxable),
            'prchrAcptcYn' => 'N', 'remark' => $this->remark, 'regrId' => $registrarId, 'regrNm' => $registrarName, 'modrId' => $registrarId, 'modrNm' => $registrarName,
            'receipt' => ['custTin' => $this->customerPin, 'custMblNo' => $this->customerMobile, 'rcptPbctDt' => $confirmed, 'trdeNm' => null, 'adrs' => null, 'topMsg' => null, 'btmMsg' => null, 'prchrAcptcYn' => 'N'],
            'itemList' => $lines];
    }

    public function json(EtimsDevice $device, string $registrarId = 'ledger', string $registrarName = 'Ledger'): string
    {
        return json_encode($this->payload($device, $registrarId, $registrarName), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['invoice_number' => $this->invoiceNumber, 'trader_invoice_number' => $this->traderInvoiceNumber, 'original_invoice_number' => $this->originalInvoiceNumber,
            'receipt_type' => $this->receiptType, 'confirmed_at' => $this->confirmedAt, 'items' => $this->items];
    }

    /** @param list<array<string, mixed>> $items
     * @param  array<string, string>  $taxRates
     */
    private static function make(int $invoiceNumber, string $traderInvoiceNumber, int $originalInvoiceNumber, string $receiptType, string $confirmedAt, array $items,
        ?string $customerPin, ?string $customerName, string $paymentType, string $salesType, ?string $reason, ?string $stockReleasedAt, array $taxRates, ?string $remark, ?string $customerMobile): self
    {
        Assert::that($invoiceNumber > 0, 'eTIMS invoice numbers are positive and unique per branch.');
        Assert::text($traderInvoiceNumber, 50);
        Assert::that($customerPin === null || preg_match('/^[A-Z]\d{9}[A-Z]$/D', $customerPin) === 1, 'A customer PIN is one letter, nine digits and one letter.');
        Assert::that($customerName === null || strlen($customerName) <= 60, 'eTIMS customer names are at most 60 characters.');
        Assert::that(isset(self::PAYMENT_TYPES[$paymentType]), 'Payment type must be an eTIMS code 01 to 07.');
        Assert::that(in_array($salesType, ['N', 'C', 'P', 'T'], true), 'Sales type must be N (normal), C (copy), P (proforma) or T (training).');
        Assert::that($items !== [] && count($items) <= 999, 'An eTIMS invoice has between 1 and 999 items.');
        foreach (array_keys(self::TAX_TYPES) as $type) {
            Assert::that(isset($taxRates[$type]) && BigDecimal::of(Assert::decimal($taxRates[$type]))->isGreaterThanOrEqualTo(0), 'Provide an eTIMS tax rate for type '.$type.'.');
        }
        self::timestamp($confirmedAt);
        if ($stockReleasedAt !== null) {
            self::timestamp($stockReleasedAt);
        }
        $sale = new self($invoiceNumber, $traderInvoiceNumber, $originalInvoiceNumber, $receiptType, $confirmedAt, $items, $customerPin, $customerName, $paymentType, $salesType,
            $reason, $stockReleasedAt, $taxRates, $remark, $customerMobile);
        foreach ($items as $index => $item) {
            $sale->line($index + 1, $item);
        }

        return $sale;
    }

    /** @param array<string, mixed> $item
     * @return array{json: array<string, mixed>, type: string, taxable: BigDecimal, tax: BigDecimal}
     */
    private function line(int $sequence, array $item): array
    {
        $text = function (string $key, int $max, bool $required = true) use ($item, $sequence): ?string {
            $value = $item[$key] ?? null;
            Assert::that(($value === null && ! $required) || (is_string($value) && trim($value) !== '' && strlen($value) <= $max), 'Item '.$sequence.' needs '.$key.' of up to '.$max.' characters.');

            return is_string($value) ? $value : null;
        };
        $decimal = function (string $key, bool $required = true) use ($item, $sequence): ?BigDecimal {
            $value = $item[$key] ?? null;
            if ($value === null && ! $required) {
                return null;
            }
            Assert::that(is_string($value) || is_int($value), 'Item '.$sequence.' needs a decimal '.$key.'.');

            return BigDecimal::of(Assert::decimal((string) (is_string($value) || is_int($value) ? $value : '0')));
        };
        $type = $text('tax_type', 1) ?? '';
        Assert::that(isset(self::TAX_TYPES[$type]), 'Item '.$sequence.' tax type must be A, B, C, D or E.');
        $quantity = $decimal('quantity') ?? BigDecimal::zero();
        $price = $decimal('price') ?? BigDecimal::zero();
        Assert::that($quantity->isPositive() && ! $price->isNegative(), 'Item '.$sequence.' needs a positive quantity and a non-negative price.');
        $supply = $quantity->multipliedBy($price)->toScale(2, RoundingMode::HalfUp);
        $discountRate = $decimal('discount_rate', false) ?? BigDecimal::zero();
        Assert::that($discountRate->isGreaterThanOrEqualTo(0) && $discountRate->isLessThanOrEqualTo(100), 'Item '.$sequence.' discount rate is between 0 and 100.');
        $discount = $supply->multipliedBy($discountRate)->dividedBy(100, 2, RoundingMode::HalfUp);
        $taxable = $supply->minus($discount);
        $rate = BigDecimal::of($this->taxRates[$type]);
        $tax = $taxable->multipliedBy($rate)->dividedBy($rate->plus(100), 2, RoundingMode::HalfUp);

        return ['type' => $type, 'taxable' => $taxable, 'tax' => $tax, 'json' => ['itemSeq' => $sequence, 'itemCd' => $text('code', 20), 'itemClsCd' => $text('class_code', 10),
            'itemNm' => $text('name', 200), 'bcd' => $text('barcode', 20, false), 'pkgUnitCd' => $text('package_unit', 5), 'pkg' => self::number($decimal('packages', false) ?? $quantity),
            'qtyUnitCd' => $text('quantity_unit', 5), 'qty' => self::number($quantity), 'prc' => self::number($price), 'splyAmt' => self::number($supply),
            'dcRt' => self::number($discountRate), 'dcAmt' => self::number($discount), 'isrccCd' => null, 'isrccNm' => null, 'isrcRt' => null, 'isrcAmt' => null,
            'taxTyCd' => $type, 'taxblAmt' => self::number($taxable), 'taxAmt' => self::number($tax), 'totAmt' => self::number($taxable)]];
    }

    private static function number(BigDecimal $value): float|int
    {
        $scaled = $value->toScale(2, RoundingMode::HalfUp);

        return $scaled->getFractionalPart()->isZero() ? $scaled->toBigInteger()->toInt() : $scaled->toFloat();
    }

    private static function timestamp(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        Assert::that($date !== false && $date->format('Y-m-d H:i:s') === $value, 'eTIMS times use Y-m-d H:i:s.');

        return $date === false ? '' : $date->format('YmdHis');
    }
}
