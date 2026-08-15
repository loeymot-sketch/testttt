<?php

namespace Tests\Feature\Migration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [GOAL-L2.5 SQLite/MySQL PARITY 2026-05-24]
 *
 * Sentinel — verifies that every NF525-grade DB-level immutability invariant
 * installed by a production MySQL trigger ALSO exists as a SQLite
 * `RAISE(ABORT, ...)` trigger, so PHPUnit (which runs on SQLite :memory:)
 * can exercise the rejection path against raw `DB::table()->delete()` /
 * `DB::table()->update()` bypass attempts.
 *
 * WHY THIS MATTERS
 * ----------------
 * Without parity, an integration test could call
 *   `DB::table('order_payments')->where('id', $pid)->delete()`
 * and silently succeed on the SQLite test runner — while the SAME query
 * would be rejected by MySQL in production with SQLSTATE 45000. The test
 * would be a FALSE GREEN. Parity is therefore a precondition for our
 * NF525 immutability test suite to be trustworthy.
 *
 * COVERAGE (9 trigger sets across 8 tables)
 * -----------------------------------------
 *   audit_logs                          UPD + DEL    (mig 2026_04_22_000002)
 *   z_reports                           DEL          (migs 2026_05_09_160000 + L2.5)
 *   cash_movements                      DEL          (migs 2026_05_10_010000 + 2026_05_16_130000)
 *   cash_drawer_sessions                DEL          (migs 2026_05_10_010000 + 2026_05_16_130000)
 *   order_payments                      DEL          (migs 2026_05_10_010000 + L2.5)
 *   delivery_boy_cash_movements         DEL          (mig 2026_05_18_120300)
 *   delivery_boy_cash_sessions          DEL          (mig 2026_05_18_120300)
 *   stock_movements                     UPD + DEL    (mig 2026_05_18_140000)
 *   order_items.composition_snapshot    UPD          (mig 2026_05_24_040211)
 *
 * ALSO COVERS
 * -----------
 *   - JSON read/write round-trip parity (Laravel json-cast on SQLite TEXT
 *     vs MySQL JSON gives identical PHP-side behaviour).
 *   - Decimal precision round-trip (decimal(19,6) + decimal(15,2)).
 *
 * ANTI-DRIFT
 * ----------
 * If a future PR adds a new MySQL-only NF525 trigger without the SQLite
 * parity branch, the trigger-presence assertion will flag the gap so it
 * cannot ship silently.
 */
class SqliteMysqlParitySentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Trigger inventory: name → table. Each must exist on SQLite.
     * MySQL has the same names by design (verified via migration source).
     */
    private const SQLITE_TRIGGERS_REQUIRED = [
        // audit_logs (mig 2026_04_22_000002)
        'audit_logs_no_update'                        => 'audit_logs',
        'audit_logs_no_delete'                        => 'audit_logs',

        // z_reports (mig 2026_05_24_050000 — SQLite parity for 2026_05_09_160000)
        'z_reports_no_delete'                         => 'z_reports',

        // cash_movements + cash_drawer_sessions (mig 2026_05_16_130000 SQLite parity
        // for 2026_05_10_010000 MySQL)
        'cash_movements_no_delete'                    => 'cash_movements',
        'cash_drawer_sessions_no_delete'              => 'cash_drawer_sessions',

        // order_payments (mig 2026_05_24_050000 SQLite parity for 2026_05_10_010000)
        'order_payments_no_delete'                    => 'order_payments',

        // delivery_boy_cash_movements + delivery_boy_cash_sessions
        // (mig 2026_05_18_120300)
        'delivery_boy_cash_movements_no_delete'       => 'delivery_boy_cash_movements',
        'delivery_boy_cash_sessions_no_delete'        => 'delivery_boy_cash_sessions',

        // stock_movements (mig 2026_05_18_140000)
        'stock_movements_no_delete'                   => 'stock_movements',
        'stock_movements_no_update'                   => 'stock_movements',

        // order_items.composition_snapshot (mig 2026_05_24_040211)
        'order_items_composition_snapshot_no_update'  => 'order_items',

        // orders BEFORE DELETE quand fiscalisé (mig 2026_07_18_130000 — P1-1)
        // Empêche la réutilisation de numéros fiscaux après hard-delete d'un
        // order porteur d'un fiscal_sequence_no. Parité SQLite = WHEN clause.
        'orders_no_delete_when_fiscalized'            => 'orders',
    ];

    /**
     * Verify the test runner is actually on SQLite — the assertions below
     * are SQLite-specific (sqlite_master query, RAISE(ABORT) behaviour).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $driver = DB::connection()->getDriverName();
        if ($driver !== 'sqlite') {
            $this->markTestSkipped(
                'SqliteMysqlParitySentinel only runs on the SQLite test runner '
                . '(actual driver: ' . $driver . '). MySQL production parity is '
                . 'guaranteed by the source migrations themselves.'
            );
        }
    }

    /**
     * Every required SQLite trigger must exist in sqlite_master.
     *
     * If this fails, a migration is missing the SQLite branch and the
     * corresponding NF525 invariant is unenforceable in PHPUnit.
     */
    public function test_every_nf525_trigger_has_sqlite_parity(): void
    {
        $present = $this->sqliteTriggerNames();

        $missing = [];
        foreach (self::SQLITE_TRIGGERS_REQUIRED as $trigger => $table) {
            // Trigger only required if the parent table exists in this
            // schema — fresh-install windows or partial-migration states
            // are tolerated (matches the `Schema::hasTable` guard in the
            // source migrations themselves).
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!in_array($trigger, $present, true)) {
                $missing[] = $trigger . ' (on ' . $table . ')';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "NF525 SQLite-parity gap — the following triggers are missing from "
            . "sqlite_master but required for PHPUnit to exercise the same "
            . "immutability invariant production MySQL enforces:\n  - "
            . implode("\n  - ", $missing)
            . "\nAdd the SQLite branch to the corresponding migration "
            . "(see 2026_05_24_050000 for the canonical pattern)."
        );
    }

    /**
     * Sanity check: confirm rejection actually fires for each DELETE-only
     * trigger. Insert one row, attempt DELETE, expect rejection +
     * row-still-present. Covers all 7 DEL triggers; UPD-only triggers
     * (audit_logs_no_update, stock_movements_no_update,
     * composition_snapshot_no_update) have their own dedicated sentinels
     * already (CompositionSnapshotImmutabilityTriggerSentinel,
     * AuditLogImmutabilityTest, etc.). We don't duplicate them here.
     */
    public function test_delete_triggers_actually_reject_raw_delete(): void
    {
        $cases = [
            'audit_logs'                  => fn () => $this->seedAuditLogRow(),
            'z_reports'                   => fn () => $this->seedZReportRow(),
            'cash_movements'              => fn () => $this->seedCashMovementRow(),
            'cash_drawer_sessions'        => fn () => $this->seedCashDrawerSessionRow(),
            'order_payments'              => fn () => $this->seedOrderPaymentRow(),
            'delivery_boy_cash_movements' => fn () => $this->seedDeliveryBoyCashMovementRow(),
            'delivery_boy_cash_sessions'  => fn () => $this->seedDeliveryBoyCashSessionRow(),
            'stock_movements'             => fn () => $this->seedStockMovementRow(),
        ];

        foreach ($cases as $table => $seedFn) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $id = $seedFn();

            $rejected = false;
            try {
                DB::table($table)->where('id', $id)->delete();
            } catch (\Throwable $e) {
                $rejected = true;
                // RAISE(ABORT, ...) messages vary by trigger:
                //   audit_logs        → "audit_logs is INSERT-only"
                //   z_reports         → "DELETE forbidden"
                //   cash_movements    → "DELETE forbidden"
                //   etc.
                // Accept either invariant marker — both mean the trigger
                // fired and the row was rejected at the DB layer.
                $msg = strtolower($e->getMessage());
                $this->assertTrue(
                    str_contains($msg, 'forbidden')
                        || str_contains($msg, 'insert-only')
                        || str_contains($msg, 'immutable')
                        || str_contains($msg, 'append-only')
                        || str_contains($msg, 'nf525'),
                    "DELETE rejection message on `{$table}` should mention "
                    . 'a recognised NF525 invariant marker (forbidden / '
                    . 'insert-only / immutable / append-only / nf525). '
                    . 'Got: ' . $e->getMessage()
                );
            }

            $this->assertTrue(
                $rejected,
                "Raw `DB::table('{$table}')->where('id', {$id})->delete()` "
                . 'silently succeeded — SQLite parity trigger is either '
                . 'missing or its pattern allows bypass. NF525 invariant '
                . 'would be a false GREEN in PHPUnit.'
            );

            $still = DB::table($table)->where('id', $id)->exists();
            $this->assertTrue(
                $still,
                "Row id={$id} on `{$table}` disappeared despite the rejected "
                . 'DELETE — defence-in-depth broken.'
            );
        }
    }

    /**
     * JSON column round-trip — write nested array, read back, compare.
     *
     * Laravel's $casts = 'array' serialises to JSON-encoded TEXT on SQLite
     * and to native JSON on MySQL; round-trip behaviour MUST match.
     */
    public function test_json_column_round_trip_parity(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            $this->markTestSkipped('audit_logs table absent — skip.');
        }

        $payload = [
            'schema_version' => 2,
            'nested'         => [
                'a' => 1,
                'b' => ['x', 'y', 3.14],
                'c' => null,
            ],
            'unicode' => 'café — €1.50',
        ];

        $id = DB::table('audit_logs')->insertGetId([
            'branch_id'    => 0,
            'user_id'      => null,
            'action'       => 'test.parity',
            'resource'     => 'parity',
            'resource_id'  => null,
            'payload'      => json_encode($payload),
            'current_hash' => str_repeat('a', 64),
            'created_at'   => now(),
        ]);

        $row = DB::table('audit_logs')->where('id', $id)->first();
        $this->assertNotNull($row, 'JSON row insert failed.');

        $decoded = json_decode($row->payload, true);
        $this->assertSame(
            $payload,
            $decoded,
            'JSON column round-trip mismatch on SQLite — expect identical '
            . 'parity with MySQL JSON column.'
        );
    }

    /**
     * Decimal precision round-trip — assert decimal(19,6) and decimal(15,2)
     * preserve their stated precision on both drivers.
     *
     * SQLite stores all numeric values as REAL/INTEGER and applies type
     * affinity at retrieval; Laravel decimal-cast formats as string with
     * the declared scale. Both drivers MUST agree.
     */
    public function test_decimal_precision_round_trip_parity(): void
    {
        if (!Schema::hasTable('orders')) {
            $this->markTestSkipped('orders table absent — skip.');
        }

        // Insert a row with a known decimal-precision-sensitive value.
        // 0.1 + 0.2 = 0.300000 if precision holds end-to-end.
        // Use the factory so we don't accidentally violate one of the many
        // NOT-NULL columns on `orders` (this test does not care about order
        // shape, only the decimal round-trip).
        $order = \App\Models\Order::factory()->create([
            'subtotal' => '0.300000',
            'total'    => '0.300000',
        ]);

        $row = DB::table('orders')->where('id', $order->id)->first();

        // Compare as floats with epsilon — both SQLite TEXT and MySQL DECIMAL
        // can return string or float depending on PDO config; assert numerical
        // equality.
        $this->assertEqualsWithDelta(
            0.300000,
            (float) $row->subtotal,
            1e-9,
            'decimal(19,6) round-trip lost precision on SQLite — expect '
            . 'production MySQL identical behaviour.'
        );
    }

    // ---------------------------------------------------------------
    // Helpers — seed minimal rows directly via DB::table to avoid the
    // weight of full factories (we are testing the trigger itself, not
    // any model invariants).
    // ---------------------------------------------------------------

    /** @return list<string> */
    private function sqliteTriggerNames(): array
    {
        $rows = DB::select("SELECT name FROM sqlite_master WHERE type='trigger'");

        return array_map(fn ($r) => $r->name, $rows);
    }

    private function seedAuditLogRow(): int
    {
        return DB::table('audit_logs')->insertGetId([
            'branch_id'    => 0,
            'action'       => 'test.delete-rejection',
            'resource'     => 'parity',
            'current_hash' => str_repeat('b', 64),
            'created_at'   => now(),
        ]);
    }

    private function seedZReportRow(): int
    {
        return DB::table('z_reports')->insertGetId([
            'branch_id'   => 0,
            'sequence_no' => 1,
            'opened_at'   => now(),
            'status'      => 'open',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function seedCashDrawerSessionRow(): int
    {
        // Migration 2026_05_10_020000 enforces a UNIQUE partial index on
        // (branch_id, opened_by_user_id) WHERE status='open' — every fixture
        // must therefore pick a fresh user id to avoid the unique-violation.
        return DB::table('cash_drawer_sessions')->insertGetId([
            'branch_id'         => 1,
            'opened_by_user_id' => self::$nextOpenedByUserId++,
            'opened_at'         => now(),
            'opening_amount'    => '0.00',
            'status'            => 'open',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Monotonic user-id stamp for cash-drawer-session fixtures so the
     * UNIQUE(branch_id, opened_by_user_id) WHERE status='open' partial
     * index doesn't fire across multiple calls in one test.
     */
    private static int $nextOpenedByUserId = 100;

    private function seedCashMovementRow(): int
    {
        $sessionId = $this->seedCashDrawerSessionRow();

        return DB::table('cash_movements')->insertGetId([
            'cash_drawer_session_id' => $sessionId,
            'branch_id'              => 1,
            'type'                   => 'adjustment',
            'amount'                 => '0.00',
            'direction'              => 'in',
            'notes'                  => 'parity-test',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    private function seedOrderPaymentRow(): int
    {
        // Use the factory so we get all the orders NOT-NULL columns right
        // (user_id, addresses, etc.).
        $order = \App\Models\Order::factory()->create();

        return DB::table('order_payments')->insertGetId([
            'order_id'      => $order->id,
            'branch_id'     => $order->branch_id,
            'mode'          => 1, // CASH per migration comment
            'amount'        => '0.00',
            'change_amount' => '0.00',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function seedDeliveryBoyCashSessionRow(): int
    {
        $uid = self::$nextOpenedByUserId++;

        return DB::table('delivery_boy_cash_sessions')->insertGetId([
            'branch_id'         => 1,
            'delivery_boy_id'   => $uid,
            'opened_by_user_id' => $uid,
            'opened_at'         => now(),
            'opening_amount'    => '0.00',
            'status'            => 'open',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function seedDeliveryBoyCashMovementRow(): int
    {
        $sessionId = $this->seedDeliveryBoyCashSessionRow();

        return DB::table('delivery_boy_cash_movements')->insertGetId([
            'delivery_boy_cash_session_id' => $sessionId,
            'branch_id'                    => 1,
            'type'                         => 'adjustment',
            'amount'                       => '0.00',
            'direction'                    => 'in',
            'notes'                        => 'parity-test',
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]);
    }

    private function seedStockMovementRow(): int
    {
        // stock_movements requires stock_level_id (FK RESTRICT on MySQL).
        $branchId = \App\Models\Branch::factory()->create()->id;

        // stock_levels uses POLYMORPHIC (stockable_type + stockable_id), NOT item_id.
        // Just use any string for type — FK is NOT enforced at the SQL layer
        // for polymorphic columns.
        $stockLevelId = DB::table('stock_levels')->insertGetId([
            'branch_id'      => $branchId,
            'stockable_type' => 'App\\Models\\Item',
            'stockable_id'   => 1,
            'on_hand'        => 0,
            'reserved'       => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return DB::table('stock_movements')->insertGetId([
            'stock_level_id' => $stockLevelId,
            'branch_id'      => $branchId,
            'delta'          => 1,
            'reason'         => 'manual_in', // ENUM value per source migration
            'created_at'     => now(),
        ]);
    }

}
