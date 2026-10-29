<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_budget_policies', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('budget_id');
            $table->string('version', 128);
            $table->boolean('reject_over_budget');
            $table->boolean('reject_unbudgeted');
            PostgresSchema::evidence($table);
            PostgresSchema::timestamps($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'version']);
            $table->foreign(['tenant_id', 'book_id', 'budget_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_budgets');
        });
        Schema::create('ledger_commitments', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('budget_id');
            $table->integer('line_no');
            $table->uuid('commitment_id')->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->string('kind', 16);
            $table->rawColumn('amount_minor', 'numeric');
            $table->date('effective_on');
            $table->string('idempotency_key', 128);
            $table->string('fingerprint', 64);
            $table->string('reason', 1024);
            $table->uuid('policy_id');
            PostgresSchema::evidence($table);
            PostgresSchema::timestamps($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->unique(['tenant_id', 'book_id', 'journal_id', 'commitment_id'], 'ledger_commitment_journal_unique');
            $table->index(['tenant_id', 'book_id', 'budget_id', 'line_no', 'sequence'], 'ledger_commitment_line');
            foreach (['budget_id' => 'ledger_budgets', 'policy_id' => 'ledger_budget_policies', 'commitment_id' => 'ledger_commitments', 'reverses_id' => 'ledger_commitments', 'journal_id' => 'ledger_journals'] as $column => $target) {
                $table->foreign(['tenant_id', 'book_id', $column])->references(['tenant_id', 'book_id', 'id'])->on($target);
            }
        });
        Schema::table('ledger_journals', function (Blueprint $table): void {
            $table->uuid('budget_policy_id')->nullable();
            $table->foreign(['tenant_id', 'book_id', 'budget_policy_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_budget_policies');
        });
        PostgresSchema::install('budgets', ['ledger_budget_policies', 'ledger_commitments']);
    }

    public function down(): void
    {
        PostgresSchema::dropTriggers(['ledger_journals' => ['stage_80_release_commitments', 'stage_40_budget_policy']]);
        Schema::table('ledger_journals', function (Blueprint $table): void {
            $table->dropColumn('budget_policy_id');
        });
        PostgresSchema::dropTables(['ledger_budget_policies', 'ledger_commitments']);
        PostgresSchema::dropFunctions(['budgets']);
    }
};
