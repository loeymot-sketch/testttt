<?php

/**
 * [WG-4 TZ-test-drift V1.0.X 2026-05-19] Sentinel — KDS service binds
 * UTC-converted day bounds at Paris-evening (DST-active) wall-clock.
 *
 * Authority:
 *   - reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md
 *     §"Sentinel test addition"
 *   - Companion to tests/Feature/Services/SisterServicesTzAwareTest.php
 *     (Wave 3b KDS-ADV3B-01) which pins the same KDS binding pattern at
 *     Paris-WINTER noon. This sentinel covers the complementary
 *     Paris-EVENING DST-active case [22:00, 23:59:59] explicitly called out
 *     in V1_0_X_BACKLOG_KDS_TZ_FIX.md as the failure window the heal
 *     `c2613cab0` was deployed to close.
 *
 * What this pins:
 *   At Paris 22:20 CEST (the failure-window centroid), the SQL bindings
 *   emitted by `KitchenDisplaySystemOrderService::list()` MUST contain the
 *   UTC-converted day bounds (`2026-05-17 22:00:00`, `2026-05-18 21:59:59`,
 *   `2026-05-18 22:00:00`) and MUST NOT contain the Paris-local literals
 *   that pre-heal `Carbon::today()` would have produced (`2026-05-18 00:00:00`,
 *   `2026-05-18 23:59:59`).
 *
 * Why a DST-evening pin is non-duplicative with SisterServicesTzAwareTest:
 *   The sister sentinel pins Paris-WINTER noon (`2026-01-15 12:00:00 UTC` →
 *   Paris UTC+1) to dodge DST ambiguity in the binding-shape assertion.
 *   The V1.0.X failure manifested in DST-active May at 22:20 Paris (UTC+2).
 *   Without an explicit DST-evening case, a future regression that broke
 *   ONLY the DST arithmetic (e.g. forgot `$appTz` and used PHP default TZ)
 *   could pass the winter test and still break production. This sentinel
 *   closes that gap.
 *
 * RED trigger:
 *   - `c2613cab0` reverted (KDS service binds `Carbon::today()` directly):
 *     bindings would include Paris-local `'2026-05-18 00:00:00'` →
 *     assertion-A fires.
 *   - DST handling regressed (e.g. service ignores `$appTz`): UTC bounds
 *     would be `'2026-05-18 23:00:00'` (UTC+1 winter math applied) instead
 *     of `'2026-05-17 22:00:00' / '2026-05-18 21:59:59'` (correct UTC+2
 *     May math) → assertion-B and assertion-C fire.
 */

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TzAwareRowVsBoundInclusionSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        // Carbon::setTestNow() leaks across tests if not cleared.
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * RED: fails when KitchenDisplaySystemOrderService::list() binds
     *      Paris-local midnight literal (Carbon::today() pre-heal) at
     *      Paris 22:20 CEST wall-clock.
     * GREEN: passes when service converts Paris-local day → UTC range
     *        (c2613cab0 post-heal).
     */
    public function test_kds_list_binds_utc_converted_paris_day_boundaries_in_dst_evening_window(): void
    {
        // Pin the wall-clock to the documented failure-window centroid.
        // 2026-05-18 20:20:00 UTC = 22:20:00 Paris CEST (DST active in May).
        $now = CarbonImmutable::parse('2026-05-18 20:20:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        // Sanity: confirm pinning + DST math (UTC+2 May, NOT UTC+1).
        $this->assertSame(
            '2026-05-18 22:20:00',
            Carbon::now()->setTimezone('Europe/Paris')->format('Y-m-d H:i:s'),
            'pinned UTC 20:20 must resolve to Paris 22:20 (CEST, DST active in May)'
        );

        // Expected post-heal UTC bound literals at this pinned moment.
        // Paris-today in May = UTC+2 → start UTC = '2026-05-17 22:00:00',
        // end UTC = '2026-05-18 21:59:59', tomorrow UTC = '2026-05-18 22:00:00'.
        $appTz = config('app.timezone');
        $expectedStartUtc = Carbon::today($appTz)
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
        $expectedEndUtc = Carbon::today($appTz)
            ->endOfDay()
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
        $expectedTomorrowUtc = Carbon::tomorrow($appTz)
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
        $this->assertSame('2026-05-17 22:00:00', $expectedStartUtc, 'Paris-today start CEST = UTC 22:00 prev-day');
        $this->assertSame('2026-05-18 21:59:59', $expectedEndUtc, 'Paris-today end CEST = UTC 21:59:59 today');
        $this->assertSame('2026-05-18 22:00:00', $expectedTomorrowUtc, 'Paris-tomorrow start CEST = UTC 22:00 today');

        // Seed an admin user + an active KIOSK order at "now" so the
        // service's whereBetween / orWhere(is_advance) branches emit
        // their order_datetime predicates.
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $order = Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::PREPARING,
            'payment_status'   => PaymentStatus::PAID,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => $now,
            'is_advance_order' => Ask::NO,
        ]);
        $order->forceFill(['updated_at' => $now])->saveQuietly();

        // Capture compiled SQL + bindings emitted by the service.
        $captured = [];
        DB::listen(static function ($query) use (&$captured) {
            $captured[] = [
                'sql' => strtolower($query->sql),
                'bindings' => array_map(
                    static fn ($b) => is_object($b) && method_exists($b, 'format')
                        ? $b->format('Y-m-d H:i:s')
                        : (string) $b,
                    $query->bindings
                ),
            ];
        });

        $service = app(KitchenDisplaySystemOrderService::class);
        $service->list(new Request());

        // Filter to the SELECT FROM orders queries that bind order_datetime.
        $normalize = static fn (string $sql): string => str_replace(['`', '"'], '', $sql);
        $orderQueries = array_values(array_filter(
            $captured,
            static function (array $q) use ($normalize): bool {
                $n = $normalize($q['sql']);
                return str_contains($n, 'from orders')
                    && str_contains($n, 'order_datetime');
            }
        ));

        $this->assertNotEmpty(
            $orderQueries,
            'KitchenDisplaySystemOrderService::list() must emit at least one '
            . 'SELECT FROM orders predicating on order_datetime. '
            . 'If empty, the service shape has changed materially.'
        );

        // Flatten all bindings into one string for substring assertions.
        $allBindings = [];
        foreach ($orderQueries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        // ASSERTION-A (negative — revert detector): the pre-heal Paris-local
        // midnight literal '2026-05-18 00:00:00' MUST NOT appear. Pre-heal
        // `Carbon::today()` resolves to this in app.timezone='Europe/Paris'
        // and was bound as-is against the UTC-stored TIMESTAMP column.
        $this->assertStringNotContainsString(
            '2026-05-18 00:00:00',
            $joined,
            'KDS service MUST NOT bind Paris-local midnight literal at Paris '
            . '22:20 CEST. If this fails, Wave 3b heal c2613cab0 has been '
            . 'reverted — production MySQL would silently drop 22:00-23:59:59 '
            . 'Paris orders from the KDS UI. See '
            . "reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md.\n"
            . "Captured bindings: $joined"
        );

        // ASSERTION-B (positive — UTC start bound): UTC instant of Paris-today
        // start MUST be bound (whereBetween lower bound).
        $this->assertStringContainsString(
            $expectedStartUtc,
            $joined,
            "KDS service MUST bind UTC instant of Paris-today start "
            . "(expected '$expectedStartUtc') at Paris 22:20 CEST. "
            . "If this fails, c2613cab0 may be partially reverted or DST "
            . "handling regressed.\nCaptured bindings: $joined"
        );

        // ASSERTION-C (positive — UTC tomorrow bound): the advance-order
        // overdue half-open `<` boundary MUST also be bound at the correct
        // UTC instant for Paris-tomorrow start.
        $this->assertStringContainsString(
            $expectedTomorrowUtc,
            $joined,
            "KDS service MUST bind UTC instant of Paris-tomorrow start "
            . "(expected '$expectedTomorrowUtc') for the overdue advance-"
            . "order branch.\nCaptured bindings: $joined"
        );
    }
}
