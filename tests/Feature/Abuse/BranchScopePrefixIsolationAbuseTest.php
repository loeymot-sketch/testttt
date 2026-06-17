<?php

namespace Tests\Feature\Abuse;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [abuse-2026-06-17 P1 — green-on-broken gap closed]
 *
 * Mutation testing exposed that a real branch-isolation-weakening mutation on BranchScope —
 * `$builder->where($field, '=', $userBranch)` → `$builder->where($field, 'LIKE', $userBranch.'%')`
 * (BranchScope.php:39) — would ship GREEN through the entire ~3000-test suite, because:
 *   - tests/Feature/BranchScopeTest uses branch ids 1 & 2 (LIKE '1%' matches '1' not '2' → no leak),
 *   - tests/Feature/Branch/OrderBranchIsolationTest uses prefix ids 1/10/100 BUT acts as an ADMIN
 *     (branch_id=0) so BranchScope short-circuits and never runs its where() clause.
 *
 * This pins PREFIX isolation: a STAFF user on branch id=1 must NEVER see rows of branch id=11/10
 * (which a `LIKE '1%'` would leak). On correct `=` code it passes; under the LIKE mutation the
 * branch-11 rows leak into the scoped query and this test goes RED.
 */
class BranchScopePrefixIsolationAbuseTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function staff_on_branch_1_never_sees_prefix_related_branch_11_or_10_rows(): void
    {
        // Controlled ids so 11 and 10 are textual prefix-victims of LIKE '1%'.
        Branch::factory()->create(['id' => 1]);
        Branch::factory()->create(['id' => 10]);
        Branch::factory()->create(['id' => 11]);

        $staffBranch1 = User::factory()->create(['branch_id' => 1]); // staff (branch_id > 0)

        Order::factory()->create(['branch_id' => 1]);
        Order::factory()->create(['branch_id' => 10]);
        Order::factory()->create(['branch_id' => 11]);
        Order::factory()->create(['branch_id' => 11]);

        $this->actingAs($staffBranch1);

        // BranchScope applies for staff (branch_id=1>0): only branch-1 rows. Under a LIKE '1%'
        // mutation, ids 10 and 11 also match → the assertions below flip RED (leak caught).
        $visibleBranchIds = Order::query()->get()->pluck('branch_id')->unique()->sort()->values()->all();

        $this->assertSame(
            [1],
            $visibleBranchIds,
            'Staff@branch1 must see ONLY branch 1 — branches 10/11 are LIKE-prefix leak victims (=→LIKE mutation guard).'
        );
        $this->assertSame(1, Order::query()->count(), 'BranchScope must exclude prefix-related branches 10/11 for staff@branch1.');
    }
}
