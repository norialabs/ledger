<?php

declare(strict_types=1);

namespace Noria\Ledger\Statutory;

interface Adapter
{
    public function name(): string;

    public function version(): string;

    public function validate(Submission $submission): void;

    public function submit(Submission $submission): Outcome;

    public function lookup(Submission $submission): Outcome;
}
