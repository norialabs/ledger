<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_deferred_tax_computations', 'ledger_deferred_tax_events'];

    public function up(): void
    {
        Schema::create('ledger_deferred_tax_computations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('configuration_id');
            $table->string('source', 128);
            $table->date('effective_on');
            $table->bigInteger('through_sequence');
            $table->jsonb('inputs');
            $table->jsonb('policy');
            $table->jsonb('result');
            $table->char('result_hash', 64);
            PostgresSchema::evidence($table);
            PostgresSchema::timestamps($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id', 'configuration_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_configurations');
            $table->index(['tenant_id', 'book_id', 'source', 'sequence'], 'ledger_deferred_tax_source');
        });
        Schema::create('ledger_deferred_tax_events', function (Blueprint $table): void {
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
            $table->unique(['tenant_id', 'book_id', 'computation_id', 'kind'], 'ledger_deferred_tax_event_kind');
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'computation_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_deferred_tax_computations');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_deferred_tax_events');
        });
        PostgresSchema::install('deferred_tax', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropTriggers(['ledger_journals' => ['stage_40_deferred_tax_source', 'stage_80_reverse_deferred_tax', 'stage_99_deferred_tax_link'],
            'ledger_configurations' => ['stage_50_deferred_tax_accounts']]);
        PostgresSchema::dropFunctions(['deferred_tax']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
