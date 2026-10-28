<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_finance_contracts', 'ledger_finance_events'];

    public function up(): void
    {
        Schema::create('ledger_finance_contracts', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('reference', 128);
            $table->string('kind', 16);
            $table->date('effective_on');
            $table->uuid('configuration_id');
            $table->jsonb('inputs');
            $table->jsonb('dimensions')->default('{}');
            $table->uuid('carrying_account_id');
            $table->uuid('allowance_account_id')->nullable();
            $table->uuid('asset_account_id')->nullable();
            $table->rawColumn('opening_minor', 'numeric');
            $table->uuid('journal_id');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reference']);
            $table->unique(['tenant_id', 'book_id', 'carrying_account_id', 'dimensions'], 'ledger_finance_carrying_bucket');
            $table->unique(['tenant_id', 'book_id', 'allowance_account_id', 'dimensions'], 'ledger_finance_allowance_bucket');
            $table->unique(['tenant_id', 'book_id', 'asset_account_id', 'dimensions'], 'ledger_finance_asset_bucket');
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'book_id', 'configuration_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_configurations');
            foreach (['carrying_account_id', 'allowance_account_id', 'asset_account_id'] as $column) {
                $table->foreign(['tenant_id', 'book_id', $column])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            }
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_finance_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('contract_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->string('kind', 16);
            $table->jsonb('inputs');
            $table->rawColumn('carrying_delta', 'numeric');
            $table->rawColumn('allowance_delta', 'numeric');
            $table->rawColumn('written_off_delta', 'numeric');
            $table->rawColumn('recovered_delta', 'numeric');
            $table->integer('stage');
            $table->integer('prior_stage');
            $table->uuid('journal_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'contract_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_finance_contracts');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_finance_events');
            $table->index(['tenant_id', 'book_id', 'contract_id', 'sequence'], 'ledger_finance_history');
            PostgresSchema::timestamps($table);
        });
        PostgresSchema::install('finance', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['finance']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
