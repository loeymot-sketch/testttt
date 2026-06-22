<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
 *
 * Adds a nullable `fiscal_alloc_error_at` timestamp to `orders` so the
 * kiosk-paid finalize path (FrontendOrderService::finalizePaidKioskOrder)
 * can flag an order whose NF525 sequence allocation just failed without
 * losing the PAID+PENDING state — and so a retry cron
 * (foodking:fiscal:retry-alloc) can later pick the row up and retry the
 * allocation without manual operator intervention.
 *
 * Backstory (audit iter13 ORDER-PATH P1):
 *   When `FiscalSequenceService::next()` failed inside the
 *   `finalizePaidKioskOrder` DB transaction, the throw rolled back the
 *   status promotion AND propagated up to the caller. If the caller
 *   crashed (or there is no caller-side retry, e.g. webhook one-shot),
 *   the order was left as `payment_status=PAID + status=PENDING +
 *   fiscal_sequence_no=NULL` with no marker — invisible to KDS, excluded
 *   from Z aggregation, unrecoverable without manual POS override.
 *
 * Strategy:
 *   - Nullable timestamp keeps the column rollback-safe and additive.
 *   - The retry command WHEREs on `fiscal_alloc_error_at IS NOT NULL`,
 *     so legacy orphan rows (pre-flag) don't suddenly enter the retry
 *     loop unless a future backfill explicitly tags them.
 *   - On a successful retry the column is reset to NULL by the same
 *     finalize path, so it doubles as "currently degraded" vs "happy"
 *     status without a separate enum.
 *
 * Idempotent / driver-agnostic:
 *   - `Schema::hasColumn` guard makes the up() rerunnable.
 *   - No index added — the retry cron filters on `payment_status=PAID
 *     AND fiscal_sequence_no IS NULL AND fiscal_alloc_error_at IS NOT
 *     NULL`, which is a small, sparse predicate by design (only failing
 *     rows live in this set). Adding an index now would be premature
 *     and would conflict with the partial-index gap on SQLite/MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('orders', 'fiscal_alloc_error_at')) {
                $table->timestamp('fiscal_alloc_error_at')
                    ->nullable()
                    ->after('fiscal_sequence_no');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'fiscal_alloc_error_at')) {
                $table->dropColumn('fiscal_alloc_error_at');
            }
        });
    }
};
