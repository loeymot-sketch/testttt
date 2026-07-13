<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL-J2-HEAL-06 2026-05-24] Phase J-ADV-2 FV-F5-1 P1 NF525.
 *
 * order_items.composition_snapshot BEFORE UPDATE immutability trigger.
 *
 * CONTEXT
 * -------
 * order_items.composition_snapshot is the immutable per-line NF525 capture
 * of the pricing+composition state at the moment the order was created
 * (variations, extras, unit_price, attribute_name, etc.). All 5 application
 * write sites are INSERT-only (verified Phase I.2):
 *
 *   - OrderService::store():484
 *   - OrderService::create():916
 *   - OrderService::storeFrontendOrder():1417
 *   - FrontendOrderService::orderStore():442
 *   - RefundWithCounterEntryService::execute():146  (clones parent value verbatim)
 *
 * BUT the Eloquent layer alone is not enough: a raw SQL UPDATE
 * (`DB::table('order_items')->update(['composition_snapshot' => ...])`)
 * or a compromised insider with DB shell could alter per-item snapshot
 * AFTER the order is sealed and the Z report is signed, undetected — the
 * Z-aggregate HMAC chain covers totals & line counts but does NOT cover
 * individual composition_snapshot JSON contents.
 *
 * FIX
 * ---
 * BEFORE UPDATE trigger that raises SQLSTATE 45000 (MySQL) / RAISE(ABORT)
 * (SQLite) when an UPDATE statement mutates a NON-NULL composition_snapshot
 * value to a different value.
 *
 * DESIGN NOTES
 * ------------
 *   - NULL → JSON allowed (covers legacy rows being backfilled). Once a
 *     snapshot is non-null it cannot be changed in-place.
 *   - JSON → NULL also blocked (would be a deletion of audit evidence).
 *   - Per-row trigger, fires only when composition_snapshot is in the
 *     SET clause. Other columns (e.g. release_at, status, instruction)
 *     remain freely updatable.
 *   - Mirrors the audit_logs / z_reports / cash_movements / stock_movements
 *     BEFORE-mutation trigger pattern (see migrations 2026_04_22_000002,
 *     2026_05_09_160000, 2026_05_16_130000, 2026_05_18_140000).
 *   - SQLite parity branch required because PHPUnit uses :memory: SQLite.
 *
 * EDGE CASES
 * ----------
 *   - MySQL JSON column compare via `!=` is a normalized JSON-VALUE comparison
 *     (NOT byte-for-byte). MySQL parses both sides and compares by value, so a
 *     rewrite of an IDENTICAL fiscal content with reordered keys or different
 *     whitespace is tolerated (same value ⇒ not flagged). This is acceptable:
 *     the invariant protects any change of VALUE — the fiscal composition
 *     itself cannot be altered, only its serialized form may differ harmlessly.
 *   - SQLite has no native JSON type — the column is TEXT, `!=` is a string
 *     compare, identical semantics.
 *   - TRUNCATE TABLE order_items bypasses triggers — mitigated by GRANT-level
 *     REVOKE on prod DB user (same pattern as audit_logs, deploy doc scope).
 *   - INSERT path untouched (BEFORE INSERT not added — we WANT inserts).
 *   - DELETE path untouched (NF525 does not forbid hard-delete of order_items
 *     during cancellation flows; the audit_logs chain captures the cancellation).
 *
 * ROLLBACK
 * --------
 * down() is a hard refuse in production (NF525 immutability once set must
 * NOT be silently reversible). Non-prod environments may drop for local
 * iteration.
 */
return new class extends Migration {
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! Schema::hasTable('order_items')) {
            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Idempotent: drop pre-existing trigger of same name if any.
            DB::unprepared('DROP TRIGGER IF EXISTS order_items_composition_snapshot_no_update');

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_composition_snapshot_no_update
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                BEGIN
                    IF NEW.composition_snapshot IS NOT NULL
                       AND OLD.composition_snapshot IS NOT NULL
                       AND NEW.composition_snapshot != OLD.composition_snapshot THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'NF525: composition_snapshot is immutable after creation (J2-HEAL-06)';
                    END IF;
                    IF NEW.composition_snapshot IS NULL
                       AND OLD.composition_snapshot IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'NF525: composition_snapshot cannot be nulled after creation (J2-HEAL-06)';
                    END IF;
                END
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS order_items_composition_snapshot_no_update');

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_composition_snapshot_no_update
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                WHEN NEW.composition_snapshot IS NOT NULL
                     AND OLD.composition_snapshot IS NOT NULL
                     AND NEW.composition_snapshot != OLD.composition_snapshot
                BEGIN
                    SELECT RAISE(ABORT, 'NF525: composition_snapshot is immutable after creation (J2-HEAL-06)');
                END;
            SQL);

            DB::unprepared('DROP TRIGGER IF EXISTS order_items_composition_snapshot_no_null');

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_composition_snapshot_no_null
                BEFORE UPDATE ON order_items
                FOR EACH ROW
                WHEN NEW.composition_snapshot IS NULL
                     AND OLD.composition_snapshot IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'NF525: composition_snapshot cannot be nulled after creation (J2-HEAL-06)');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'NF525: cannot drop composition_snapshot immutability trigger in production (J2-HEAL-06)'
            );
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS order_items_composition_snapshot_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS order_items_composition_snapshot_no_null');
        }
    }
};
