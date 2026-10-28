<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

use Noria\Ledger\Support\Row;
use stdClass;

/** @phpstan-type State array{status: string, latest_event_id: ?string, available_at: string, attempts: int, receipt_reference: ?string, ready: bool, mode: string} */
final readonly class PendingSubmission
{
    /** @param State $state */
    public function __construct(public string $id, public string $channel, public string $environment, public array $state) {}

    public static function fromRow(stdClass $row): self
    {
        return new self(Row::text($row, 'id'), Row::text($row, 'channel'), Row::text($row, 'environment'), self::state(Row::object(Row::json($row, 'value'))));
    }

    /** @return State */
    public static function state(stdClass $row): array
    {
        return ['status' => Row::text($row, 'status'), 'latest_event_id' => Row::nullable($row, 'latest_event_id'),
            'available_at' => Row::text($row, 'available_at'), 'attempts' => (int) Row::text($row, 'attempts'),
            'receipt_reference' => Row::nullable($row, 'receipt_reference'), 'ready' => Row::boolean($row, 'ready'), 'mode' => Row::text($row, 'mode')];
    }
}
