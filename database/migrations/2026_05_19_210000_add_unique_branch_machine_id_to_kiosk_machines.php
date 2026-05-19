<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [WAVE-M P3 SWEEP-1 / DATA-INTEGRITY] Promote `kiosk_machines` to enforce
 * a composite UNIQUE on `(branch_id, machine_id)` so a duplicate kiosk
 * registration within a branch is rejected at the DATABASE level — defense
 * in depth above the application-layer `KioskMachineService::store()` which
 * currently performs no pre-insert collision check before
 * `KioskMachine::create([...])` (verified `app/Services/KioskMachineService.php:62-69`).
 *
 * Pre-existing index shape (verified via SHOW INDEX 2026-05-19, dev DB):
 *   { Key_name: kiosk_machines_branch_id_foreign, Non_unique: 1, Column: branch_id }
 *   { Key_name: kiosk_machines_user_id_foreign,   Non_unique: 1, Column: user_id }
 *   { Key_name: PRIMARY,                          Non_unique: 0, Column: id }
 *  No existing index covers `machine_id` alone or the composite `(branch_id, machine_id)`.
 *
 * Source migration: database/migrations/2025_02_21_110459_create_kiosk_machines_table.php
 *   - declares `machine_id` as `varchar(191) NOT NULL` (no UNIQUE)
 *   - declares `branch_id` as `foreignId` → `branches.id` (FK + default INDEX)
 *
 * Pre-deploy GATE (executed 2026-05-19, dev DB): 0 duplicate (branch_id, machine_id) pairs.
 *   SELECT branch_id, machine_id, COUNT(*) AS dupes FROM kiosk_machines
 *    GROUP BY branch_id, machine_id HAVING COUNT(*) > 1;
 *   → 0 rows. Safe to add UNIQUE without data cleanup.
 *
 * Semantics:
 *  - Both `branch_id` (NOT NULL via foreignId) and `machine_id` (NOT NULL string) are non-nullable —
 *    MySQL NULL-distinct semantics do NOT apply, the UNIQUE is fully effective.
 *  - SQLite (CI test runs) same behaviour for NOT NULL composite UNIQUE.
 *  - The pre-existing FK index `kiosk_machines_branch_id_foreign` (single column)
 *    is RETAINED so the FK keeps its dedicated index — we do NOT drop it.
 *
 * Application-layer fallout (audited 2026-05-19):
 *  - `app/Services/KioskMachineService.php:50-52, 72-76` already maps QueryException
 *    via `QueryExceptionLibrary::message($exception)` and throws an HTTP 422,
 *    so the SQLSTATE 23000 / errno 1062 raised by this UNIQUE is surfaced
 *    cleanly to the admin UI — no new error-translation code needed.
 *  - `app/Http/Controllers/Auth/KioskMachineLoginController.php:55-57` looks up
 *    machines by `username`, not by `machine_id`, so the login flow is
 *    unaffected by this constraint addition.
 *  - `app/Services/KioskMachineService.php:39-41` `delete()` resolves machines
 *    via WHERE-IN by id — no machine_id collision-risk pre-delete read.
 *
 * Why this and not a `machine_id` UNIQUE alone:
 *  - V1 single-tenant Le Cayenne has 1 branch, but V2 (multi-branch SaaS) would
 *    legitimately reuse machine_id `KIOSK-001` across branches. Composite
 *    `(branch_id, machine_id)` is forward-compatible with V2.
 *  - V1.0.1 baseline data: 1 row only (`branch=1, machine=KIOSK-LC-001`) —
 *    constraint addition is cost-free at this scale.
 *
 * NF525-adjacency:
 *  - `kiosk_machines` is NOT NF525-critical. Not in CLAUDE.md §7 frozen scope.
 *  - Not in deploy/ansible/site.yml REVOKE list (which protects audit_logs,
 *    z_reports, cash_movements, cash_drawer_sessions, order_payments,
 *    domain_events, webhook_events — 7 tables).
 *  - Additive constraint on a non-nullable column with verified 0 duplicates
 *    is non-destructive — no data loss, no column drop, no NF525 field touched.
 *
 * Down(): non-NF525 table, rollback restores prior shape (drop composite UNIQUE
 * only) without restoring any other index because no other index covered these
 * columns pre-migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('kiosk_machines', function (Blueprint $table) {
            // Add composite UNIQUE — explicit name so subsequent migrations
            // can reference it deterministically. MySQL/MariaDB online DDL
            // covers ADD UNIQUE on InnoDB without table copy when no
            // duplicates exist.
            $table->unique(['branch_id', 'machine_id'], 'kiosk_machines_branch_machine_unique');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_machines', function (Blueprint $table) {
            try {
                $table->dropUnique('kiosk_machines_branch_machine_unique');
            } catch (\Throwable) {
                // Already absent — safe.
            }
        });
    }
};
