<?php

declare(strict_types=1);

namespace Noria\Ledger\Console;

use Noria\Ledger\Access\Scope;
use Noria\Ledger\Ledger;
use Noria\Ledger\Session;

trait ResolvesTenant
{
    private function session(string $tenant): Session
    {
        return $this->laravel->make(Ledger::class)->for(new Scope($tenant, $this->configString('ledger.console_actor', 'ledger:console')));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function configString(string $key, string $default): string
    {
        $value = $this->laravel->make('config')->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
