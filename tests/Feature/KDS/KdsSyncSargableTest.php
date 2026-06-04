<?php

/**
 * Wave 1 KDS Architect — P1 perf regression sentinel.
 * Authority: reports/audit/critical-focus-2026-05-18/wave-1/kds-architect.md
 *   lines 43, 87, 119, 135 (DRIFT + Coverage Gap + Recommendation P1).
 *
 * KdsSyncService::sync() — /api/admin/kds-order/sync delta path — was missed by
 * the Wave 5F sargable migration applied to KitchenDisplaySystemOrderService::
 * list() and ::orderItems(). whereDate('order_datetime', Carbon::today())
 * compiles to DATE(order_datetime) = '…' on MySQL, which prevents use of
 * idx_orders_datetime and regresses page latency 10x-30x at 50+ active orders
 * on multi-branch admin polling.
 *
 * Heal landed in commit 8dc6ec331 (KdsSyncService.php lines 65-77, whereDate →
 * whereBetween + "<" Carbon::tomorrow mirroring Wave 5F pattern in
 * KitchenDisplaySystemOrderService::list:91-102).
 *
 * SQLite passes whereDate functionally — this test pins the COMPILED SQL so a
 * future re-introduction of whereDate fails CI even though behavior tests stay
 * green on the in-memory driver.
 */

namespace Tests\Feature\Kds;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\KdsSyncService;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KdsSyncSargableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    /**
     * RED: fails on whereDate (compiles to "date(order_datetime)").
     * GREEN: passes when migrated to whereBetween (compiles to
     *        "order_datetime between ? and ?") + "<" tomorrow for advance.
     */
    public function test_sync_query_uses_sargable_range_predicates_not_date_function(): void
    {
        $branch = Branch::factory()->create();

        // Seed a realistic set: 60 active orders today (audit cites "50+ open"
        // as the regression threshold).
        for ($i = 0; $i < 60; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::ACCEPT,
                'order_datetime' => Carbon::today()->addMinutes($i),
                'is_advance_order' => Ask::NO,
            ]);
            $order->forceFill(['updated_at' => Carbon::now()])->saveQuietly();
        }

        // Capture the compiled SQL of every query KdsSyncService runs.
        $capturedSql = [];
        DB::listen(function ($query) use (&$capturedSql) {
            $capturedSql[] = strtolower($query->sql);
        });

        Cache::flush();
        $service = app(KdsSyncService::class);
        $since = new DateTimeImmutable('-10 minutes');
        $payload = $service->sync($branch->id, $since, true);

        // Sanity: the service returned the seeded branch's data.
        $this->assertSame($branch->id, $payload['branch_id']);
        $this->assertNotEmpty($capturedSql, 'DB::listen must capture at least one query.');

        // Find the orders SELECT(s) containing the date filter. Driver-agnostic:
        // MySQL renders `from \`orders\`` + `\`order_datetime\``, SQLite renders
        // `from "orders"` + `"order_datetime"`. Strip both quote styles so this
        // sentinel survives a future MySQL CI run.
        $normalize = static fn (string $sql): string =>
            str_replace(['`', '"'], '', $sql);

        $orderQueries = array_values(array_filter(
            $capturedSql,
            function (string $sql) use ($normalize): bool {
                $n = $normalize($sql);
                return str_contains($n, 'from orders')
                    && str_contains($n, 'order_datetime');
            }
        ));
        if (empty($orderQueries)) {
            $this->fail(
                'Expected at least one SELECT from orders filtering by '
                . 'order_datetime. Captured ' . count($capturedSql)
                . " queries:\n" . implode("\n---\n", $capturedSql)
            );
        }

        $joined = $normalize(implode("\n", $orderQueries));

        // ASSERT-A (negative): no DATE() / strftime() wrapper on order_datetime
        // — the non-sargable pattern Wave 5F eliminated and Wave 1 audit re-flagged.
        // MySQL emits "date(order_datetime)"; SQLite emits "strftime('%y-%m-%d', order_datetime)".
        $this->assertStringNotContainsString(
            'date(order_datetime)',
            $joined,
            'KdsSyncService MUST NOT wrap order_datetime in DATE() — that '
            . 'prevents idx_orders_datetime use and regresses 10x-30x at 50+ '
            . 'active orders (Wave 1 KDS Architect P1).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/strftime\([^)]*order_datetime/i',
            $joined,
            'KdsSyncService MUST NOT wrap order_datetime in strftime() — '
            . 'whereDate emits this non-sargable form on SQLite (Wave 1 P1).'
        );

        // ASSERT-B (positive): use a sargable range predicate. Accept either
        // BETWEEN (non-advance branch in Wave 5F pattern) or "order_datetime <"
        // (advance overdue branch in Wave 5F pattern).
        $hasBetween = str_contains($joined, 'order_datetime between');
        $hasRange = str_contains($joined, 'order_datetime <')
            || str_contains($joined, 'order_datetime >=');
        $this->assertTrue(
            $hasBetween && $hasRange,
            'KdsSyncService MUST use sargable range predicates on '
            . 'order_datetime (BETWEEN for non-advance + < comparator for '
            . 'advance overdue) so idx_orders_datetime is usable. '
            . "Mirror Wave 5F pattern in KitchenDisplaySystemOrderService::list:91-102.\n"
            . 'Captured SQL: ' . $joined
        );
    }
}
