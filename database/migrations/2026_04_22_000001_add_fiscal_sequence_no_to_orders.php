<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [POS-9.4.1 / POS-GA-F-38]
 *
 * Adds an immutable, strictly monotonic fiscal sequence number per branch
 * to `orders`, as required by NF525 / Loi Finance 2018 (FR anti-fraude
 * TVA). The column is nullable for backfill, but enforced unique per
 * `(branch_id, fiscal_sequence_no)` so tests can guarantee "no gap" on
 * a given branch's sequence. The actual allocation is performed by
 * {@see \App\Services\Fiscal\FiscalSequenceService::next()}.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'fiscal_sequence_no')) {
                // UNSIGNED BIGINT NULL — backfill-friendly, later NOT NULL when all
                // legacy orders are resealed in a dedicated migration.
                $table->unsignedBigInteger('fiscal_sequence_no')->nullable()->after('total');
            }
        });

        // Unique composite only makes sense once both columns exist. Try to add
        // it, swallow "already exists" so the migration is idempotent without
        // requiring doctrine/dbal at runtime.
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique(
                    ['branch_id', 'fiscal_sequence_no'],
                    'orders_branch_fiscal_seq_unique'
                );
            });
        } catch (\Throwable $e) {
            // index already present — idempotent up().
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropUnique('orders_branch_fiscal_seq_unique');
            } catch (\Throwable $e) {
                // index already absent — nothing to do
            }

            if (Schema::hasColumn('orders', 'fiscal_sequence_no')) {
                $table->dropColumn('fiscal_sequence_no');
            }
        });
    }
};
