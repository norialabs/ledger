<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_assets', 'ledger_asset_events'];

    public function up(): void
    {
        Schema::create('ledger_assets', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('reference', 128);
            $table->date('effective_on');
            $table->date('available_on');
            $table->rawColumn('cost_minor', 'numeric');
            $table->rawColumn('residual_minor', 'numeric');
            $table->rawColumn('opening_depreciation_minor', 'numeric')->default(0);
            $table->rawColumn('opening_impairment_minor', 'numeric')->default(0);
            $table->rawColumn('opening_surplus_minor', 'numeric')->default(0);
            $table->rawColumn('opening_loss_minor', 'numeric')->nullable();
            $table->uuid('surplus_account_id')->nullable();
            $table->uuid('cost_account_id');
            $table->uuid('depreciation_account_id');
            $table->uuid('impairment_account_id');
            $table->uuid('expense_account_id');
            $table->uuid('counter_account_id');
            $table->uuid('journal_id');
            $table->jsonb('plan');
            $table->jsonb('dimensions');
            $table->string('stub_convention', 32);
            $table->string('policy_version', 128);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'reference']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            foreach (['cost_account_id', 'depreciation_account_id', 'impairment_account_id', 'expense_account_id', 'counter_account_id', 'surplus_account_id'] as $column) {
                $table->foreign(['tenant_id', 'book_id', $column])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            }
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_asset_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('asset_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->string('kind', 16);
            $table->rawColumn('amount_minor', 'numeric')->default(0);
            $table->rawColumn('cost_delta', 'numeric')->default(0);
            $table->rawColumn('fraction', 'numeric')->nullable();
            $table->rawColumn('depreciation_minor', 'numeric');
            $table->rawColumn('impairment_minor', 'numeric');
            $table->smallInteger('disposal_delta');
            $table->uuid('counter_account_id')->nullable();
            $table->uuid('gain_account_id')->nullable();
            $table->uuid('loss_account_id')->nullable();
            $table->uuid('surplus_account_id')->nullable();
            $table->uuid('retained_account_id')->nullable();
            $table->rawColumn('surplus_delta', 'numeric')->default(0);
            $table->rawColumn('loss_delta', 'numeric')->default(0);
            $table->rawColumn('unimpaired_minor', 'numeric')->nullable();
            $table->jsonb('plan')->nullable();
            $table->jsonb('dimensions')->nullable();
            $table->rawColumn('residual_minor', 'numeric')->nullable();
            $table->uuid('plan_id')->nullable();
            $table->integer('line_no')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->string('policy_version', 128);
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'asset_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_assets');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_asset_events');
            foreach (['counter_account_id', 'gain_account_id', 'loss_account_id', 'surplus_account_id', 'retained_account_id'] as $column) {
                $table->foreign(['tenant_id', 'book_id', $column])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            }
            $table->index(['tenant_id', 'book_id', 'asset_id', 'sequence'], 'ledger_asset_history');
            PostgresSchema::timestamps($table);
        });
        PostgresSchema::install('assets', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['assets']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
