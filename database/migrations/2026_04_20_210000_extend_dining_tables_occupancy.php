<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            if (! Schema::hasColumn('dining_tables', 'occupancy_status')) {
                $table->string('occupancy_status', 16)->nullable()->default('free')->after('status');
            }

            if (! Schema::hasColumn('dining_tables', 'occupied_order_id')) {
                $table->unsignedBigInteger('occupied_order_id')->nullable()->after('occupancy_status');
            }

            if (! Schema::hasColumn('dining_tables', 'occupied_at')) {
                $table->timestamp('occupied_at')->nullable()->after('occupied_order_id');
            }
        });

        DB::table('dining_tables')
            ->whereNull('occupancy_status')
            ->update(['occupancy_status' => 'free']);

        if (! $this->indexExists('dining_tables', 'dining_tables_branch_occupancy_idx')) {
            try {
                Schema::table('dining_tables', function (Blueprint $table) {
                    $table->index(['branch_id', 'occupancy_status'], 'dining_tables_branch_occupancy_idx');
                });
            } catch (\Throwable $e) {
                // Defensive: keep migration idempotent across drivers.
            }
        }
    }

    public function down(): void
    {
        if ($this->indexExists('dining_tables', 'dining_tables_branch_occupancy_idx')) {
            try {
                Schema::table('dining_tables', function (Blueprint $table) {
                    $table->dropIndex('dining_tables_branch_occupancy_idx');
                });
            } catch (\Throwable $e) {
                // Idempotent rollback.
            }
        }

        Schema::table('dining_tables', function (Blueprint $table) {
            if (Schema::hasColumn('dining_tables', 'occupied_at')) {
                $table->dropColumn('occupied_at');
            }

            if (Schema::hasColumn('dining_tables', 'occupied_order_id')) {
                $table->dropColumn('occupied_order_id');
            }

            if (Schema::hasColumn('dining_tables', 'occupancy_status')) {
                $table->dropColumn('occupancy_status');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $schemaManager = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $schemaManager->listTableIndexes($table);

            foreach ($indexes as $index) {
                if (strtolower($index->getName()) === strtolower($indexName)) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
