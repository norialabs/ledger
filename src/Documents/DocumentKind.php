<?php

declare(strict_types=1);

namespace Noria\Ledger\Documents;

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;

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
