<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [NF-1-prereq 2026-06-15 — GOAL 2.0 Phase 2, FISC-EXH-01].
 *
 * Records WHEN a fiscal_sequence_no was allocated, distinct from created_at (order
 * placement). The frozen ZReportService late-salvage block (NF-1, behind LOCK_FISC-EXH-01)
 * keys on this column to sweep a sale whose seq was allocated AFTER its created_at window's
 * Z closed (e.g. a COD seq allocated by the retry-cron post-midnight) into the CURRENT
 * open Z — closing the cross-Z-window orphan gap (FISC-EXH-01).
 *
 * Additive + nullable. Backfills existing allocated rows with updated_at as the best
 * available proxy so the late-salvage window never misses a pre-migration sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'fiscal_seq_allocated_at')) {
                $table->timestamp('fiscal_seq_allocated_at')->nullable()->after('fiscal_alloc_error_at');
            }
        });

        // Backfill: every row that already carries a sequence gets a stamp so the
        // late-salvage window (which keys on this column) can reason about it. updated_at
        // is the closest available allocation-time proxy.
        DB::table('orders')
            ->whereNotNull('fiscal_sequence_no')
            ->whereNull('fiscal_seq_allocated_at')
            ->update(['fiscal_seq_allocated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'fiscal_seq_allocated_at')) {
                $table->dropColumn('fiscal_seq_allocated_at');
            }
        });
    }
};
