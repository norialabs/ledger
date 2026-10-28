<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Noria\Ledger\Storage\PostgresSchema;

return new class extends Migration
{
    private const TABLES = ['ledger_inventory_pools', 'ledger_inventory_moves', 'ledger_inventory_layer_entries', 'ledger_inventory_transfers', 'ledger_productions'];

    public function up(): void
    {
        Schema::create('ledger_inventory_pools', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('reference', 128);
            $table->string('sku', 128);
            $table->char('fingerprint', 64);
            $table->uuid('account_id');
            $table->string('method', 16);
            $table->jsonb('dimensions');
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'reference']);
            $table->foreign(['tenant_id', 'book_id', 'account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_inventory_moves', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('pool_id');
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->string('kind', 16);
            $table->rawColumn('quantity', 'numeric');
            $table->rawColumn('value_minor', 'numeric');
            $table->rawColumn('commercial_minor', 'numeric')->nullable();
            $table->uuid('variance_account_id')->nullable();
            $table->uuid('counter_account_id');
            $table->uuid('journal_id')->nullable();
            $table->jsonb('plan');
            $table->uuid('reverses_id')->nullable();
            $table->uuid('returns_id')->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->uuid('production_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'journal_id']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->foreign(['tenant_id', 'book_id', 'pool_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_pools');
            $table->foreign(['tenant_id', 'book_id', 'counter_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'variance_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'journal_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_journals');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->foreign(['tenant_id', 'book_id', 'returns_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->index(['tenant_id', 'book_id', 'pool_id', 'sequence'], 'ledger_inventory_pool_history');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_inventory_layer_entries', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->uuid('movement_id');
            $table->uuid('layer_id');
            $table->rawColumn('quantity', 'numeric');
            $table->rawColumn('value_minor', 'numeric');
            $table->unique(['tenant_id', 'book_id', 'movement_id', 'layer_id']);
            $table->foreign(['tenant_id', 'book_id', 'movement_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->foreign(['tenant_id', 'book_id', 'layer_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->index(['tenant_id', 'book_id', 'layer_id'], 'ledger_inventory_layer_history');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_inventory_transfers', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->uuid('source_pool_id');
            $table->uuid('destination_pool_id');
            $table->uuid('issue_id');
            $table->uuid('receipt_id');
            $table->rawColumn('quantity', 'numeric');
            $table->rawColumn('value_minor', 'numeric');
            $table->uuid('clearing_account_id');
            $table->uuid('reverses_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->unique(['tenant_id', 'book_id', 'issue_id']);
            $table->unique(['tenant_id', 'book_id', 'receipt_id']);
            $table->foreign(['tenant_id', 'book_id', 'source_pool_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_pools');
            $table->foreign(['tenant_id', 'book_id', 'destination_pool_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_pools');
            $table->foreign(['tenant_id', 'book_id', 'issue_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->foreign(['tenant_id', 'book_id', 'receipt_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_transfers');
            $table->foreign(['tenant_id', 'book_id', 'clearing_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            PostgresSchema::timestamps($table);
        });
        Schema::create('ledger_productions', function (Blueprint $table): void {
            PostgresSchema::identity($table, book: true);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->date('effective_on');
            $table->uuid('output_pool_id');
            $table->uuid('output_movement_id');
            $table->rawColumn('output_quantity', 'numeric');
            $table->rawColumn('output_value_minor', 'numeric');
            $table->rawColumn('conversion_minor', 'numeric')->default(0);
            $table->uuid('wip_account_id');
            $table->jsonb('inputs');
            $table->uuid('reverses_id')->nullable();
            $table->string('reason', 1024);
            PostgresSchema::evidence($table);
            $table->unique(['tenant_id', 'book_id', 'id']);
            $table->unique(['tenant_id', 'book_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'book_id', 'reverses_id']);
            $table->unique(['tenant_id', 'book_id', 'output_movement_id']);
            $table->foreign(['tenant_id', 'book_id', 'output_pool_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_pools');
            $table->foreign(['tenant_id', 'book_id', 'output_movement_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_inventory_moves');
            $table->foreign(['tenant_id', 'book_id', 'wip_account_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_accounts');
            $table->foreign(['tenant_id', 'book_id', 'reverses_id'])->references(['tenant_id', 'book_id', 'id'])->on('ledger_productions');
            PostgresSchema::timestamps($table);
        });
        Schema::table('ledger_inventory_moves', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'book_id', 'transfer_id'], 'ledger_inventory_transfer_fk')->references(['tenant_id', 'book_id', 'id'])
                ->on('ledger_inventory_transfers')->deferrable()->initiallyImmediate(false);
            $table->foreign(['tenant_id', 'book_id', 'production_id'], 'ledger_inventory_production_fk')->references(['tenant_id', 'book_id', 'id'])
                ->on('ledger_productions')->deferrable()->initiallyImmediate(false);
        });
        PostgresSchema::install('inventory', self::TABLES);
    }

    public function down(): void
    {
        PostgresSchema::dropFunctions(['inventory']);
        Schema::table('ledger_inventory_moves', function (Blueprint $table): void {
            $table->dropForeign('ledger_inventory_transfer_fk');
            $table->dropForeign('ledger_inventory_production_fk');
        });
        PostgresSchema::dropTables(self::TABLES);
    }
};
