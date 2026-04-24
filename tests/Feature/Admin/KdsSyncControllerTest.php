<?php

/**
 * F-03 / NEW-02 / NEW-04
 * Authority: plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md (Lot 1.C)
 *
 * Sentinel tests for the adaptive KDS polling endpoint:
 *   - active-window deltas
 *   - left-window deletion ids
 *   - branch isolation (non-admin cross-branch attempt → 403)
 *   - admin global view (branch_id=0 sees all)
 *   - server_now monotonic
 *   - cache key isolation per branch
 *   - 400 on missing `since`
 */

namespace Tests\Feature\Admin;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KdsSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    private function makeUserForBranch(?Branch $branch, string $role = 'POS Operator'): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch ? $branch->id : 0,
        ]);
        $user->assignRole($role);
        return $user;
    }

    private function makeOrderInBranch(Branch $branch, int $status = OrderStatus::ACCEPT, ?Carbon $updatedAt = null): Order
    {
        $now = $updatedAt ?: Carbon::now();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => $status,
            'order_datetime' => Carbon::today()->addHours(12),
            'is_advance_order' => Ask::NO,
        ]);
        // forceFill bypasses the timestamps mutator so we can pin updated_at
        // for the "since" delta tests.
        $order->forceFill(['updated_at' => $now])->saveQuietly();
        return $order->refresh();
    }

    public function test_sync_rejects_missing_since_with_400(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->makeUserForBranch($branch);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync')
            ->assertStatus(400);
    }

    public function test_sync_rejects_unparseable_since_with_400(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->makeUserForBranch($branch);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=not-a-date')
            ->assertStatus(400);
    }

    public function test_sync_returns_orders_updated_since(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->makeUserForBranch($branch);
        $older = $this->makeOrderInBranch($branch, OrderStatus::ACCEPT, Carbon::now()->subMinutes(5));
        $sinceCutoff = Carbon::now()->subMinutes(2);
        $updated = $this->makeOrderInBranch($branch, OrderStatus::PREPARING, Carbon::now());

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode($sinceCutoff->toIso8601String()));

        $response->assertOk();
        $ids = collect($response->json('orders'))->pluck('id')->all();
        $this->assertContains($updated->id, $ids, 'Recently updated order should be in delta.');
        $this->assertNotContains($older->id, $ids, 'Order older than `since` must not appear.');
        $this->assertSame($branch->id, $response->json('branch_id'));
    }

    public function test_sync_returns_deleted_ids_for_orders_that_left_active_window(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->makeUserForBranch($branch);
        $order = $this->makeOrderInBranch($branch);
        $since = Carbon::now()->subMinute();

        $order->status = OrderStatus::DELIVERED;
        $order->save();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode($since->toIso8601String()));

        $response->assertOk();
        $this->assertContains($order->id, $response->json('deleted_ids'));
    }

    public function test_sync_branch_isolation_non_admin_cannot_cross_branch(): void
    {
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $user = $this->makeUserForBranch($branch);
        $since = urlencode(Carbon::now()->subMinute()->toIso8601String());

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . $since . '&branch_id=' . $other->id)
            ->assertStatus(403);
    }

    public function test_sync_admin_can_view_all_branches_with_branch_id_zero(): void
    {
        $admin = $this->makeUserForBranch(null, 'Admin');
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $orderA = $this->makeOrderInBranch($branchA);
        $orderB = $this->makeOrderInBranch($branchB);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode(Carbon::now()->subMinute()->toIso8601String()));

        $response->assertOk();
        $ids = collect($response->json('orders'))->pluck('id')->all();
        $this->assertContains($orderA->id, $ids);
        $this->assertContains($orderB->id, $ids);
        $this->assertNull($response->json('branch_id'), 'Global view echoes branch_id=null.');
    }

    public function test_sync_server_now_advances_strictly_when_cache_is_bypassed(): void
    {
        // [Audit G2 fix] Original test passed the same `since` and slept 2 ms;
        // the second call hit the cache (same minuteBucket + md5(since|incl)),
        // so server_now never actually changed. Now: distinct `since` per call
        // AND a Cache::flush in between to prove genuine monotonic advancement
        // of the server_now stamp.
        $branch = Branch::factory()->create();
        $user = $this->makeUserForBranch($branch);
        $this->makeOrderInBranch($branch);

        $first = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode(Carbon::now()->subMinutes(2)->toIso8601String()))
            ->json('server_now');

        Cache::flush();
        sleep(1);

        $second = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/kds-order/sync?since=' . urlencode(Carbon::now()->subMinute()->toIso8601String()))
            ->json('server_now');

        $this->assertGreaterThan(
            strtotime($first),
            strtotime($second),
            'server_now must strictly advance when the cache is bypassed.'
        );
    }

    public function test_sync_cache_key_is_isolated_per_branch(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $user1 = $this->makeUserForBranch($branch1);
        $user2 = $this->makeUserForBranch($branch2);
        $order1 = $this->makeOrderInBranch($branch1);
        $order2 = $this->makeOrderInBranch($branch2);
        $since = urlencode(Carbon::now()->subMinute()->toIso8601String());

        $resp1 = $this->actingAs($user1, 'sanctum')->getJson('/api/admin/kds-order/sync?since=' . $since);
        $resp2 = $this->actingAs($user2, 'sanctum')->getJson('/api/admin/kds-order/sync?since=' . $since);

        $ids1 = collect($resp1->json('orders'))->pluck('id')->all();
        $ids2 = collect($resp2->json('orders'))->pluck('id')->all();

        $this->assertContains($order1->id, $ids1);
        $this->assertNotContains($order2->id, $ids1, 'Cache must NOT leak branch 2 into branch 1 response.');
        $this->assertContains($order2->id, $ids2);
        $this->assertNotContains($order1->id, $ids2, 'Cache must NOT leak branch 1 into branch 2 response.');
    }
}
