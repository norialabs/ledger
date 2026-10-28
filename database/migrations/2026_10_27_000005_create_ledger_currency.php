<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_exchange_rates', 'ledger_revaluations', 'ledger_revaluation_clearings', 'ledger_monetary_accounts', 'ledger_monetary_valuations'];

    public function up(): void
    {
        Schema::create('ledger_exchange_rates', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->string('base_code', 16);
            $table->string('quote_code', 16);
            $table->string('rate_type', 16);
            $table->date('effective_on');
            $table->integer('revision');
            $table->rawColumn('rate', 'numeric');
            $table->string('source', 256);
            $table->string('reason', 1024)->nullable();
            $table->timestampTz('recorded_at', 6);
            $table->string('actor_id', 128);
            $table->string('firm_id', 128)->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'base_code', 'quote_code', 'rate_type', 'effective_on', 'revision']);
            $table->foreign(['tenant_id', 'base_code'])->references(['tenant_id', 'code'])->on('ledger_currencies');
            $table->foreign(['tenant_id', 'quote_code'])->references(['tenant_id', 'code'])->on('ledger_currencies');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_revaluations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('item_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->rawColumn('delta_minor', 'numeric');
            $table->decimal('rate', 38, 18);
            $table->date('rate_as_of');
            $table->string('rate_source', 256);
            $table->string('rounding', 16);
            $table->uuid('journal_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'item_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_open_items');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_revaluations');
            $table->index(['tenant_id', 'book_id', 'item_id', 'sequence'], 'ledger_revaluations_item');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_revaluation_clearings', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('item_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->string('reason', 1024);
            $table->jsonb('journal_ids');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'item_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id', 'item_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_open_items');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_monetary_accounts', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('account_id');
            $table->string('policy_version', 128);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'account_id']);
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_monetary_valuations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('account_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->jsonb('dimensions');
            $table->rawColumn('source_minor', 'numeric');
            $table->rawColumn('before_minor', 'numeric');
            $table->rawColumn('delta_minor', 'numeric');
            $table->rawColumn('rate', 'numeric');
            $table->date('rate_as_of');
            $table->string('rate_source', 256);
            $table->string('rounding', 16);
            $table->uuid('journal_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'account_id'])->on('ledger_monetary_accounts');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_monetary_valuations');
            $table->index(['tenant_id', 'book_id', 'account_id', 'sequence'], 'ledger_monetary_history');
            PostgresSchema::timestamps($table);
        });
        PostgresSchema::install('currency', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['currency']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
