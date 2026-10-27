<?php

declare(strict_types=1);

namespace NoriaLabs\Ledger\Documents;

use NoriaLabs\Ledger\Accounting\AccountType;
use NoriaLabs\Ledger\Accounting\Side;

enum DocumentKind: string
{
    case SalesInvoice = 'sales_invoice';
    case SalesCredit = 'sales_credit';
    case PurchaseBill = 'purchase_bill';
    case PurchaseCredit = 'purchase_credit';

    public function controlSide(): Side
    {
        return match ($this) {
            self::SalesInvoice, self::PurchaseCredit => Side::Debit,
            self::SalesCredit, self::PurchaseBill => Side::Credit,
        };
    }

    public function controlType(): AccountType
    {
        return match ($this) {
            self::SalesInvoice, self::SalesCredit => AccountType::Asset,
            self::PurchaseBill, self::PurchaseCredit => AccountType::Liability,
        };
    }
}
