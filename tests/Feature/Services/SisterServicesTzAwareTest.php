<?php

/**
 * Wave 3b KDS Adversarial — P0 production-breaking sentinel.
 * Authority: reports/audit/critical-focus-2026-05-18/wave-3b/adv-1-kds-heals-r2.md
 *   (KDS-ADV3B-01).
 *
 * Sister-service mirror of `tests/Feature/KDS/KdsSyncTzAwareTest.php`.
 *
 * Wave 2b heal `148dbebce` patched KdsSyncService ONLY. The two sister
 * services bind `Carbon::today()` / `Carbon::tomorrow()` to MySQL
 * TIMESTAMP queries identically:
 *
 *   - KitchenDisplaySystemOrderService::list()       (~line 94, 100)
 *   - KitchenDisplaySystemOrderService::orderItems() (~line 260, 263)
 *   - OrderStatusScreenOrderService::list()          (~line 68, 73)
 *   - OrderStatusScreenOrderService::listForBranch() (~line 179, 182)
 *
 * Carbon::today/tomorrow resolve in `app.timezone='Europe/Paris'` but the
 * mysql connection in config/database.php has NO `timezone` key — PDO's
 * MySQL session TZ defaults to UTC. `orders.order_datetime` is a TIMESTAMP
 * column (MySQL coerces between session TZ and UTC storage). Net effect:
 * 1-2h of nightly orders [00:00-02:00 Paris, depending on DST] silently
 * dropped from the KDS UI hydration (admin) and the OSS customer wall.
 *
 * Heal: convert Paris-local day boundaries to UTC before binding so the
 * bound literals match the UTC-stored TIMESTAMP column. Surgical at the
 * query level (does NOT touch config/database.php mysql.timezone, which
 * would affect every query in the app).
 *
 * SQLite (test driver) has no session-TZ concept, so a behavioral test
 * would pass both pre- and post-heal. These sentinels pin the compiled
 * SQL bindings: they assert the bound literals are the UTC representation
 * of Paris-local today, NOT the Paris-local midnight string.
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
     * Returns the expected UTC bound literals so each test asserts the same
     * shape: [startUtc, endUtc, tomorrowUtc].
     *
     * @return array{0:string,1:string,2:string,3:CarbonImmutable}
     */
    private function pinParisWinterNow(): array
    {
        $now = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $appTz = config('app.timezone'); // 'Europe/Paris'
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

        // Sanity: Paris winter = UTC+1.
        $this->assertSame('2026-01-14 23:00:00', $expectedStartUtc);
        $this->assertSame('2026-01-15 22:59:59', $expectedEndUtc);
        $this->assertSame('2026-01-15 23:00:00', $expectedTomorrowUtc);

        return [$expectedStartUtc, $expectedEndUtc, $expectedTomorrowUtc, $now];
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
     * Assert UTC literals present + Paris-local midnight absent.
     */
    private function assertUtcDayBoundariesBound(
        array $orderQueries,
        string $expectedStartUtc,
        string $expectedTomorrowUtc,
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

        // ASSERT-A (negative): Paris-local midnight MUST NOT appear in any
        // binding. This is the bug — pre-heal, `Carbon::today()` resolves
        // to '2026-01-15 00:00:00' (Paris-local) and is bound as-is.
        $this->assertStringNotContainsString(
            '2026-01-15 00:00:00',
            $joinedBindings,
            "$services MUST NOT bind Paris-local midnight literal to MySQL. "
            . 'MySQL session TZ is UTC (no timezone key in config/database.php) '
            . 'so the bound literal would shift orders by 1-2h vs the UTC-stored '
            . "TIMESTAMP column. KDS-ADV3B-01.\nCaptured bindings: $joinedBindings"
        );

        // ASSERT-B (positive): the UTC instant corresponding to Paris-today
        // start MUST be bound (whereBetween lower bound).
        $this->assertStringContainsString(
            $expectedStartUtc,
            $joinedBindings,
            "$services MUST bind UTC instant of Paris-today start "
            . "(expected '$expectedStartUtc'). KDS-ADV3B-01."
        );

        // ASSERT-C (positive): tomorrow's UTC instant (the "<" boundary for
        // advance-order branch) MUST also be bound.
        $this->assertStringContainsString(
            $expectedTomorrowUtc,
            $joinedBindings,
            "$services MUST bind UTC instant of Paris-tomorrow start "
            . "for advance-order branch (expected '$expectedTomorrowUtc'). KDS-ADV3B-01."
        );
    }

    /**
     * RED: fails when KitchenDisplaySystemOrderService::list() binds
     *      Carbon::today() (Paris-local midnight) to MySQL UTC session.
     * GREEN: passes when service converts Paris-local day → UTC range.
     */
    public function test_kds_list_binds_utc_converted_paris_day_boundaries(): void
    {
        [$startUtc, , $tomorrowUtc, $now] = $this->pinParisWinterNow();

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
            $startUtc,
            $tomorrowUtc,
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
        [$startUtc, , $tomorrowUtc, $now] = $this->pinParisWinterNow();

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
            $startUtc,
            $tomorrowUtc,
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
        [$startUtc, , $tomorrowUtc, $now] = $this->pinParisWinterNow();

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
            $startUtc,
            $tomorrowUtc,
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
        [$startUtc, , $tomorrowUtc, $now] = $this->pinParisWinterNow();

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
            $startUtc,
            $tomorrowUtc,
            'OrderStatusScreenOrderService::list'
        );
    }
}
