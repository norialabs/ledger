<?php

declare(strict_types=1);

namespace Noria\Ledger\Catalog;

use Noria\Ledger\Accounting\AccountType;
use Noria\Ledger\Accounting\Side;
use Noria\Ledger\Support\Row;
use stdClass;

final readonly class Account
{
    /** @param list<string> $requiredDimensions */
    public function __construct(
        public string $id,
        public string $bookId,
        public string $code,
        public string $name,
        public AccountType $type,
        public Side $normalSide,
        public ?string $currencyCode,
        public ?string $parentId,
        public bool $group,
        public bool $control,
        public bool $active,
        public ?string $cashFlow,
        public bool $deleted,
        public array $requiredDimensions,
    ) {}

    /** @param list<string> $requiredDimensions */
    public static function fromRow(stdClass $row, array $requiredDimensions = []): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'book_id'), Row::text($row, 'code'), Row::text($row, 'name'),
            AccountType::from(Row::text($row, 'type')), Side::from(Row::text($row, 'normal_side')), Row::nullable($row, 'currency_code'),
            Row::nullable($row, 'parent_id'), Row::boolean($row, 'is_group'), Row::boolean($row, 'is_control'), Row::boolean($row, 'active'),
            Row::nullable($row, 'cash_flow'), Row::nullable($row, 'deleted_at') !== null, $requiredDimensions);
    }
}
