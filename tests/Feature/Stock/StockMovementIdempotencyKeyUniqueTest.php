<?php

namespace Tests\Feature\Stock;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sentinel — Mission #2 Vague 2 action 2.6.
 *
 * Asserts that stock_movements.idempotency_key is uniquely constrained
 * at the DB level, preventing duplicate writes even under retry storms
 * or worker overlap (pessimistic safety belt).
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.2 #15
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 2.6
 *
 * Contract once unskipped:
 *
 *   1. The schema for stock_movements has a UNIQUE INDEX on idempotency_key.
 *      Verified via Schema::hasIndex or DB introspection.
 *
 *   2. INSERT with a duplicate idempotency_key throws QueryException with
 *      a unique-constraint violation. StockService::mutateForOrder catches
 *      this and treats it as a no-op (the prior write already succeeded).
 *
 *   3. tests/Feature/Stock/StockMovementsAppendOnlyTest.php still passes
 *      (no regression on append-only guard).
 *
 *   4. The migration backfill assigns a synthetic idempotency_key
 *      (e.g. sha1(order_item_id|stockable_type|stockable_id|movement_type|created_at))
 *      to historical rows so that the UNIQUE constraint can be added
 *      retroactively without DB rejection.
 *
 *   5. tests/Feature/Stock/StockConcurrentDecrementTest.php still passes
 *      (lockForUpdate + unique key combine without deadlock).
 */
class StockMovementIdempotencyKeyUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_constraint_present(): void
    {
        $this->markTestSkipped('Pending plan task 2.6 (PLAN_CV1-LIFECYCLE-UX-001).');
    }

    public function test_duplicate_insert_throws_and_is_treated_as_noop(): void
    {
        $this->markTestSkipped('Pending plan task 2.6 (PLAN_CV1-LIFECYCLE-UX-001).');
    }

    public function test_append_only_guard_unaffected(): void
    {
        $this->markTestSkipped('Pending plan task 2.6 (PLAN_CV1-LIFECYCLE-UX-001).');
    }

    public function test_concurrent_decrement_still_passes(): void
    {
        $this->markTestSkipped('Pending plan task 2.6 (PLAN_CV1-LIFECYCLE-UX-001).');
    }
}
