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

        // Backfill: every already-allocated row gets a stamp. Use created_at (NOT
        // updated_at) deliberately — this makes legacy rows mathematically IMMUNE to the
        // NF-1 late-salvage window: that window requires created_at <= $from AND
        // fiscal_seq_allocated_at ∈ ($from,$to]; with allocated_at = created_at the two
        // conditions are mutually exclusive, so no already-counted legacy row can ever be
        // double-swept into a future Z. (updated_at would risk exactly that if a legacy row
        // were touched after its Z closed.) Late-salvage only fires for FUTURE rows whose
        // saving-hook stamps the true allocation instant.
        DB::table('orders')
            ->whereNotNull('fiscal_sequence_no')
            ->whereNull('fiscal_seq_allocated_at')
            ->update(['fiscal_seq_allocated_at' => DB::raw('created_at')]);
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
