<?php

declare(strict_types=1);

namespace Noria\Ledger\Imports;

enum ImportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Json = 'json';
    case Mpesa = 'mpesa';
    case Mt940 = 'mt940';
    case Camt053 = 'camt053';

    public static function fromPath(string $path): self
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx', 'xls', 'ods' => self::Xlsx,
            'json' => self::Json,
            'sta', 'mt940', 'txt' => self::Mt940,
            'xml' => self::Camt053,
            default => self::Csv,
        };
    }
}
