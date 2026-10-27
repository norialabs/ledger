<?php

declare(strict_types=1);

namespace Noria\Ledger\Access;

enum Permission: string
{
    case Configure = 'ledger.configure';
    case Read = 'ledger.read';
    case Post = 'ledger.post';
    case Reverse = 'ledger.reverse';
    case Allocate = 'ledger.allocate';
    case Close = 'ledger.close';
    case Reopen = 'ledger.reopen';
    case Reconcile = 'ledger.reconcile';
    case Intercompany = 'ledger.intercompany';
    case Revalue = 'ledger.revalue';
    case Inventory = 'ledger.inventory';
    case Assets = 'ledger.assets';
    case Consolidate = 'ledger.consolidate';
}
