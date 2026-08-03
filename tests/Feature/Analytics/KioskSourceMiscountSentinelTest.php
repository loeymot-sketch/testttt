<?php

namespace Tests\Feature\Analytics;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WG-3 WF-3 P1 2026-05-19] Kiosk source miscount sentinel.
 *
 * Background:
 *   The kiosk frontend posts `source = Source::WEB (=5)` because the kiosk
 *   browser shell is technically a web client. The dashboard
 *   `channelStatistics` legacy code mistakenly assumed kiosk == `Source::APP
 *   (=10)`. As a result, kiosk orders (~152 across recent prod data) silently
 *   mis-bucketed as "Web" in admin analytics.
 *
 *   The canonical discriminator across the FoodKing codebase is
 *   `source_surface` ('kiosk' | 'web' | 'mobile' | 'pos' | 'admin') — see
 *   KDSOrderDetailsResource ("KDS lane bucketing must use source_surface"),
 *   FrontendOrderService line 522 (kiosk path sets 'kiosk' here), the loyalty
 *   pipeline, and the outbox listeners.
 *
 * Sentinel guarantees:
 *   A1. A kiosk order with `source=Source::WEB` (the real production payload)
 *       AND `source_surface='kiosk'` is counted as Kiosk/App, NOT Web.
 *   A2. A pure web order (`source=Source::WEB`, `source_surface='web'`)
 *       continues to count as Web.
 *   A3. A POS order (`source=Source::POS`, `source_surface='pos'`) continues
 *       to count as POS.
 *   A4. Legacy back-compat: a row with `source_surface=NULL` falls back on the
 *       legacy `source` int discriminator (so historical pre-2026-03-26 rows
 *       still bucket sanely).
 *
 * This complements `DashboardBranchScopeMatrixTest` which still uses the
 * legacy `source` int path (intentionally — that test asserts the back-compat
 * fallback works for un-tagged fixture rows).
 */
class KioskSourceMiscountSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        // Pin "now" to Paris midday to keep Paris-today == UTC-today day-boundary
        // (mirrors DashboardBranchScopeMatrixTest pattern).
        $pinned = CarbonImmutable::parse('2026-05-19 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($pinned);
        CarbonImmutable::setTestNow($pinned);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * RED before heal: this test would assert kiosk_pct=33.33 but the buggy
     * `where('source', Source::APP)` would yield 0 (the kiosk row has
     * `source=WEB`, not `source=APP`) — kiosk_pct would compute as 0 and the
     * test would fail.
     * GREEN after heal: source_surface='kiosk' wins, kiosk_pct=33.33.
     */
    public function test_kiosk_order_with_source_web_counts_as_kiosk_not_web(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->assignRole('Branch Manager');

        // The 3 production-realistic rows: a kiosk order (frontend posts
        // source=WEB but service tags source_surface='kiosk'), a pure web
        // order, and a POS order.
        $this->createCanonicalOrder($branch, Source::WEB, 'kiosk', OrderType::KIOSK, 'K0001');
        $this->createCanonicalOrder($branch, Source::WEB, 'web',   OrderType::TAKEAWAY, 'W0001');
        $this->createCanonicalOrder($branch, Source::POS, 'pos',   OrderType::POS,      'P0001');

        $channels = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/dashboard/channel-statistics')
            ->assertOk()
            ->json('data');

        $webPct = (float) collect($channels)->firstWhere('name', 'Web')['value'];
        $kioskPct = (float) collect($channels)->firstWhere('name', 'Kiosk/App')['value'];
        $posPct = (float) collect($channels)->firstWhere('name', 'POS')['value'];

        // Each bucket is 1/3 of total. Before the heal kiosk_pct would be 0
        // (no row has source=APP) and web_pct would be ~66.67 (both kiosk and
        // web rows have source=WEB).
        $this->assertSame(33.33, $webPct, 'pure-web order must count exactly once as Web');
        $this->assertSame(33.33, $kioskPct, 'kiosk order (source=WEB, source_surface=kiosk) must bucket as Kiosk/App');
        $this->assertSame(33.33, $posPct, 'POS order must count as POS');
    }

    /**
     * Direct DashboardService test (does not depend on the HTTP route or
     * permission gates) — equivalent to dashboardCounts() coverage. Ensures
     * the discrimination logic itself is correct independent of routing.
     */
    public function test_dashboard_service_canonical_kiosk_discriminator_direct(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        // 4 kiosk rows (all source=WEB + source_surface=kiosk).
        for ($i = 1; $i <= 4; $i++) {
            $this->createCanonicalOrder($branch, Source::WEB, 'kiosk', OrderType::KIOSK, "K000{$i}");
        }
        // 1 pure-web row.
        $this->createCanonicalOrder($branch, Source::WEB, 'web', OrderType::TAKEAWAY, 'W0001');
        // 5 POS rows.
        for ($i = 1; $i <= 5; $i++) {
            $this->createCanonicalOrder($branch, Source::POS, 'pos', OrderType::POS, "P000{$i}");
        }

        $this->actingAs($admin, 'sanctum');

        $stats = app(DashboardService::class)->channelStatistics();

        $byName = collect($stats)->keyBy('name');

        // 10 orders total: 4 kiosk + 1 web + 5 pos = 10.
        $this->assertSame(40.0,  (float) $byName['Kiosk/App']['value'], 'Kiosk/App must be 4/10 = 40%');
        $this->assertSame(10.0,  (float) $byName['Web']['value'],       'Web must be 1/10 = 10% (pure-web only)');
        $this->assertSame(50.0,  (float) $byName['POS']['value'],       'POS must be 5/10 = 50%');
    }

    /**
     * Legacy back-compat sentinel: pre-2026-03-26 rows have `source_surface=NULL`.
     * For those rows, channel bucketing must fall back on the legacy `source`
     * int — so historical Source::APP rows still bucket as Kiosk/App, and
     * historical Source::WEB rows still bucket as Web. This preserves
     * historical reporting AND keeps the original DashboardBranchScopeMatrixTest
     * fixture passing (which seeds Source::APP rows without source_surface).
     *
     * Note: `order_type` is TAKEAWAY (=10), not DELIVERY (=5), to avoid the
     * Order::booted() observer auto-filling source_surface='delivery' — the
     * auto-fill only triggers on order_type=DELIVERY, which is irrelevant to
     * the channel discriminator under test.
     */
    public function test_legacy_null_source_surface_falls_back_to_source_int(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        // Legacy pre-migration kiosk row: source=APP, source_surface=NULL.
        Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 10.00,
            'source'         => Source::APP,
            'source_surface' => null,
            'order_type'     => OrderType::TAKEAWAY,
            'queue_number'   => 'LEG-K1',
            'order_datetime' => now(),
        ]);

        // Legacy pre-migration web row: source=WEB, source_surface=NULL.
        Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 10.00,
            'source'         => Source::WEB,
            'source_surface' => null,
            'order_type'     => OrderType::TAKEAWAY,
            'queue_number'   => 'LEG-W1',
            'order_datetime' => now(),
        ]);

        $this->actingAs($admin, 'sanctum');

        $stats = app(DashboardService::class)->channelStatistics();
        $byName = collect($stats)->keyBy('name');

        $this->assertSame(50.0, (float) $byName['Kiosk/App']['value'], 'Legacy Source::APP row with NULL source_surface must still bucket as Kiosk/App');
        $this->assertSame(50.0, (float) $byName['Web']['value'],       'Legacy Source::WEB row with NULL source_surface must bucket as Web');
        $this->assertSame(0.0,  (float) $byName['POS']['value']);
    }

    private function createCanonicalOrder(
        Branch $branch,
        int $source,
        string $sourceSurface,
        int $orderType,
        string $queueNumber
    ): void {
        Order::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 12.50,
            'source'         => $source,
            'source_surface' => $sourceSurface,
            'order_type'     => $orderType,
            'queue_number'   => $queueNumber,
            'order_datetime' => now(),
        ]);
    }
}
