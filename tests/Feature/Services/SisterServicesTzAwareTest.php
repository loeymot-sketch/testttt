<?php

/**
 * Wave T R5 KDS+OSS — P0 production-breaking sentinel (CORRECTED).
 * Authority: Wave T R5 mission 2026-05-20 (KDS-T-R5-03 / KDS-T-R5-04
 *   / KDS-T-R5-05). Empirical capture at 23:51 Paris showed
 *   `KitchenDisplaySystemOrderService::list` returned 1 row out of 11 DB
 *   rows for branch=1 status=7 PREPARING.
 *
 * ROOT CAUSE: the Wave 2b/3b heal (commit `148dbebce` and mirrors)
 * ASSUMED MySQL session_tz=UTC. EMPIRICALLY FALSE on this deployment:
 *   SELECT @@session.time_zone → 'SYSTEM' (= OS local = Europe/Paris)
 * because config/database.php connections.mysql.timezone is NULL and PDO
 * inherits the OS TZ.
 *
 * Under session_tz=Paris, UTC-shifted bind literals get re-interpreted as
 * Paris-local datetimes, shifting the active window backward by 2h and
 * silently dropping the last ~2h of every Paris day (22h-minuit) from:
 *   - KitchenDisplaySystemOrderService::list()       (admin KDS UI)
 *   - KitchenDisplaySystemOrderService::orderItems() (chef items board)
 *   - OrderStatusScreenOrderService::list()          (admin OSS dashboard)
 *   - OrderStatusScreenOrderService::listForBranch() (customer wall)
 *
 * CORRECT HEAL: use Paris-local Carbon bounds directly. MySQL
 * session_tz=Paris interprets them at face value, matching the semantic
 * intent "all of TODAY in Paris" and aligning with how stored TIMESTAMP
 * values are displayed/compared.
 *
 * INVARIANT WARNING: these sentinels pin behavior under session_tz=
 * OS-local (Paris). Any future PR that sets
 *   config/database.php connections.mysql.timezone => '+00:00'
 * must re-evaluate BOTH the services AND these sentinels.
 *
 * SQLite (test driver) has no session-TZ concept, so a behavioral row-
 * count test would pass either way. These sentinels pin the compiled
 * SQL bindings: they assert the bound literals are Paris-local Carbon
 * objects, NOT the UTC-converted instants of the pre-Wave-T-R5 era.
 */

namespace Tests\Feature\Services;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use App\Services\OrderStatusScreenOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SisterServicesTzAwareTest extends TestCase
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
     * Pin "now" to a Paris-winter timestamp (no DST ambiguity, Paris = UTC+1).
     * Returns the expected Paris-local bound literals (the Wave T R5 heal
     * binds these directly to MySQL session_tz=Paris).
     *
     * @return array{0:string,1:string,2:string,3:CarbonImmutable}
     */
    private function pinParisWinterNow(): array
    {
        $now = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $appTz = config('app.timezone'); // 'Europe/Paris'
        $expectedStart = Carbon::today($appTz)->format('Y-m-d H:i:s');
        $expectedEnd = Carbon::today($appTz)->endOfDay()->format('Y-m-d H:i:s');
        $expectedTomorrow = Carbon::tomorrow($appTz)->format('Y-m-d H:i:s');

        // Sanity: Paris-local midnight literals (NOT UTC-shifted).
        $this->assertSame('2026-01-15 00:00:00', $expectedStart);
        $this->assertSame('2026-01-15 23:59:59', $expectedEnd);
        $this->assertSame('2026-01-16 00:00:00', $expectedTomorrow);

        return [$expectedStart, $expectedEnd, $expectedTomorrow, $now];
    }

    /**
     * Capture compiled SQL + bindings for queries running inside $callback.
     *
     * @return array<int, array{sql:string,bindings:array<int,string>}>
     */
    private function captureOrderDatetimeQueries(callable $callback): array
    {
        $captured = [];
        DB::listen(function ($query) use (&$captured) {
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

        $callback();

        $normalize = static fn (string $sql): string => str_replace(['`', '"'], '', $sql);
        return array_values(array_filter(
            $captured,
            function (array $q) use ($normalize): bool {
                $n = $normalize($q['sql']);
                return str_contains($n, 'from orders')
                    && str_contains($n, 'order_datetime');
            }
        ));
    }

    /**
     * Assert Paris-local literals present + UTC-converted bound absent.
     */
    private function assertUtcDayBoundariesBound(
        array $orderQueries,
        string $expectedStart,
        string $expectedTomorrow,
        string $services
    ): void {
        if (empty($orderQueries)) {
            $this->fail(
                'Expected at least one SELECT from orders filtering by '
                . "order_datetime in $services."
            );
        }

        $allBindings = [];
        foreach ($orderQueries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joinedBindings = implode(' | ', $allBindings);

        // ASSERT-A (negative): the UTC-converted Paris-day-start MUST NOT
        // appear. This is the Wave 2b/3b bug — empirical session_tz=Paris
        // (NOT UTC as the Wave 3b commit asserted) means UTC bind literals
        // get re-interpreted as Paris-local, shifting the window backward
        // by 1-2h. Wave T R5 KDS-T-R5-03/04/05.
        $this->assertStringNotContainsString(
            '2026-01-14 23:00:00',
            $joinedBindings,
            "$services MUST NOT bind UTC-converted Paris-day-start to MySQL. "
            . 'Empirical session_tz=SYSTEM (Paris-local) on this deployment, '
            . 'so UTC literals get re-interpreted as Paris-local, shifting '
            . "the active window backward by 1-2h. Wave T R5.\n"
            . "Captured bindings: $joinedBindings"
        );

        // ASSERT-B (positive): the Paris-local today-start MUST be bound
        // (whereBetween lower bound).
        $this->assertStringContainsString(
            $expectedStart,
            $joinedBindings,
            "$services MUST bind Paris-local today-start "
            . "(expected '$expectedStart'). Wave T R5."
        );

        // ASSERT-C (positive): Paris-local tomorrow-start MUST also be bound
        // (the "<" boundary for the advance-order branch).
        $this->assertStringContainsString(
            $expectedTomorrow,
            $joinedBindings,
            "$services MUST bind Paris-local tomorrow-start "
            . "for advance-order branch (expected '$expectedTomorrow'). Wave T R5."
        );
    }

    /**
     * RED: fails when KitchenDisplaySystemOrderService::list() binds
     *      Carbon::today() (Paris-local midnight) to MySQL UTC session.
     * GREEN: passes when service converts Paris-local day → UTC range.
     */
    public function test_kds_list_binds_utc_converted_paris_day_boundaries(): void
    {
        [$startLocal, , $tomorrowLocal, $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        // Seed active orders so the SELECT emits its predicates.
        for ($i = 0; $i < 3; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::PREPARING,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => $now->subHour(),
                'is_advance_order' => Ask::NO,
            ]);
            $order->forceFill(['updated_at' => $now])->saveQuietly();
        }

        $orderQueries = $this->captureOrderDatetimeQueries(function () {
            $service = app(KitchenDisplaySystemOrderService::class);
            $service->list(new Request());
        });

        $this->assertUtcDayBoundariesBound(
            $orderQueries,
            $startLocal,
            $tomorrowLocal,
            'KitchenDisplaySystemOrderService::list'
        );
    }

    /**
     * RED: fails when KitchenDisplaySystemOrderService::orderItems() binds
     *      Carbon::today() (Paris-local midnight) to MySQL UTC session.
     * GREEN: passes when service converts Paris-local day → UTC range.
     */
    public function test_kds_orderItems_binds_utc_converted_paris_day_boundaries(): void
    {
        [$startLocal, , $tomorrowLocal, $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        for ($i = 0; $i < 3; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::PREPARING,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => $now->subHour(),
                'is_advance_order' => Ask::NO,
            ]);
            $order->forceFill(['updated_at' => $now])->saveQuietly();
        }

        $orderQueries = $this->captureOrderDatetimeQueries(function () {
            $service = app(KitchenDisplaySystemOrderService::class);
            $service->orderItems();
        });

        $this->assertUtcDayBoundariesBound(
            $orderQueries,
            $startLocal,
            $tomorrowLocal,
            'KitchenDisplaySystemOrderService::orderItems'
        );
    }

    /**
     * RED: fails when OrderStatusScreenOrderService::listForBranch() binds
     *      Carbon::today() (Paris-local midnight) to MySQL UTC session.
     * GREEN: passes when service converts Paris-local day → UTC range.
     *
     * `listForBranch()` is the auth-less public-wall variant (used by
     * `OrderStatusScreenController::publicIndex`). Its query body is
     * byte-identical to `list()` per the in-source docstring — testing
     * one path is sufficient to confirm the shared pattern, but we also
     * call `list()` below for full coverage.
     */
    public function test_oss_listForBranch_binds_utc_converted_paris_day_boundaries(): void
    {
        [$startLocal, , $tomorrowLocal, $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::PREPARING,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => $now->subHour(),
                'is_advance_order' => Ask::NO,
                'queue_number' => 100 + $i,
                'token' => 'tok-' . $i,
            ]);
        }

        $orderQueries = $this->captureOrderDatetimeQueries(function () use ($branch) {
            $service = app(OrderStatusScreenOrderService::class);
            $service->listForBranch($branch->id);
        });

        $this->assertUtcDayBoundariesBound(
            $orderQueries,
            $startLocal,
            $tomorrowLocal,
            'OrderStatusScreenOrderService::listForBranch'
        );
    }

    /**
     * RED: fails when OrderStatusScreenOrderService::list() binds
     *      Carbon::today() (Paris-local midnight) to MySQL UTC session.
     * GREEN: passes when service converts Paris-local day → UTC range.
     */
    public function test_oss_list_binds_utc_converted_paris_day_boundaries(): void
    {
        [$startLocal, , $tomorrowLocal, $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        for ($i = 0; $i < 3; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::PREPARING,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => $now->subHour(),
                'is_advance_order' => Ask::NO,
                'queue_number' => 200 + $i,
                'token' => 'tok2-' . $i,
            ]);
        }

        $orderQueries = $this->captureOrderDatetimeQueries(function () use ($branch) {
            // list() reads request()->query('branch_id') + auth()->user();
            // we passed acting-as admin above so resolveBranchScope returns
            // the requested branch id when provided.
            request()->query->set('branch_id', $branch->id);
            $service = app(OrderStatusScreenOrderService::class);
            $service->list();
        });

        $this->assertUtcDayBoundariesBound(
            $orderQueries,
            $startLocal,
            $tomorrowLocal,
            'OrderStatusScreenOrderService::list'
        );
    }
}
