<?php

declare(strict_types=1);

$zip = new ZipArchive;

if ($zip->open($argv[1] ?? '') !== true) {
    throw new RuntimeException('Cannot open the package archive.');
}

foreach (['composer.json', 'src/Ledger.php', 'src/Assets.php', 'src/Inventory.php', 'src/Revaluations.php', 'src/Consolidation.php',
    'database/schema.pgsql.sql', 'database/currency.pgsql.sql', 'database/inventory.pgsql.sql', 'database/assets.pgsql.sql', 'database/groups.pgsql.sql',
    'database/finance.pgsql.sql', 'database/tax.pgsql.sql', 'database/statutory.pgsql.sql', 'database/storage.pgsql.sql',
    'database/migrations/2026_10_27_000001_create_ledger.php', 'README.md', 'LICENSE'] as $required) {
    if ($zip->locateName($required) === false) {
        throw new RuntimeException('Package archive is missing '.$required);
    }
}

$root = dirname(__DIR__);

foreach (['src', 'database', 'docs'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $name = substr($file->getPathname(), strlen($root) + 1);

        if ($zip->getFromName($name) !== file_get_contents($file->getPathname())) {
            throw new RuntimeException('Package archive is missing or changed: '.$name);
        }
    }
}

for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);

    if ($name !== false && preg_match('~^(vendor/|tests/|\.git/|\.env$|composer\.lock$)~', $name) === 1) {
        throw new RuntimeException('Development content found in package archive: '.$name);
    }
}

fwrite(STDOUT, 'Verified package archive: '.$zip->numFiles." files.\n");
$zip->close();
