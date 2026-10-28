<?php

declare(strict_types=1);

namespace Noria\Ledger\Console;

use Illuminate\Console\Command;
use Noria\Ledger\Imports\ImportFormat;
use Noria\Ledger\Imports\ImportKind;

final class ImportCommand extends Command
{
    use ResolvesTenant;

    protected $signature = 'ledger:import
        {kind : bank_statement, parties, accounts, journals or exchange_rates}
        {path : File to import}
        {--tenant= : Ledger tenant UUID}
        {--format= : csv, xlsx, json, mpesa, mt940 or camt053; guessed from the extension when omitted}
        {--option=* : Import options as key=value, e.g. book_id=..., account_id=..., entity_id=..., reference=..., opening=...}
        {--commit : Submit the import when it is valid; otherwise only preview}';

    protected $description = 'Preview a ledger import and optionally commit it';

    public function handle(): int
    {
        $tenant = $this->stringOption('tenant');
        $kindArgument = $this->argument('kind');
        $path = $this->argument('path');
        $kind = is_string($kindArgument) ? ImportKind::tryFrom($kindArgument) : null;
        $format = $this->stringOption('format');
        if ($tenant === null || $kind === null || ! is_string($path) || ($format !== null && ImportFormat::tryFrom($format) === null)) {
            $this->error('A --tenant, a valid kind and, if given, a valid --format are required.');

            return self::FAILURE;
        }
        $options = [];
        foreach ((array) $this->option('option') as $pair) {
            if (is_string($pair) && str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $options[trim($key)] = trim($value);
            }
        }
        $imports = $this->session($tenant)->imports;
        $preview = $imports->preview($kind, $path, $format === null ? null : ImportFormat::from($format), $options);
        $rows = array_slice($preview->rows, 0, 50);
        if ($rows !== []) {
            $this->table(array_keys($rows[0]), array_map(fn (array $row): array => array_map(fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value), $row), $rows));
        }
        foreach ($preview->summary as $key => $value) {
            $this->line($key.': '.(is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value)));
        }
        foreach ($preview->errors as $row => $messages) {
            $this->error(($row === '_' ? 'File' : 'Row '.((int) $row + 1)).': '.implode(' ', $messages));
        }
        if (! $preview->isValid()) {
            return self::FAILURE;
        }
        if ($this->option('commit')) {
            $result = $imports->submit($kind, $preview->rows, [...$options, ...$preview->summary]);
            $this->info('Imported '.count($result['created']).' new records; '.$result['existing'].' already existed.');
        }

        return self::SUCCESS;
    }
}
