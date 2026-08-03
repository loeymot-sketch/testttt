<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // [V14 C-α / FINDING C-8 P2] Add column AND index independently to handle
        // the case where the column already exists from another migration but
        // the index is missing.
        if (! Schema::hasColumn('items', 'barcode')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('barcode', 64)->nullable()->after('slug');
            });
        }

        if (! $this->indexExists('items', 'items_barcode_idx')) {
            try {
                Schema::table('items', function (Blueprint $table) {
                    $table->index('barcode', 'items_barcode_idx');
                });
            } catch (\Throwable $e) {
                // Defensive: some drivers (sqlite in-memory tests) may already
                // have an implicit index. Swallow to keep migration idempotent.
            }
        }
    }

    public function down(): void
    {
        if ($this->indexExists('items', 'items_barcode_idx')) {
            try {
                Schema::table('items', function (Blueprint $table) {
                    $table->dropIndex('items_barcode_idx');
                });
            } catch (\Throwable $e) {
                // Idempotent rollback.
            }
        }

        if (Schema::hasColumn('items', 'barcode')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('barcode');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes($table);
            foreach ($indexes as $idx) {
                if (strtolower($idx->getName()) === strtolower($indexName)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
