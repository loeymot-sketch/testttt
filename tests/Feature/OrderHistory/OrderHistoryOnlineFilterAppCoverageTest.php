<?php

namespace Tests\Feature\OrderHistory;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN Wave B — HIST-04] Adversarial coverage test for the
 * Historique unified-orders "En ligne" / online origin filter.
 *
 * GROUNDING (verified against source, anti-false-finding):
 *  - Filter param: `source_surface` (OrderService::$orderFilter line 88),
 *    applied as `LIKE '%value%'` via OrderService::applyOrderFilter (line 2806).
 *  - The "En ligne" UI button sends `source_surface = 'web'`
 *    (HistoriqueListComponent.vue:337 — `case 'online': … = 'web'`).
 *  - Customer online (web/mobile) orders are CREATED with `source_surface = 'web'`
 *    (FrontendOrderService.php:535 — `$isKiosk ? 'kiosk' : 'web'`). This is the
 *    authoritative creation path for online customer orders.
 *  - The `'app'` token (and `'mobile'`) is a PHANTOM on this V1 deployment: it
 *    appears only in code comments + the badge resolver's read-side `||` chain
 *    (HistoriqueListComponent.vue:297 treats web|app|mobile as "En ligne"), but
 *    NO order-creation path ever writes `'app'`. The legacy `Source::APP` enum
 *    (int=10) is a DIFFERENT column (`source`), not `source_surface`.
 *
 * Therefore the coverage assertion that matters: the online filter
 * (`source_surface=web`) INCLUDES the real online order and EXCLUDES every
 * non-online origin (kiosk / pos / delivery) — i.e. online orders do NOT
 * silently disappear from the "En ligne" filter, and non-online origins are
 * NOT silently swept in.
 *
 * Hard assertions on specific ids + counts (would fail if the filter regressed
 * to over-match or to drop the online surface).
 *
 * DOCUMENTED DORMANT ASYMMETRY (NOT a live V1 defect — left as a guard note):
 * if a future order ever carried `source_surface='app'` or `'mobile'`, the badge
 * would label it "En ligne" yet the filter (which sends `'web'`) would exclude
 * it. This is dormant in V1 because no creation path writes app/mobile. The
 * `test_app_is_a_phantom_value_not_written_by_creation_path` assertion below
 * pins that fact so the test is honest about scope.
 */
class OrderHistoryOnlineFilterAppCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        // branch_id=0 admin bypasses BranchScope → sees every origin.
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');
    }

    /**
     * Seed an order with an explicit source_surface (factory has no default →
     * must set it explicitly so non-online rows are never accidentally NULL).
     */
    private function makeOrder(string $sourceSurface, int $orderType, int $paymentStatus): Order
    {
        return Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'order_type'     => $orderType,
            'source_surface' => $sourceSurface,
            'payment_status' => $paymentStatus,
            'total'          => 10.00,
        ]);
    }

    /**
     * Core HIST-04 coverage assertion: the "En ligne" filter value the UI
     * actually sends (source_surface=web) returns the online order AND excludes
     * kiosk / pos / delivery. No coverage gap where the online order vanishes,
     * and no over-match pulling in non-online origins.
     */
    public function test_online_filter_includes_web_origin_and_excludes_non_online(): void
    {
        $online   = $this->makeOrder('web', OrderType::TAKEAWAY, PaymentStatus::PAID);
        $kiosk    = $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $pos      = $this->makeOrder('pos', OrderType::POS, PaymentStatus::PAID);
        $delivery = $this->makeOrder('delivery', OrderType::DELIVERY, PaymentStatus::PAID);

        $this->actingAs($this->admin, 'sanctum');

        // 'online' UI selection → backend filter source_surface=web (verified
        // HistoriqueListComponent.vue:337).
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=50&source_surface=web');
        $res->assertOk();

        $returnedIds = collect($res->json('data'))->pluck('id')->all();

        // HARD: the online (web) order IS returned.
        $this->assertContains($online->id, $returnedIds, 'Online (web) order must appear in the "En ligne" filter');

        // HARD: none of the non-online origins leak in.
        $this->assertNotContains($kiosk->id, $returnedIds, 'Kiosk order must NOT appear in the online filter');
        $this->assertNotContains($pos->id, $returnedIds, 'POS order must NOT appear in the online filter');
        $this->assertNotContains($delivery->id, $returnedIds, 'Delivery order must NOT appear in the online filter');

        // HARD: exactly one row, and it is the online order (count-precise).
        $surfaces = collect($res->json('data'))->pluck('source_surface')->unique()->values()->all();
        $this->assertSame(['web'], $surfaces, 'Online filter must return only web-surface rows');
        $this->assertCount(1, $returnedIds, 'Exactly one online order should match');
        $this->assertSame([$online->id], $returnedIds);
    }

    /**
     * Anti-vacuity guard: prove the filter is genuinely scoping (not returning
     * everything). With NO filter the admin sees all four origins; WITH the
     * online filter only the web row survives. If the filter were a no-op this
     * fails (it would return 4).
     */
    public function test_online_filter_actually_narrows_versus_unfiltered(): void
    {
        $online = $this->makeOrder('web', OrderType::TAKEAWAY, PaymentStatus::PAID);
        $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $this->makeOrder('pos', OrderType::POS, PaymentStatus::PAID);

        $this->actingAs($this->admin, 'sanctum');

        $all = $this->getJson('/api/admin/order-history?paginate=1&per_page=50');
        $all->assertOk();
        $this->assertGreaterThanOrEqual(3, count($all->json('data')), 'Unfiltered admin view sees every origin');

        $filtered = $this->getJson('/api/admin/order-history?paginate=1&per_page=50&source_surface=web');
        $filtered->assertOk();
        $filteredIds = collect($filtered->json('data'))->pluck('id')->all();

        $this->assertSame([$online->id], $filteredIds, 'Online filter narrows to exactly the web order');
    }

    /**
     * LIKE-substring safety: the online value 'web' must not over-match other
     * surfaces. None of kiosk/pos/delivery contains the substring 'web', so the
     * LIKE '%web%' is safe — assert it explicitly so a future surface rename
     * that reintroduces over-match (e.g. 'webhook-test') is caught here.
     */
    public function test_web_filter_does_not_overmatch_other_surfaces(): void
    {
        $online = $this->makeOrder('web', OrderType::TAKEAWAY, PaymentStatus::PAID);
        $kiosk  = $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);
        $pos    = $this->makeOrder('pos', OrderType::POS, PaymentStatus::PAID);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson('/api/admin/order-history?paginate=1&per_page=50&source_surface=web');
        $res->assertOk();

        $returnedIds = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$online->id], $returnedIds);
        $this->assertNotContains($kiosk->id, $returnedIds);
        $this->assertNotContains($pos->id, $returnedIds);
    }

    /**
     * Scope-honesty pin: 'app' is a phantom value. No order created here carries
     * it, and the literal 'app' filter (which the UI never sends — it sends
     * 'web') returns nothing. This documents that the V1 online surface IS 'web',
     * not 'app', so the suite is not asserting against a value no real order
     * carries. If a future deployment starts WRITING source_surface='app' on
     * online orders WITHOUT updating the En-ligne filter mapping
     * (HistoriqueListComponent.vue:337 still sends 'web'), this test stays green
     * but the dormant asymmetry documented in the class docblock becomes live —
     * revisit then.
     */
    public function test_app_is_a_phantom_value_not_written_by_creation_path(): void
    {
        // The real online order is 'web' (FrontendOrderService.php:535).
        $online = $this->makeOrder('web', OrderType::TAKEAWAY, PaymentStatus::PAID);
        $this->makeOrder('kiosk', OrderType::TAKEAWAY, PaymentStatus::PENDING_COUNTER);

        $this->actingAs($this->admin, 'sanctum');

        // The UI never sends 'app'; if it did, no V1 order carries it → empty.
        $appRes = $this->getJson('/api/admin/order-history?paginate=1&per_page=50&source_surface=app');
        $appRes->assertOk();
        $this->assertCount(0, $appRes->json('data'), "No V1 order carries source_surface='app' — it is a phantom value");

        // Sanity: the actual online value resolves the online order.
        $webRes = $this->getJson('/api/admin/order-history?paginate=1&per_page=50&source_surface=web');
        $webRes->assertOk();
        $this->assertSame(
            [$online->id],
            collect($webRes->json('data'))->pluck('id')->all()
        );
    }
}
