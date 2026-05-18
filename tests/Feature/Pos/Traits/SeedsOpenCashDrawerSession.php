<?php

namespace Tests\Feature\Pos\Traits;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\User;

/**
 * [Sprint H6 TEST-DEBT-001 2026-05-17] Seed an OPEN CashDrawerSession
 * for POS tests that post CASH-bearing orders to /api/admin/pos.
 *
 * Sprint 1B (commit 2e3635d64) added a guard in PosController::store
 * that throws CashDrawerSessionNotOpenException when no OPEN session
 * exists for the actor on the target branch. Legacy POS tests posted
 * the request without seeding a session, so they 422'd.
 *
 * This trait centralises the seeding. Use it via:
 *
 *   use SeedsOpenCashDrawerSession;
 *
 * Then call `$this->seedOpenSessionFor($actor, $branch)` inside setUp()
 * AFTER the actor + branch are created.
 *
 * NB: The seeded session is not auto-closed between tests because each
 * test runs in a transaction (RefreshDatabase) — the session vanishes
 * with the rollback.
 */
trait SeedsOpenCashDrawerSession
{
    protected function seedOpenSessionFor(User $user, Branch $branch, float $opening = 50.00): CashDrawerSession
    {
        return CashDrawerSession::create([
            'branch_id'         => $branch->id,
            'opened_by_user_id' => $user->id,
            'opened_at'         => now(),
            'opening_amount'    => $opening,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);
    }
}
