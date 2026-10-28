<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax\Kenya;

use Illuminate\Http\Client\Factory;
use Noria\Ledger\Exceptions\LedgerException;

class EtimsClient
{
    public const EPOCH = '20180101000000';

    public function __construct(private readonly Factory $http, private readonly EtimsDevice $device, private readonly int $timeoutSeconds = 30, private readonly ?string $baseUrl = null) {}

    public function device(): EtimsDevice
    {
        return $this->device;
    }

    /** @return array<string, mixed> */
    public function initialize(string $serialNumber): array
    {
        return $this->send('selectInitOsdcInfo', ['tin' => $this->device->pin, 'bhfId' => $this->device->branchId, 'dvcSrlNo' => $serialNumber], false);
    }

    /** @return array<string, mixed> */
    public function codes(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectCodeList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @return array<string, mixed> */
    public function itemClassifications(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectItemClsList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @return array<string, mixed> */
    public function customer(string $pin): array
    {
        return $this->post('selectCustomer', ['custmTin' => $pin]);
    }

    /** @return array<string, mixed> */
    public function notices(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectNoticeList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @return array<string, mixed> */
    public function branches(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectBhfList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @return array<string, mixed> */
    public function items(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectItemList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function saveItem(array $item): array
    {
        return $this->post('saveItem', $item);
    }

    /** @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    public function saveBranchCustomer(array $customer): array
    {
        return $this->post('saveBhfCustomer', $customer);
    }

    /** @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function saveBranchUser(array $user): array
    {
        return $this->post('saveBhfUser', $user);
    }

    /** @return array<string, mixed> */
    public function importedItems(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectImportItemList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function updateImportedItem(array $item): array
    {
        return $this->post('updateImportItem', $item);
    }

    /** @param array<string, mixed> $sale
     * @return array<string, mixed>
     */
    public function saveSale(array $sale): array
    {
        return $this->post('saveTrnsSalesOsdc', $sale);
    }

    /** @return array<string, mixed> */
    public function purchases(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectTrnsPurchaseSalesList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    public function savePurchase(array $purchase): array
    {
        return $this->post('insertTrnsPurchase', $purchase);
    }

    /** @return array<string, mixed> */
    public function stockMovements(string $lastRequestAt = self::EPOCH): array
    {
        return $this->post('selectStockMoveList', ['lastReqDt' => $lastRequestAt]);
    }

    /** @param array<string, mixed> $movement
     * @return array<string, mixed>
     */
    public function saveStockMovement(array $movement): array
    {
        return $this->post('insertStockIO', $movement);
    }

    /** @param array<string, mixed> $stock
     * @return array<string, mixed>
     */
    public function saveStockMaster(array $stock): array
    {
        return $this->post('saveStockMaster', $stock);
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $body): array
    {
        return $this->send($endpoint, ['tin' => $this->device->pin, 'bhfId' => $this->device->branchId, ...$body], true);
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function send(string $endpoint, array $body, bool $authenticated): array
    {
        if (preg_match('/^[A-Za-z]+$/D', $endpoint) !== 1) {
            throw new LedgerException('Unknown eTIMS endpoint.');
        }
        $headers = ['tin' => $this->device->pin, 'bhfId' => $this->device->branchId];
        if ($authenticated) {
            $headers['cmcKey'] = $this->device->communicationKey;
        }
        $response = $this->http->baseUrl($this->baseUrl ?? $this->device->baseUrl())->timeout($this->timeoutSeconds)->acceptJson()->asJson()
            ->withHeaders($headers)->post($endpoint, $body);
        $data = $response->json();
        if (! is_array($data) || ! is_string($data['resultCd'] ?? null)) {
            throw new LedgerException('eTIMS returned HTTP '.$response->status().' without a result code.');
        }
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }
}
