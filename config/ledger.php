<?php

declare(strict_types=1);
use Noria\Ledger\Access\DenyAccess;

return [
    'authorizer' => DenyAccess::class,
    'scope_resolver' => null,
    'posting_policies' => [],
    'posting_treatments' => [],
    'console_actor' => 'ledger:console',
    'defaults' => [
        'currency' => 'KES',
        'country' => 'KE',
    ],
];
