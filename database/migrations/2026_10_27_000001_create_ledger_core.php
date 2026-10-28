<?php

declare(strict_types=1);

use Brick\Money\IsoCurrencyProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = [
        'ledger_currency_definitions', 'ledger_currencies', 'ledger_exchange_rates', 'ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties',
        'ledger_periods', 'ledger_dimension_values', 'ledger_account_dimensions', 'ledger_journals', 'ledger_postings', 'ledger_open_items',
        'ledger_allocations', 'ledger_write_offs', 'ledger_allowance_runs', 'ledger_schedules', 'ledger_schedule_runs', 'ledger_schedule_cancellations',
        'ledger_recurring_journals', 'ledger_recurring_runs', 'ledger_recurring_cancellations', 'ledger_year_closures',
        'ledger_bank_statements', 'ledger_bank_lines', 'ledger_bank_matches', 'ledger_bank_voids', 'ledger_batches', 'ledger_batch_members',
        'ledger_audit_events', 'ledger_configurations', 'ledger_statement_runs', 'ledger_budgets', 'ledger_events',
    ];

    public function up(): void
    {
        PostgresSchema::assertSupported();
        Schema::create('ledger_currency_definitions', function (Blueprint $table): void {
            PostgresSchema::identity($table, tenant: false);
            $table->string('code', 16)->unique();
            $table->smallInteger('scale');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_currencies', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->string('code', 16);
            $table->smallInteger('scale');
            $table->unique(['tenant_id', 'code']);
            PostgresSchema::timestamps($table);
        });
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
        Schema::create('ledger_entities', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->string('name', 256);
            $table->char('country', 2);
            $table->unique(['tenant_id', 'id']);
            PostgresSchema::timestamps($table, mutable: true, catalog: true);
        });
        Schema::create('ledger_books', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->uuid('entity_id');
            $table->string('name', 256);
            $table->string('currency_code', 16);
            $table->bigInteger('next_sequence')->default(0);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'entity_id', 'name']);
            $table->foreign(['tenant_id', 'entity_id'])->references(['tenant_id', 'id'])->on('ledger_entities');
            $table->foreign(['tenant_id', 'currency_code'])->references(['tenant_id', 'code'])->on('ledger_currencies');
            PostgresSchema::timestamps($table, mutable: true, catalog: true);
        });
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('code', 64);
            $table->string('name', 256);
            $table->string('type', 16);
            $table->string('normal_side', 8);
            $table->string('currency_code', 16)->nullable();
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_group')->default(false);
            $table->boolean('is_control')->default(false);
            $table->boolean('active')->default(true);
            $table->string('cash_flow', 16)->nullable();
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'code']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            $table->foreign(['tenant_id', 'currency_code'])->references(['tenant_id', 'code'])->on('ledger_currencies');
            $table->foreign(['tenant_id', 'book_id', 'parent_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table, mutable: true, catalog: true);
        });
        Schema::create('ledger_parties', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->uuid('entity_id');
            $table->string('reference', 128);
            $table->string('name', 256);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'entity_id', 'reference']);
            $table->foreign(['tenant_id', 'entity_id'])->references(['tenant_id', 'id'])->on('ledger_entities');
            PostgresSchema::timestamps($table, mutable: true, catalog: true);
        });
        Schema::create('ledger_periods', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('name', 128);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('closed')->default(false);
            $table->string('change_reason', 1024)->default('Period opened');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'name']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            PostgresSchema::timestamps($table, mutable: true);
        });
        Schema::create('ledger_dimension_values', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->uuid('entity_id');
            $table->string('dimension', 64);
            $table->string('value', 128);
            $table->unique(['tenant_id', 'entity_id', 'dimension', 'value']);
            $table->foreign(['tenant_id', 'entity_id'])->references(['tenant_id', 'id'])->on('ledger_entities');
            PostgresSchema::timestamps($table, mutable: true, catalog: true);
        });
        Schema::create('ledger_account_dimensions', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('account_id');
            $table->string('dimension', 64);
            $table->unique(['tenant_id', 'book_id', 'account_id', 'dimension']);
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_journals', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->timestampTz('recorded_at', 6);
            $table->bigInteger('sequence');
            $table->string('description', 1024);
            $table->jsonb('postings');
            $table->jsonb('metadata')->default('{}');
            $table->string('actor_id', 128);
            $table->string('firm_id', 128)->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->boolean('closing')->default(false);
            $table->string('source_type', 64)->nullable();
            $table->string('source_reference', 128)->nullable();
            $table->jsonb('policy_versions')->default('[]');
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'sequence']);
            $table->unique(['tenant_id', 'book_id', 'source_type', 'source_reference']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->index(['tenant_id', 'book_id', 'effective_on', 'recorded_at'], 'ledger_journals_dates');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_postings', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('journal_id');
            $table->integer('line_no');
            $table->uuid('account_id');
            $table->string('side', 8);
            $table->decimal('amount_minor', 38, 0);
            $table->decimal('functional_minor', 38, 0);
            $table->string('currency_code', 16);
            $table->decimal('rate', 38, 18);
            $table->date('rate_as_of');
            $table->string('rate_source', 256);
            $table->string('rounding', 16);
            $table->uuid('party_id')->nullable();
            $table->date('due_on')->nullable();
            $table->jsonb('dimensions')->default('{}');
            $table->string('description', 1024)->default('');
            $table->boolean('valuation')->default(false);
            $table->unique(['tenant_id', 'book_id', 'journal_id', 'line_no']);
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'currency_code'])->references(['tenant_id', 'code'])->on('ledger_currencies');
            $table->foreign(['tenant_id', 'party_id'])->references(['tenant_id', 'id'])->on('ledger_parties');
            $table->index(['tenant_id', 'book_id', 'account_id'], 'ledger_postings_account');
            $table->index('dimensions', 'ledger_postings_dimensions', 'gin');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_open_items', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('journal_id');
            $table->integer('line_no');
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'journal_id', 'line_no']);
            $table->foreign(['tenant_id', 'book_id', 'journal_id', 'line_no'])->references(['tenant_id', 'book_id', 'journal_id', 'line_no'])->on('ledger_postings');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_allocations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('debit_item_id');
            $table->uuid('credit_item_id');
            $table->decimal('amount_minor', 38, 0);
            $table->decimal('debit_functional_minor', 38, 0);
            $table->decimal('credit_functional_minor', 38, 0);
            $table->date('effective_on');
            PostgresSchema::evidence($table);
            $table->uuid('reverses_id')->nullable();
            $table->uuid('fx_journal_id')->nullable();
            $table->string('reason', 1024);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->unique(['tenant_id', 'book_id', 'fx_journal_id']);
            $table->foreign(['tenant_id', 'book_id', 'debit_item_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_open_items');
            $table->foreign(['tenant_id', 'book_id', 'credit_item_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_open_items');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_allocations');
            $table->foreign(['tenant_id', 'book_id', 'fx_journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->index(['tenant_id', 'book_id', 'debit_item_id'], 'ledger_allocations_debit');
            $table->index(['tenant_id', 'book_id', 'credit_item_id'], 'ledger_allocations_credit');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_write_offs', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('item_id');
            $table->uuid('account_id');
            $table->decimal('amount_minor', 38, 0);
            $table->date('effective_on');
            $table->uuid('journal_id');
            $table->uuid('allocation_id');
            $table->uuid('reverses_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'allocation_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'item_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_open_items');
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'allocation_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_allocations');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_write_offs');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_allowance_runs', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('control_account_id');
            $table->uuid('allowance_account_id');
            $table->uuid('expense_account_id');
            $table->date('effective_on');
            $table->jsonb('matrix');
            $table->jsonb('exposure');
            $table->rawColumn('required_minor', 'numeric');
            $table->rawColumn('previous_minor', 'numeric');
            $table->uuid('journal_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'book_id', 'control_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'allowance_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'expense_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_schedules', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('description', 1024);
            $table->uuid('debit_account_id');
            $table->uuid('credit_account_id');
            $table->decimal('amount_minor', 38, 0);
            $table->jsonb('plan');
            $table->string('policy_version', 128);
            $table->jsonb('dimensions');
            $table->timestampTz('recorded_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            $table->foreign(['tenant_id', 'book_id', 'debit_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'credit_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_schedule_runs', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('schedule_id');
            $table->integer('line_no');
            $table->uuid('journal_id');
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'schedule_id', 'line_no']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'book_id', 'schedule_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_schedules');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_schedule_cancellations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('schedule_id');
            $table->string('reason', 1024);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'schedule_id']);
            $table->foreign(['tenant_id', 'book_id', 'schedule_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_schedules');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_recurring_journals', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('description', 1024);
            $table->jsonb('postings');
            $table->jsonb('metadata');
            $table->string('rate_type', 16);
            $table->string('unit', 8);
            $table->integer('every');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->integer('occurrences')->nullable();
            $table->integer('due_after_days')->nullable();
            $table->boolean('auto_reverse');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_recurring_runs', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('recurring_id');
            $table->integer('occurrence');
            $table->string('kind', 8);
            $table->uuid('journal_id');
            $table->unique(['tenant_id', 'book_id', 'recurring_id', 'occurrence', 'kind']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'book_id', 'recurring_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_recurring_journals');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_recurring_cancellations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('recurring_id');
            $table->date('effective_on');
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'recurring_id']);
            $table->foreign(['tenant_id', 'book_id', 'recurring_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_recurring_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_year_closures', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->uuid('journal_id')->nullable();
            $table->uuid('retained_account_id');
            $table->string('reason', 1024);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'retained_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_bank_statements', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('account_id');
            $table->string('reference', 128);
            $table->char('fingerprint', 64);
            $table->date('starts_on');
            $table->date('effective_on');
            $table->decimal('opening_minor', 38, 0);
            $table->decimal('closing_minor', 38, 0);
            $table->jsonb('movements');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'account_id', 'reference']);
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_bank_lines', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('statement_id');
            $table->uuid('account_id');
            $table->integer('line_no');
            $table->string('external_id', 128);
            $table->date('value_on');
            $table->decimal('amount_minor', 38, 0);
            $table->string('description', 1024);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'statement_id', 'line_no']);
            $table->unique(['tenant_id', 'book_id', 'statement_id', 'external_id']);
            $table->foreign(['tenant_id', 'book_id', 'statement_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_bank_statements');
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->index(['tenant_id', 'book_id', 'account_id', 'external_id'], 'ledger_bank_external_id');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_bank_matches', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->uuid('bank_line_id');
            $table->uuid('journal_id');
            $table->integer('line_no');
            $table->decimal('amount_minor', 38, 0);
            $table->date('effective_on');
            PostgresSchema::evidence($table);
            $table->string('reason', 1024);
            $table->uuid('reverses_id')->nullable();
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'bank_line_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_bank_lines');
            $table->foreign(['tenant_id', 'book_id', 'journal_id', 'line_no'])->references(['tenant_id', 'book_id', 'journal_id', 'line_no'])->on('ledger_postings');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_bank_matches');
            $table->index(['tenant_id', 'book_id', 'journal_id', 'line_no'], 'ledger_bank_matches_posting');
            $table->index(['tenant_id', 'book_id', 'bank_line_id'], 'ledger_bank_matches_line');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_bank_voids', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('statement_id');
            $table->date('effective_on');
            PostgresSchema::evidence($table);
            $table->string('reason', 1024);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'statement_id']);
            $table->foreign(['tenant_id', 'book_id', 'statement_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_bank_statements');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_batches', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->string('description', 1024);
            $table->jsonb('members');
            $table->uuid('reverses_id')->nullable();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'reverses_id'])->references(['tenant_id', 'id'])->on('ledger_batches');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_batch_members', function (Blueprint $table): void {
            PostgresSchema::identity($table);
            $table->uuid('batch_id');
            $table->uuid('book_id');
            $table->uuid('journal_id');
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->foreign(['tenant_id', 'batch_id'])->references(['tenant_id', 'id'])->on('ledger_batches');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_audit_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, audit: true);
            $table->string('table_name', 64);
            $table->string('operation', 8);
            $table->jsonb('before_value')->nullable();
            $table->jsonb('after_value')->nullable();
            $table->string('actor_id', 128);
            $table->string('firm_id', 128)->nullable();
            $table->timestampTz('recorded_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->unique(['tenant_id', 'id']);
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_configurations', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('name', 128);
            $table->string('version', 128);
            $table->jsonb('definition_json');
            $table->char('definition_hash', 64);
            $table->jsonb('account_ids');
            $table->jsonb('roles');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'name', 'version']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_statement_runs', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->jsonb('result_json');
            $table->char('result_hash', 64);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_budgets', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('name', 128);
            $table->string('version', 128);
            $table->char('fingerprint', 64);
            $table->jsonb('lines');
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'name', 'version']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_events', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->bigInteger('sequence');
            $table->bigInteger('audit_id');
            $table->unique(['tenant_id', 'book_id', 'sequence']);
            $table->unique(['tenant_id', 'audit_id']);
            $table->foreign(['tenant_id', 'book_id'])->references(['tenant_id', 'id'])->on('ledger_books');
            $table->foreign(['tenant_id', 'audit_id'])->references(['tenant_id', 'id'])->on('ledger_audit_events');
            PostgresSchema::timestamps($table);
        });

        PostgresSchema::install('storage');
        PostgresSchema::install('schema', self::TABLES,
            catalog: ['ledger_entities', 'ledger_books', 'ledger_accounts', 'ledger_parties', 'ledger_dimension_values', 'ledger_periods', 'ledger_account_dimensions'],
            evidence: ['ledger_configurations', 'ledger_statement_runs']);
        foreach (IsoCurrencyProvider::getInstance()->getAvailableCurrencies() as $currency) {
            DB::table('ledger_currency_definitions')->insert(['code' => $currency->getCurrencyCode(), 'scale' => $currency->getDefaultFractionDigits()]);
        }
        DB::unprepared('CREATE TRIGGER stage_10_reference_insert BEFORE INSERT ON ledger_currency_definitions FOR EACH ROW EXECUTE FUNCTION ledger_immutable()');
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['schema', 'storage']);
        PostgresSchema::dropTables(self::TABLES);
    }
};
