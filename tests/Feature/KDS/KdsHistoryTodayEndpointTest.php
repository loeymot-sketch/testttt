<?php

/**
 * [Wave X3 2026-05-21] KDS Historique du jour — read-only V1 sentinel.
 *
 * Pins behavior of `GET /api/admin/kds-order/history-today`:
 *  - Returns today's PREPARED (8) + OUT_FOR_DELIVERY (10) + DELIVERED (13)
 *    orders for the caller's branch.
 *  - Sorted by `updated_at` DESC.
 *  - Capped at 50 rows.
 *  - Branch-scoped: admin (branch_id=0) sees all; branch staff sees only their own.
 *  - TZ-aware Paris-local bounds (Wave T R5 discipline).
 *  - Read-only — NF525 chain untouched.
 *
 * Mirrors test patterns from `KdsBranchFilterExactTest` (seedSpatieRoles +
 * seedMinimalSettings + role assignment + actingAs sanctum + x-api-key header).
 */

namespace Tests\Feature\Kds;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsHistoryTodayEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function endpoint(): string
    {
        return '/api/admin/kds-order/history-today';
    }

    private function actingAsChef(int $branchId): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        $user->assignRole('Chef');
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    /** @test */
    public function it_returns_today_prepared_out_and_delivered_orders_only(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        $appTz = config('app.timezone');
        $todayMorning = Carbon::today($appTz)->copy()->addHours(8);
        $yesterday    = Carbon::yesterday($appTz)->copy()->addHours(14);

        // SHOULD be returned (today + matching status)
        $prepared = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $todayMorning->copy()->addMinutes(10),
        ]);
        $out = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::OUT_FOR_DELIVERY,
            'updated_at' => $todayMorning->copy()->addMinutes(30),
        ]);
        $delivered = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::DELIVERED,
            'updated_at' => $todayMorning->copy()->addMinutes(45),
        ]);

        // SHOULD be filtered out (yesterday)
        Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $yesterday,
        ]);

        // SHOULD be filtered out (today but wrong status — ACCEPT still active grid)
        Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::ACCEPT,
            'updated_at' => $todayMorning->copy()->addMinutes(5),
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson($this->endpoint());

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(3, $ids, 'Endpoint must return exactly 3 rows (PREPARED/OUT/DELIVERED today).');
        $this->assertEqualsCanonicalizing(
            [$prepared->id, $out->id, $delivered->id],
            $ids,
            'Endpoint must return the three valid orders, no more and no less.'
        );
    }

    /** @test */
    public function it_sorts_by_updated_at_descending(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        $appTz = config('app.timezone');
        $base = Carbon::today($appTz)->copy()->addHours(10);

        $early = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $base->copy(),
        ]);
        $late = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::DELIVERED,
            'updated_at' => $base->copy()->addHour(),
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson($this->endpoint())
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals(
            [$late->id, $early->id],
            $ids,
            'Most-recent updated_at must come first.'
        );
    }

    /** @test */
    public function it_enforces_branch_isolation_for_branch_staff(): void
    {
        // KDSOrderDetailsResource intentionally does NOT expose branch_id
        // (frontend never needs it). So we assert isolation by ORDER IDs:
        // branch A chef must see only branch-A orders' IDs.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $this->actingAsChef($branchA->id);

        $appTz = config('app.timezone');
        $now = Carbon::today($appTz)->copy()->addHours(12);

        $orderA = Order::factory()->create([
            'branch_id'  => $branchA->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $now,
        ]);
        // Other branch's order — must NOT leak to branch-A chef.
        $orderB = Order::factory()->create([
            'branch_id'  => $branchB->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $now,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson($this->endpoint())
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertEquals([$orderA->id], $ids,
            'Branch A chef must only see branch-A orders (branch_id leak check via order id set).');
        $this->assertNotContains($orderB->id, $ids,
            'Branch B order MUST NOT leak across branches.');
    }

    /** @test */
    public function admin_branch_id_zero_sees_all_branches(): void
    {
        // Admin (branch_id=0) bypasses the per-branch filter. Assert by ID set
        // since resource does not expose branch_id.
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $appTz = config('app.timezone');
        $now = Carbon::today($appTz)->copy()->addHours(11);

        $orderA = Order::factory()->create([
            'branch_id'  => $branchA->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $now,
        ]);
        $orderB = Order::factory()->create([
            'branch_id'  => $branchB->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::DELIVERED,
            'updated_at' => $now->copy()->addMinutes(5),
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson($this->endpoint())
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$orderA->id, $orderB->id])->sort()->values()->all();
        $this->assertEquals($expected, $ids, 'Admin must see both branches\' orders.');
    }

    /** @test */
    public function it_caps_at_50_orders(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        $appTz = config('app.timezone');
        $base = Carbon::today($appTz)->copy()->addHours(8);

        // Distinct updated_at per row so ORDER BY DESC + LIMIT 50 is deterministic.
        for ($i = 0; $i < 60; $i++) {
            Order::factory()->create([
                'branch_id'  => $branch->id,
                'order_type' => OrderType::POS,
                'status'     => OrderStatus::DELIVERED,
                'updated_at' => $base->copy()->addSeconds($i),
            ]);
        }

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson($this->endpoint())
            ->assertOk();

        $this->assertCount(50, $response->json('data'), 'Endpoint must cap at 50 rows.');
    }

    /** @test */
    public function it_uses_tz_aware_paris_local_bounds_not_utc(): void
    {
        // Wave T R5 lesson: orders updated at 23:30 Paris-local MUST be in "today"
        // even though their UTC stamp (21:30 / 22:30 depending on DST) is the same UTC day.
        // Bounds built with Carbon::today(appTz) are Paris-local Y-m-d H:i:s strings;
        // MySQL session_tz=Paris interprets them at face value. A UTC-shifted heal would
        // drop the last ~2h of every Paris day from this endpoint.

        $branch = Branch::factory()->create();
        $this->actingAsChef($branch->id);

        $appTz = config('app.timezone'); // 'Europe/Paris'
        $parisLate = Carbon::today($appTz)->copy()->setTime(23, 30, 0);

        $order = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::POS,
            'status'     => OrderStatus::PREPARED,
            'updated_at' => $parisLate,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson($this->endpoint())
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains(
            $order->id,
            $ids,
            'Order at 23:30 Paris-local must be in today\'s history (Wave T R5 TZ discipline).'
        );
    }

    /** @test */
    public function unauthenticated_request_is_denied(): void
    {
        // No actingAs — guest. KDS endpoints sit behind auth middleware.
        // Accept any non-2xx status that signals denial (401, 403, 302 redirect, 419 CSRF).
        $response = $this->getJson($this->endpoint());

        $this->assertTrue(
            in_array($response->status(), [401, 403, 302, 419], true),
            "Guest must be denied; got HTTP {$response->status()}."
        );
    }
}
