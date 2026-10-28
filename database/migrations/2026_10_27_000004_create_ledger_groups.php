<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_associates', 'ledger_associate_events', 'ledger_consolidation_runs'];

    public function up(): void
    {
        Schema::create('ledger_associates', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('investee_entity_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('reference', 128);
            $table->date('effective_on');
            $table->rawColumn('ownership_fraction', 'numeric');
            $table->rawColumn('cost_minor', 'numeric');
            $table->rawColumn('fair_value_net_assets_minor', 'numeric');
            $table->rawColumn('carrying_minor', 'numeric');
            $table->rawColumn('goodwill_minor', 'numeric');
            $table->rawColumn('bargain_minor', 'numeric');
            $table->uuid('investment_account_id');
            $table->uuid('funding_account_id');
            $table->uuid('profit_account_id');
            $table->uuid('oci_account_id');
            $table->uuid('dividend_account_id');
            $table->uuid('impairment_account_id');
            $table->uuid('loss_liability_account_id')->nullable();
            $table->string('influence_basis', 1024);
            $table->string('policy_version', 128);
            $table->string('dividend_cash_flow', 16);
            $table->jsonb('dimensions')->default('{}');
            $table->uuid('journal_id')->nullable();
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reference']);
            $table->unique(['tenant_id', 'book_id', 'investment_account_id']);
            $table->unique(['tenant_id', 'book_id', 'loss_liability_account_id']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'investee_entity_id'])->references(['tenant_id', 'id'])->on('ledger_entities');
            foreach (['investment_account_id', 'funding_account_id', 'profit_account_id', 'oci_account_id', 'dividend_account_id', 'impairment_account_id', 'loss_liability_account_id'] as $column) {
                $table->foreign(['tenant_id', 'book_id', $column])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            }
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_associate_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('associate_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->string('kind', 16);
            $table->rawColumn('source_minor', 'numeric');
            $table->rawColumn('share_minor', 'numeric');
            $table->rawColumn('carrying_delta', 'numeric');
            $table->rawColumn('liability_delta', 'numeric');
            $table->rawColumn('unrecognized_profit_delta', 'numeric');
            $table->rawColumn('unrecognized_oci_delta', 'numeric');
            $table->rawColumn('impairment_delta', 'numeric');
            $table->rawColumn('obligation_limit_minor', 'numeric');
            $table->rawColumn('prior_limit_minor', 'numeric');
            $table->rawColumn('unimpaired_minor', 'numeric')->nullable();
            $table->uuid('counter_account_id')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->jsonb('evidence');
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'associate_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_associates');
            $table->foreign(['tenant_id', 'book_id', 'counter_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_associate_events');
            $table->index(['tenant_id', 'book_id', 'associate_id', 'sequence'], 'ledger_associate_history');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_consolidation_runs', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->jsonb('inputs');
            $table->jsonb('result');
            $table->char('result_hash', 64);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            PostgresSchema::timestamps($table);
        });
        PostgresSchema::install('groups', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['groups']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
