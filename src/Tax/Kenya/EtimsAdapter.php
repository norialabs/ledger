<?php

declare(strict_types=1);

namespace Noria\Ledger\Tax\Kenya;

use Noria\Ledger\Exceptions\LedgerException;
use Noria\Ledger\Statutory\Adapter;
use Noria\Ledger\Statutory\Outcome;
use Noria\Ledger\Statutory\Submission;

class EtimsAdapter implements Adapter
{
    private const REJECTED = ['891', '892', '893', '895', '896', '899', '900', '901', '902', '903', '910', '911', '912', '921', '922', '991', '992', '993', '995'];

    public function __construct(private readonly EtimsClient $client) {}

    public function name(): string
    {
        return 'kra.etims.oscu';
    }

    public function version(): string
    {
        return 'oscu-2.0';
    }

    public function validate(Submission $submission): void
    {
        $sale = $this->sale($submission);
        $device = $this->client->device();
        if ($submission->environment !== $device->environment || ($sale['tin'] ?? null) !== $device->pin || ($sale['bhfId'] ?? null) !== $device->branchId) {
            throw new LedgerException('The eTIMS payload belongs to another PIN, branch or environment.');
        }
        foreach (['invcNo', 'rcptTyCd', 'salesDt', 'cfmDt', 'itemList', 'totAmt'] as $field) {
            if (! array_key_exists($field, $sale)) {
                throw new LedgerException('The eTIMS payload is missing '.$field.'.');
            }
        }
        if (($sale['rcptTyCd'] === 'R') !== ($submission->originalReceipt !== null)) {
            throw new LedgerException('An eTIMS credit note must reference its accepted original invoice.');
        }
    }

    public function submit(Submission $submission): Outcome
    {
        $response = $this->client->saveSale($this->sale($submission));
        $code = $this->code($response);
        if ($code === '994') {
            return new Outcome('uncertain', 'eTIMS already holds this invoice number; confirm before treating it as accepted', ['result_code' => $code, 'result_message' => $this->message($response)]);
        }

        return $this->outcome($submission, $response);
    }

    public function lookup(Submission $submission): Outcome
    {
        $response = $this->client->saveSale($this->sale($submission));
        $code = $this->code($response);
        if ($code === '994') {
            return new Outcome('accepted', 'eTIMS confirms the invoice number was already received', ['result_code' => $code, 'result_message' => $this->message($response),
                'signature_available' => false], $this->reference($submission, null));
        }
        $outcome = $this->outcome($submission, $response);

        return $outcome->status === 'retry' ? new Outcome('uncertain', $outcome->reason, $outcome->evidence, delaySeconds: 60) : $outcome;
    }

    /** @param array<string, mixed> $response */
    private function outcome(Submission $submission, array $response): Outcome
    {
        $code = $this->code($response);
        $evidence = ['result_code' => $code, 'result_message' => $this->message($response), 'result_at' => is_string($response['resultDt'] ?? null) ? $response['resultDt'] : null];
        if ($code === '000') {
            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            foreach (['curRcptNo', 'totRcptNo', 'intrlData', 'rcptSign', 'sdcDateTime'] as $field) {
                $value = $data[$field] ?? $data[$field.' '] ?? null;
                $evidence[$field] = is_scalar($value) ? trim((string) $value) : null;
            }

            return new Outcome('accepted', 'eTIMS accepted the invoice', $evidence, $this->reference($submission, $evidence['curRcptNo']));
        }
        if (in_array($code, self::REJECTED, true)) {
            return new Outcome('rejected', 'eTIMS rejected the invoice ('.$code.'): '.$this->message($response), $evidence);
        }

        if ($code === '990') {
            return new Outcome('retry', 'eTIMS request limit reached before processing ('.$code.')', $evidence, delaySeconds: 300);
        }

        return new Outcome('uncertain', 'eTIMS returned '.$code.' without confirming the invoice', $evidence, delaySeconds: 60);
    }

    /** @return array<string, mixed> */
    private function sale(Submission $submission): array
    {
        $sale = json_decode($submission->payload, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($sale) || array_is_list($sale)) {
            throw new LedgerException('An eTIMS payload must be a JSON object.');
        }
        $result = [];
        foreach ($sale as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $response */
    private function code(array $response): string
    {
        return is_string($response['resultCd'] ?? null) ? $response['resultCd'] : '999';
    }

    /** @param array<string, mixed> $response */
    private function message(array $response): string
    {
        return is_string($response['resultMsg'] ?? null) && $response['resultMsg'] !== '' ? substr($response['resultMsg'], 0, 900) : 'No message';
    }

    private function reference(Submission $submission, mixed $receipt): string
    {
        $sale = $this->sale($submission);
        $invoice = is_scalar($sale['invcNo'] ?? null) ? (string) $sale['invcNo'] : '?';
        $device = $this->client->device();

        return $device->pin.'-'.$device->branchId.'-'.$invoice.(is_string($receipt) && $receipt !== '' ? '-'.$receipt : '');
    }
}
