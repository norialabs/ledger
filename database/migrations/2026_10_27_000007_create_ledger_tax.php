<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_tax_decisions', 'ledger_tax_returns', 'ledger_tax_return_events'];

    public function up(): void
    {
        Schema::create('ledger_tax_decisions', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('scheme', 128);
            $table->uuid('configuration_id');
            $table->date('effective_on');
            $table->uuid('journal_id');
            $table->integer('line_no');
            $table->string('tax_code', 64);
            $table->string('kind', 16);
            $table->rawColumn('fraction', 'numeric');
            $table->string('classification', 64)->nullable();
            $table->string('reason', 1024);
            $table->jsonb('evidence');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'configuration_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_configurations');
            $table->index(['tenant_id', 'book_id', 'scheme', 'journal_id', 'line_no', 'tax_code', 'sequence'], 'ledger_tax_decision_history');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_tax_returns', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('configuration_id');
            $table->string('scheme', 128);
            $table->date('starts_on');
            $table->date('effective_on');
            $table->bigInteger('through_sequence');
            $table->uuid('previous_id')->nullable();
            $table->uuid('prior_period_id')->nullable();
            $table->rawColumn('opening_credit_minor', 'numeric');
            $table->string('opening_reference', 256)->nullable();
            $table->jsonb('result_json');
            $table->char('result_hash', 64);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'previous_id']);
            $table->foreign(['tenant_id', 'book_id', 'configuration_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_configurations');
            $table->foreign(['tenant_id', 'book_id', 'previous_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_tax_returns');
            $table->foreign(['tenant_id', 'book_id', 'prior_period_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_tax_returns');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_tax_return_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('return_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->string('kind', 16);
            $table->string('reason', 1024);
            $table->jsonb('evidence');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'return_id', 'kind']);
            $table->foreign(['tenant_id', 'book_id', 'return_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_tax_returns');
            PostgresSchema::timestamps($table);
        });
        PostgresSchema::install('tax', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['tax']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
