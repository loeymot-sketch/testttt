<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [HEAL-A.3 / WAVE-L Z8 P0-1] Promote `orders.parent_order_id` from non-unique
 * INDEX to UNIQUE so a second counter-entry mirror against the same parent
 * order is rejected at the DATABASE level — defense-in-depth above the
 * RefundWithCounterEntryService:73-78 status-check guard which fires only on
 * the out-of-band `parent.status=RETURNED` flip path (NF525 immutability
 * makes the normal counter-entry path never mutate parent.status).
 *
 * Pre-existing INDEX shape (verified via SHOW INDEX 2026-05-19, dev DB):
 *   { Key_name: orders_parent_order_id_index, Non_unique: 1, Column: parent_order_id, Null: YES }
 *
 * Source migration: database/migrations/2026_05_06_200000_add_parent_order_id_to_orders.php:25
 *   - adds nullable parent_order_id column + FK(parent_order_id → orders.id) ON DELETE SET NULL
 *   - adds non-unique INDEX (Laravel default name)
 *
 * Pre-deploy GATE (executed 2026-05-19, dev DB): 0 duplicate parent_order_id pairs.
 *   SELECT parent_order_id, COUNT(*) AS dupes FROM orders
 *    WHERE parent_order_id IS NOT NULL GROUP BY parent_order_id HAVING COUNT(*)>1;
 *   → 0 rows. Safe to add UNIQUE without data cleanup.
 *
 * Semantics:
 *  - MySQL UNIQUE indexes allow MULTIPLE NULLs (parent.parent_order_id stays
 *    NULL for non-mirror orders — no clash).
 *  - SQLite ≥3.9 same semantics (CI test runs use SQLite by default).
 *  - PostgreSQL not targeted per config/database.php.
 *
 * Order of ops (verified via `migrate --pretend`):
 *  - UNIQUE is added FIRST so it can shoulder the FK index requirement
 *    when the non-unique INDEX is dropped. Without this ordering MySQL
 *    would refuse `DROP INDEX orders_parent_order_id_index` because the
 *    foreign key `orders.parent_order_id → orders.id` (from the 2026_05_06
 *    migration) requires at least one index on the column.
 *  - The old non-unique INDEX is then dropped (UNIQUE keeps the FK happy).
 *
 * NF525-adjacency:
 *  - `orders` table is the Z aggregate source.
 *  - NOT in deploy/ansible/site.yml:59-71 REVOKE list (which protects
 *    audit_logs, z_reports, cash_movements, cash_drawer_sessions,
 *    order_payments, domain_events, webhook_events — 7 tables).
 *  - NOT in CLAUDE.md §7 frozen scope (the §7 NF525 list names services:
 *    FiscalSequenceService, ZReportService, AuditLogService + audit_logs /
 *    z_reports DELETE triggers — orders DDL additive constraints not in scope).
 *  - Additive constraint on a nullable column is non-destructive — no
 *    column drop, no NF525-critical field touched (fiscal_sequence_no,
 *    order_serial_no, audit/HMAC fields untouched).
 *
 * Down(): refuses production rollback per the NF525-adjacent pattern of
 * `2026_04_22_000002` and the `audit_logs` / `z_reports` DELETE-trigger
 * migrations — dropping UNIQUE in production would re-open the window for
 * accidental double-mirror under a buggy client sending two distinct
 * X-Idempotency-Key values. Non-production environments may roll back.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Add UNIQUE first — explicit name so subsequent migrations can
            // reference it deterministically. MySQL/MariaDB online DDL covers
            // ADD UNIQUE on InnoDB without table copy when no duplicates exist.
            $table->unique('parent_order_id', 'orders_parent_order_id_unique');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Drop the redundant non-unique INDEX. UNIQUE above keeps the
            // FK happy (FK requires at least one index on the column;
            // UNIQUE satisfies that requirement). Wrapped in try/catch for
            // idempotent replay if a prior run partially completed.
            try {
                $table->dropIndex('orders_parent_order_id_index');
            } catch (\Throwable) {
                // Index already absent (e.g. replay after success) — safe.
            }
        });
    }

    public function down(): void
    {
        // NF525-adjacent: orders table is the Z aggregate source. Refuse
        // rollback in production to preserve the forensic and integrity
        // trail. Forward-only data migrations are the correct path if
        // surplus mirrors must be tolerated.
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NF525-adjacent DDL rollback refused in production. '
                . 'Drop of UNIQUE(orders.parent_order_id) would re-open the '
                . 'double-mirror window. Use a forward-only data-migration '
                . 'if surplus counter-entries must be tolerated.'
            );
        }

        // Non-production rollback: restore original INDEX shape.
        Schema::table('orders', function (Blueprint $table) {
            // Restore non-unique INDEX first so the FK still has an index
            // backing it when we drop UNIQUE below (mirrors up() ordering).
            try {
                $table->index('parent_order_id', 'orders_parent_order_id_index');
            } catch (\Throwable) {
                // Already exists — safe.
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropUnique('orders_parent_order_id_unique');
            } catch (\Throwable) {
                // Already absent — safe.
            }
        });
    }
};
