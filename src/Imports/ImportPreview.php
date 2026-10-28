<?php

declare(strict_types=1);

namespace Noria\Ledger\Imports;

use JsonSerializable;

final readonly class ImportPreview implements JsonSerializable
{
    /** @param list<array<string, mixed>> $rows
     * @param  array<int|string, list<string>>  $errors
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $headers
     */
    public function __construct(public ImportKind $kind, public array $rows, public array $errors = [], public array $summary = [], public array $headers = []) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> */
    public function errorsFor(int|string $row): array
    {
        return $this->errors[$row] ?? [];
    }

    /** @return array{kind: string, rows: list<array<string, mixed>>, errors: array<int|string, list<string>>, summary: array<string, mixed>, headers: list<string>, valid: bool} */
    public function jsonSerialize(): array
    {
        return ['kind' => $this->kind->value, 'rows' => $this->rows, 'errors' => $this->errors, 'summary' => $this->summary, 'headers' => $this->headers, 'valid' => $this->isValid()];
    }
}
