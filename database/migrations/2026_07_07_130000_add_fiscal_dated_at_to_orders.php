<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT — P1 deferred-order Z-membership 2026-07-07]
 *
 * Adds a nullable `fiscal_dated_at` timestamp to `orders`: the instant the
 * NF525 `fiscal_sequence_no` was ALLOCATED (i.e. the moment the receipt
 * became a numbered fiscal event).
 *
 * WHY (P1 bug):
 *   A deferred-payment order (COUNTER_DEFERRED — kiosk Plan B, walk-in, phone)
 *   receives its `fiscal_sequence_no` at ENCAISSEMENT
 *   (PaymentService::confirmCounterPayment), NOT at creation. But
 *   ZReportService::aggregate() partitioned Z-membership by `created_at`.
 *   An order created inside Z_n's window (before its close) but settled
 *   AFTER Z_{n+1} opened fell into NO signed Z:
 *     - at Z_n close it was still unsettled (fiscal_sequence_no NULL → excluded);
 *     - at Z_{n+1} close its created_at <= from (C33 from = closed_at of Z_n)
 *       → `created_at > from` FALSE → excluded again.
 *   → a numbered receipt in zero signed Z = a SILENT NF525 gap-free violation.
 *
 * FIX:
 *   Stamp `fiscal_dated_at = now()` wherever the sequence is allocated LATE
 *   (confirmCounterPayment + the deferred UNPAID→PAID edges in OrderService).
 *   aggregate() then keys the Z window on COALESCE(fiscal_dated_at, created_at):
 *   deferred orders belong to the Z whose window contains their ALLOCATION
 *   instant; non-deferred orders (fiscal_dated_at NULL) keep the created_at
 *   behaviour byte-for-byte (COALESCE fallback) — historical rows unchanged.
 *
 * Additive / rollback-safe:
 *   - nullable, no default backfill (legacy rows stay NULL → created_at fallback);
 *   - Schema::hasColumn guard makes up()/down() idempotent and driver-agnostic
 *     (MySQL prod + SQLite tests);
 *   - no index: the column is read only inside the Z aggregation window
 *     predicate (already bounded by branch_id + fiscal_sequence_no filters),
 *     not as a standalone lookup key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'fiscal_dated_at')) {
                $table->timestamp('fiscal_dated_at')
                    ->nullable()
                    ->after('fiscal_alloc_error_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'fiscal_dated_at')) {
                $table->dropColumn('fiscal_dated_at');
            }
        });
    }
};
