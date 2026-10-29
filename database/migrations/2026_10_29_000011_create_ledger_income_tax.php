<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_income_tax_computations', 'ledger_income_tax_losses', 'ledger_income_tax_events'];

    public function up(): void
    {
        Schema::create('ledger_income_tax_computations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('configuration_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('through_sequence');
            $table->string('source', 64);
            $table->jsonb('inputs');
            $table->jsonb('policy');
            $table->jsonb('result');
            $table->char('result_hash', 64);
            $table->uuid('expense_account_id');
            $table->uuid('payable_account_id');
            PostgresSchema::evidence($table);
            PostgresSchema::timestamps($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id', 'configuration_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_configurations');
            foreach (['expense_account_id', 'payable_account_id'] as $column) {
                $table->foreign(['tenant_id', 'book_id', $column])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            }
            $table->index(['tenant_id', 'book_id', 'starts_on', 'ends_on', 'source'], 'ledger_income_tax_year');
        });
        Schema::create('ledger_income_tax_losses', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('source', 64);
            $table->date('vintage_on');
            $table->rawColumn('amount_minor', 'numeric');
            $table->string('reference', 256);
            $table->uuid('computation_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            PostgresSchema::evidence($table);
            PostgresSchema::timestamps($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'computation_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            $table->foreign(['tenant_id', 'book_id', 'computation_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_income_tax_computations');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_income_tax_losses');
        });
        Schema::create('ledger_income_tax_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('computation_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('kind', 16);
            $table->date('effective_on');
            $table->string('reason', 1024);
            $table->jsonb('evidence');
            $table->uuid('journal_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            PostgresSchema::evidence($table);
            PostgresSchema::timestamps($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'computation_id', 'kind'], 'ledger_income_tax_event_kind');
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'computation_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_income_tax_computations');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_income_tax_events');
        });
        PostgresSchema::install('income_tax', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropTriggers(['ledger_journals' => ['stage_80_reverse_income_tax', 'stage_40_income_tax_source', 'stage_40_income_tax_expense'],
            'ledger_configurations' => ['stage_50_income_tax_accounts']]);
        PostgresSchema::dropFunctions(['income_tax']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
