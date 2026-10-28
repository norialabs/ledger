<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_statutory_submissions', 'ledger_statutory_events'];

    public function up(): void
    {
        Schema::create('ledger_statutory_submissions', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('kind', 16);
            $table->uuid('journal_id')->nullable();
            $table->uuid('return_id')->nullable();
            $table->uuid('original_id')->nullable();
            $table->uuid('replaces_id')->nullable();
            $table->string('channel', 128);
            $table->string('environment', 16);
            $table->string('adapter', 128);
            $table->string('adapter_version', 128);
            $table->string('request_reference', 128);
            $table->string('content_type', 128);
            $table->text('payload');
            $table->char('payload_hash', 64);
            $table->char('source_hash', 64);
            $table->string('source_currency', 16);
            $table->integer('source_scale');
            $table->rawColumn('gross_minor', 'numeric');
            $table->integer('max_attempts');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'replaces_id']);
            $table->unique(['tenant_id', 'book_id', 'channel', 'environment', 'request_reference']);
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'return_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_tax_returns');
            $table->foreign(['tenant_id', 'book_id', 'original_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_statutory_submissions');
            $table->foreign(['tenant_id', 'book_id', 'replaces_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_statutory_submissions');
            $table->index(['tenant_id', 'book_id', 'channel', 'environment', 'journal_id', 'return_id'], 'ledger_statutory_source');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_statutory_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('submission_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('kind', 16);
            $table->string('mode', 16)->nullable();
            $table->uuid('claim_id')->nullable();
            $table->integer('lease_seconds')->nullable();
            $table->timestampTz('lease_expires_at', 6)->nullable();
            $table->integer('delay_seconds')->default(0);
            $table->timestampTz('available_at', 6);
            $table->string('receipt_reference', 256)->nullable();
            $table->string('channel', 128);
            $table->string('environment', 16);
            $table->jsonb('evidence');
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'claim_id']);
            $table->foreign(['tenant_id', 'book_id', 'submission_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_statutory_submissions');
            $table->foreign(['tenant_id', 'book_id', 'claim_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_statutory_events');
            $table->rawIndex('tenant_id, book_id, submission_id, sequence DESC', 'ledger_statutory_history');
            PostgresSchema::timestamps($table);
        });
        PostgresSchema::install('statutory', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['statutory']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
