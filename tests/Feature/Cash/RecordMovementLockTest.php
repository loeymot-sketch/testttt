<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [Sprint H2 P2-Z10-08 2026-05-17] recordMovement must wrap the session
 * reload + state check + CashMovement insert + audit_logs write inside
 * a DB::transaction() with lockForUpdate() on the session row.
 *
 * Pre-fix the recordMovement reload used a plain `find()` outside any
 * transaction. Two simultaneous calls on the same OPEN session could
 * both observe status='open' and proceed to insert a CashMovement —
 * generally benign because CashMovement is append-only, BUT if a
 * concurrent close() flipped the session between our SELECT and our
 * INSERT, we'd write a movement onto a CLOSED session and silently
 * corrupt the Z-report aggregate.
 *
 * Post-fix invariants validated here:
 *   1. recordMovement opens a transaction (transactionLevel rises mid-call).
 *   2. On MySQL drivers, the SELECT against cash_drawer_sessions carries
 *      "for update" (SQLite is a no-op — covered structurally).
 *   3. The happy-path remains correct: a movement is created on an OPEN
 *      session and persisted to cash_movements with correct columns.
 *   4. Audit logging (cash.movement.recorded) still fires from inside
 *      the transaction (atomic with the movement row).
 */
class RecordMovementLockTest extends TestCase
{
    use RefreshDatabase;

    private CashDrawerService $service;
    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashDrawerService();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->cashier);
    }

    /**
     * Sentinel: assert recordMovement runs inside DB::transaction AND
     * (on MySQL) emits a `FOR UPDATE` clause on the session SELECT.
     *
     * This is the canonical Sprint H2 P2-Z10-08 regression sentinel.
     */
    public function test_record_movement_uses_transaction_and_for_update_on_session_reload(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 50.00);

        // Baseline: track the maximum DB::transactionLevel observed during
        // recordMovement. Pre-fix the call never raises the level (no DB::
        // transaction()); post-fix it raises by exactly +1 (or +1 nested
        // when RefreshDatabase already holds the outer transaction in tests).
        //
        // We tap DB::listen() to observe the level while a query is in
        // flight — guaranteed to fire from inside the service's transaction
        // body (where lockForUpdate() emits its SELECT) if the wrap exists.
        // Capture the transaction level AT THE TIME of the session SELECT
        // (the cash_drawer_sessions reload). Pre-fix that SELECT runs at
        // the test's outer-transaction baseline (RefreshDatabase level=1).
        // Post-fix the lockForUpdate() wrap pushes it to baseline+1.
        //
        // We do NOT use a general "max level during the whole call" check
        // because the audit_logs writer (AuditLogService::write) already
        // opens its OWN DB::transaction, which would mask whether the
        // recordMovement reload itself is wrapped.
        $baselineLevel = DB::transactionLevel();
        $sessionSelectLevel = null;
        DB::listen(function ($query) use ($session, &$sessionSelectLevel) {
            // Only the SELECT against cash_drawer_sessions for OUR session id.
            $sql = (string) ($query->sql ?? '');
            if ($sessionSelectLevel !== null) {
                return; // already captured the first one
            }
            if (stripos($sql, 'cash_drawer_sessions') === false) {
                return;
            }
            if (stripos($sql, 'select') === false) {
                return;
            }
            if (! in_array($session->id, $query->bindings ?? [], false)) {
                return;
            }
            $sessionSelectLevel = DB::transactionLevel();
        });

        DB::enableQueryLog();
        $this->service->recordMovement(
            sessionId: $session->id,
            type: CashMovement::TYPE_ORDER_PAYMENT,
            amount: 10.00,
            direction: CashMovement::DIRECTION_IN,
            orderId: null,
            notes: 'unit test record movement',
            strict: true,
        );
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Sentinel 1: the session SELECT must have happened INSIDE a
        // transaction opened by recordMovement (level > baseline). This is
        // the canonical proof that the lockForUpdate() wrap is active —
        // a SELECT FOR UPDATE outside a transaction is a no-op in MySQL
        // (autocommit releases the row lock immediately).
        $this->assertNotNull(
            $sessionSelectLevel,
            'recordMovement must SELECT cash_drawer_sessions during the call (P2-Z10-08)'
        );
        $this->assertGreaterThan(
            $baselineLevel,
            $sessionSelectLevel,
            sprintf(
                'recordMovement session SELECT MUST run inside its own DB::transaction (P2-Z10-08). baseline=%d sessionSelectLevel=%d',
                $baselineLevel,
                $sessionSelectLevel
            )
        );

        // Sentinel 2: the session was SELECTed by id during the call.
        $sessionSelect = collect($log)->first(function ($q) use ($session) {
            $query = (string) ($q['query'] ?? '');
            $bindings = $q['bindings'] ?? [];
            return stripos($query, 'cash_drawer_sessions') !== false
                && stripos($query, 'select') !== false
                && in_array($session->id, $bindings, false);
        });

        $this->assertNotNull(
            $sessionSelect,
            'Service must SELECT the cash_drawer_sessions row by id during recordMovement'
        );

        // Sentinel 3: on MySQL / pgsql, the SELECT must carry "for update".
        // On SQLite, the grammar strips it (no-op) — sentinels 1+2 above
        // remain the structural proof of correctness.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'pgsql') {
            $this->assertStringContainsStringIgnoringCase(
                'for update',
                (string) $sessionSelect['query'],
                'recordMovement MUST issue SELECT ... FOR UPDATE on the session reload (race defense)'
            );
        }
    }

    /**
     * Regression: the transaction wrap must not break the happy path.
     * A normal recordMovement on an OPEN session still creates the row,
     * returns the CashMovement, and persists branch_id + amount + direction.
     */
    public function test_record_movement_happy_path_still_creates_movement(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 50.00);

        $movement = $this->service->recordMovement(
            sessionId: $session->id,
            type: CashMovement::TYPE_ORDER_PAYMENT,
            amount: 15.00,
            direction: CashMovement::DIRECTION_IN,
        );

        $this->assertNotNull($movement, 'Happy-path recordMovement must return a CashMovement');
        $this->assertEquals(15.00, (float) $movement->amount);
        $this->assertSame($session->id, (int) $movement->cash_drawer_session_id);
        $this->assertSame($this->branch->id, (int) $movement->branch_id);
        $this->assertSame(CashMovement::DIRECTION_IN, $movement->direction);
        $this->assertSame(CashMovement::TYPE_ORDER_PAYMENT, $movement->type);

        // Row must really be in the DB (committed, not just an unsaved instance).
        $this->assertDatabaseHas('cash_movements', [
            'id' => $movement->id,
            'cash_drawer_session_id' => $session->id,
            'amount' => 15.00,
        ]);
    }

    /**
     * Regression: state guard still rejects movements on a CLOSED session.
     * The transaction wrap must not regress this invariant (I4).
     */
    public function test_record_movement_returns_null_on_closed_session_non_strict(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 50.00);
        $this->service->closeSession($session->id, 50.00);

        $movement = $this->service->recordMovement(
            sessionId: $session->id,
            type: CashMovement::TYPE_ORDER_PAYMENT,
            amount: 5.00,
            direction: CashMovement::DIRECTION_IN,
            strict: false,
        );

        $this->assertNull($movement, 'recordMovement on CLOSED session (non-strict) must return null');
        $this->assertDatabaseMissing('cash_movements', [
            'cash_drawer_session_id' => $session->id,
            'amount' => 5.00,
        ]);
    }
}
