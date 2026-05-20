<?php

/**
 * [Wave T R5 2026-05-20] Sentinel — KDS service binds Paris-local
 * day bounds at Paris-evening (DST-active) wall-clock (CORRECTED).
 *
 * Authority: Wave T R5 mission 2026-05-20. The Wave 3b heal (commit
 * `148dbebce`) ASSUMED MySQL session_tz=UTC and converted Paris-local
 * day bounds to UTC strings. EMPIRICAL inspection on this deployment
 * shows session_tz='SYSTEM' (= Europe/Paris), so the UTC bind literals
 * were re-interpreted as Paris-local datetimes, shifting the window
 * backward by 1-2h (more in DST). Wave T R5 reverts to binding
 * Paris-local Carbon bounds directly.
 *
 * What this pins (post-Wave-T-R5):
 *   At Paris 22:20 CEST (a DST-active failure-window centroid), the
 *   SQL bindings emitted by `KitchenDisplaySystemOrderService::list()`
 *   MUST contain the Paris-local day bounds (`2026-05-18 00:00:00`,
 *   `2026-05-18 23:59:59`, `2026-05-19 00:00:00`) and MUST NOT contain
 *   the UTC-converted bounds (`2026-05-17 22:00:00` etc) that the
 *   Wave 3b heal produced.
 *
 * Why a DST-evening pin is non-duplicative with SisterServicesTzAwareTest:
 *   The sister sentinel pins Paris-WINTER noon (`2026-01-15` UTC+1) to
 *   dodge DST ambiguity. This sentinel covers the DST-active May 22:20
 *   case (UTC+2). Without an explicit DST-evening case, a future
 *   regression that broke ONLY DST arithmetic could pass the winter
 *   test and still break production.
 *
 * RED triggers:
 *   - Wave 3b `setTimezone('UTC')` re-introduced: bindings would shift
 *     to '2026-05-17 22:00:00' → assertion-A fires.
 *   - DST handling regressed (forgets `$appTz`, uses PHP default TZ
 *     under a UTC-set PHP env): Paris-local bound math drifts →
 *     assertion-B and assertion-C fire.
 *
 * INVARIANT WARNING: this sentinel assumes session_tz=OS-local (Paris).
 * Any future `connections.mysql.timezone => '+00:00'` config requires
 * re-evaluating BOTH the service AND this sentinel.
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

        // Expected post-Wave-T-R5 Paris-local bound literals at this moment.
        // Paris-today (DST May): start = '2026-05-18 00:00:00', end =
        // '2026-05-18 23:59:59', tomorrow = '2026-05-19 00:00:00'.
        $appTz = config('app.timezone');
        $expectedStart = Carbon::today($appTz)->format('Y-m-d H:i:s');
        $expectedEnd = Carbon::today($appTz)->endOfDay()->format('Y-m-d H:i:s');
        $expectedTomorrow = Carbon::tomorrow($appTz)->format('Y-m-d H:i:s');
        $this->assertSame('2026-05-18 00:00:00', $expectedStart, 'Paris-today start CEST = Paris-local midnight');
        $this->assertSame('2026-05-18 23:59:59', $expectedEnd, 'Paris-today end CEST = Paris-local 23:59:59');
        $this->assertSame('2026-05-19 00:00:00', $expectedTomorrow, 'Paris-tomorrow start CEST = Paris-local midnight next');

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

        // ASSERTION-A (negative — Wave 3b regression detector): the
        // UTC-converted Paris-day-start '2026-05-17 22:00:00' MUST NOT
        // appear. If it does, `->setTimezone('UTC')` has been re-introduced
        // and production MySQL session_tz=Paris will silently drop the last
        // 22:00-23:59:59 Paris orders from the KDS UI. Wave T R5.
        $this->assertStringNotContainsString(
            '2026-05-17 22:00:00',
            $joined,
            'KDS service MUST NOT bind UTC-converted Paris-day-start at Paris '
            . '22:20 CEST. If this fails, the Wave 3b `->setTimezone(\'UTC\')` '
            . 'pattern has been re-introduced — production MySQL session_tz='
            . "Paris re-interprets UTC literals as Paris-local. Wave T R5.\n"
            . "Captured bindings: $joined"
        );

        // ASSERTION-B (positive — Paris-local start bound): Paris-local
        // today-start MUST be bound (whereBetween lower bound).
        $this->assertStringContainsString(
            $expectedStart,
            $joined,
            'KDS service MUST bind Paris-local today-start '
            . "(expected '$expectedStart') at Paris 22:20 CEST. "
            . "If this fails, DST handling has regressed or `->setTimezone('UTC')` "
            . "is back.\nCaptured bindings: $joined"
        );

        // ASSERTION-C (positive — Paris-local tomorrow bound): the advance-
        // order overdue half-open `<` boundary MUST be bound at the
        // Paris-local tomorrow-start.
        $this->assertStringContainsString(
            $expectedTomorrow,
            $joined,
            'KDS service MUST bind Paris-local tomorrow-start '
            . "(expected '$expectedTomorrow') for the overdue advance-"
            . "order branch.\nCaptured bindings: $joined"
        );
    }
}
