<?php

/**
 * Wave T R5 — KDS today-window TZ-aware behavioral sentinel.
 * Authority: Wave T R5 mission 2026-05-20 (KDS-T-R5-01 / KDS-T-R5-02).
 *
 * ROOT CAUSE recap: prior heal (commit 148dbebce, Wave 2b/3b) converted
 * Paris-local day bounds to UTC strings (`->setTimezone('UTC')`) before
 * binding. The Wave 2b commit comment ASSUMED MySQL session_tz=UTC, but
 * empirical inspection on this deployment shows
 *   SELECT @@session.time_zone → 'SYSTEM' (= OS local = Europe/Paris)
 * because config/database.php connections.mysql.timezone is NULL.
 *
 * Under session_tz=Paris, UTC-shifted bind literals get re-interpreted as
 * Paris-local datetimes, shifting the active window backward by 2h.
 * Symptom captured at 23:51 Paris: 1 row served vs 11 DB rows for
 * branch=1 status=7 PREPARING.
 *
 * HEAL: bind Paris-local Carbon bounds directly. MySQL session_tz=Paris
 * interprets them at face value, matching the semantic intent
 * "all of TODAY in Paris". Applied to BOTH services:
 *   - KitchenDisplaySystemOrderService::list (admin /api/admin/pos-order)
 *   - KdsSyncService::sync                  (admin /api/admin/kds-order/sync)
 *
 * THIS SENTINEL pins the INVARIANT (not the implementation):
 *  1. Orders placed near end-of-day (21h, 23h, 23:55 Paris) MUST be visible
 *     to BOTH services when "now" is the same Paris day.
 *  2. Same orders MUST NOT be visible the NEXT Paris day (00:30 next day).
 *  3. Bindings carry Paris-local literals, not UTC-converted ones.
 *
 * INVARIANT DEPENDENCY: this sentinel assumes session_tz is OS-local
 * (Paris). If config/database.php gains
 *   connections.mysql.timezone => '+00:00'
 * BOTH services AND this sentinel must be re-evaluated.
 *
 * SQLite (test driver) ignores session_tz, but Carbon serialization stays
 * Paris-local so the behavioral check via the service roundtrip is robust
 * across driver mismatch — what we pin is the SERVICE returning the row.
 */

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KdsSyncService;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KdsTodayWindowTzSentinelTest extends TestCase
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
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Builds 3 orders near the Paris end-of-day boundary (21h, 23h, 23:55)
     * and asserts BOTH KitchenDisplaySystemOrderService::list and
     * KdsSyncService::sync return all 3 when "now" is 23:30 Paris-local.
     *
     * Pinned winter date — Paris = UTC+1, no DST.
     *
     * RED pre-heal (148dbebce): UTC-shifted bounds drop the 23h + 23:55
     *      rows because they fall outside the buggy [Paris 22:00 → 21:59:59
     *      next-day] window.
     * GREEN post-heal: all 3 rows surface.
     */
    public function test_orders_at_paris_end_of_day_are_visible_to_kds_services(): void
    {
        // Pin Paris-local 23:30 on a winter day (UTC+1, no DST).
        $parisNow = CarbonImmutable::parse('2026-01-15 23:30:00', 'Europe/Paris');
        Carbon::setTestNow($parisNow);
        CarbonImmutable::setTestNow($parisNow);

        $branch = Branch::factory()->create();
        $admin = $this->createAdminUser();

        // Seed PREPARING orders at 21:00, 23:00, 23:55 (all SAME Paris day).
        $orderTimes = [
            'order_21h'  => Carbon::parse('2026-01-15 21:00:00', 'Europe/Paris'),
            'order_23h'  => Carbon::parse('2026-01-15 23:00:00', 'Europe/Paris'),
            'order_2355' => Carbon::parse('2026-01-15 23:55:00', 'Europe/Paris'),
        ];

        $orderIds = [];
        foreach ($orderTimes as $label => $when) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::PREPARING,
                'order_datetime' => $when,
                'is_advance_order' => Ask::NO,
            ]);
            $order->forceFill(['updated_at' => $when])->saveQuietly();
            $orderIds[$label] = $order->id;
        }

        // === Service A: KitchenDisplaySystemOrderService::list ===
        $this->actingAs($admin);
        $listService = app(KitchenDisplaySystemOrderService::class);
        $req = new Request(['branch_id' => $branch->id, 'status' => OrderStatus::PREPARING]);
        $listOrders = $listService->list($req);
        $listIds = $listOrders->pluck('id')->all();

        $missingFromList = array_diff(array_values($orderIds), $listIds);
        $this->assertEmpty(
            $missingFromList,
            'KitchenDisplaySystemOrderService::list MUST return orders placed '
            . 'at 21h / 23h / 23:55 Paris when "now" is 23:30 same Paris day. '
            . 'Missing: [' . implode(',', $missingFromList) . ']. '
            . 'KDS-T-R5-01.'
        );

        // === Service B: KdsSyncService::sync ===
        Cache::flush();
        $syncService = app(KdsSyncService::class);
        $since = (new DateTimeImmutable('2026-01-15 00:00:00', new \DateTimeZone('Europe/Paris')));
        $payload = $syncService->sync($branch->id, $since, true);
        $syncIds = collect($payload['orders'])->pluck('id')->all();

        $missingFromSync = array_diff(array_values($orderIds), $syncIds);
        $this->assertEmpty(
            $missingFromSync,
            'KdsSyncService::sync MUST return orders placed at 21h / 23h / '
            . '23:55 Paris when "now" is 23:30 same Paris day. Missing: ['
            . implode(',', $missingFromSync) . ']. KDS-T-R5-02.'
        );
    }

    /**
     * Boundary test (the OTHER direction): "now" is 00:30 the NEXT Paris day.
     *
     * [ULTRA MINUIT-STRADDLE 2026-07-04 — ATTENTE INVERSÉE] L'ancienne version de ce
     * test exigeait que les commandes de la veille (23:00/23:55, encore PREPARING)
     * DISPARAISSENT à 00:30 — prémisse « hier = périmé » FAUSSE pour Le Cayenne qui
     * opère après minuit (commandes réelles 23h-02h) : la cuisine perdait un ticket
     * actif de 35 min en pleine préparation. La fenêtre non-advance est désormais
     * GLISSANTE (`oss.stale_window_hours`, 8h) : les commandes récentes de la veille
     * restent visibles, et l'intention anti-zombie d'origine est préservée par
     * l'exclusion des commandes > 8h. Sister : OssKdsMidnightStraddleTest.
     *
     * La dimension TZ d'origine du sentinel reste couverte : les bornes sont
     * toujours générées en Paris-local (voir test_orders_at_paris_end_of_day + le
     * pin du format de binding ci-dessous).
     */
    public function test_recent_prior_day_orders_stay_visible_after_paris_midnight_stale_pruned(): void
    {
        // "Now" = 00:30 on 2026-01-16 Paris (winter, UTC+1).
        $parisNow = CarbonImmutable::parse('2026-01-16 00:30:00', 'Europe/Paris');
        Carbon::setTestNow($parisNow);
        CarbonImmutable::setTestNow($parisNow);

        $branch = Branch::factory()->create();
        $admin = $this->createAdminUser();

        // Prior-day RECENT orders (Paris 23:00 + 23:55 on the 15th — 1h30/0h35 d'âge) :
        // encore actives, elles DOIVENT rester sur le board après minuit.
        $recentPriorIds = [];
        foreach (['23:00', '23:55'] as $hm) {
            $when = Carbon::parse("2026-01-15 $hm:00", 'Europe/Paris');
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::PREPARING,
                'order_datetime' => $when,
                'is_advance_order' => Ask::NO,
            ]);
            $order->forceFill(['updated_at' => $when])->saveQuietly();
            $recentPriorIds[] = $order->id;
        }

        // Prior-day STALE order (12:30 on the 15th — 12h d'âge, > fenêtre 8h) :
        // zombie, DOIT rester exclue (l'intention anti-zombie d'origine).
        $staleWhen = Carbon::parse('2026-01-15 12:30:00', 'Europe/Paris');
        $staleOrder = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'order_datetime' => $staleWhen,
            'is_advance_order' => Ask::NO,
        ]);
        $staleOrder->forceFill(['updated_at' => $staleWhen])->saveQuietly();

        // One same-day order (00:20 on the 16th) — must be visible.
        $sameDay = Carbon::parse('2026-01-16 00:20:00', 'Europe/Paris');
        $sameDayOrder = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'order_datetime' => $sameDay,
            'is_advance_order' => Ask::NO,
        ]);
        $sameDayOrder->forceFill(['updated_at' => $sameDay])->saveQuietly();

        // === Service A: KitchenDisplaySystemOrderService::list ===
        $this->actingAs($admin);
        $listService = app(KitchenDisplaySystemOrderService::class);
        $req = new Request(['branch_id' => $branch->id, 'status' => OrderStatus::PREPARING]);
        $listOrders = $listService->list($req);
        $listIds = $listOrders->pluck('id')->all();

        $this->assertContains(
            $sameDayOrder->id,
            $listIds,
            'KitchenDisplaySystemOrderService::list MUST include same-day '
            . '(post-midnight) order id=' . $sameDayOrder->id . '. KDS-T-R5-01.'
        );

        $missing = array_diff($recentPriorIds, $listIds);
        $this->assertEmpty(
            $missing,
            'KitchenDisplaySystemOrderService::list MUST keep RECENT prior-day active '
            . 'orders (< stale window) visible across Paris midnight — Le Cayenne opère '
            . 'après minuit. Missing: [' . implode(',', $missing) . ']. MINUIT-STRADDLE.'
        );

        $this->assertNotContains(
            $staleOrder->id,
            $listIds,
            'KitchenDisplaySystemOrderService::list MUST still exclude STALE (>8h) '
            . 'prior-day orders — the original anti-zombie intent. MINUIT-STRADDLE.'
        );
    }

    /**
     * Pin the binding format directly to catch any future re-introduction
     * of `->setTimezone('UTC')` even if SQLite test-driver hides the symptom.
     */
    public function test_list_query_binds_paris_local_literals_not_utc(): void
    {
        // Winter (UTC+1).
        $parisNow = CarbonImmutable::parse('2026-01-15 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($parisNow);
        CarbonImmutable::setTestNow($parisNow);

        $branch = Branch::factory()->create();
        $admin = $this->createAdminUser();

        // Seed at least one order so the query actually runs.
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'order_datetime' => $parisNow,
            'is_advance_order' => Ask::NO,
        ]);
        $order->forceFill(['updated_at' => $parisNow])->saveQuietly();

        $captured = [];
        DB::listen(function ($q) use (&$captured) {
            $captured[] = [
                'sql' => strtolower($q->sql),
                'bindings' => array_map(
                    static fn ($b) => is_object($b) && method_exists($b, 'format')
                        ? $b->format('Y-m-d H:i:s')
                        : (string) $b,
                    $q->bindings
                ),
            ];
        });

        $this->actingAs($admin);
        $service = app(KitchenDisplaySystemOrderService::class);
        $service->list(new Request(['branch_id' => $branch->id, 'status' => OrderStatus::PREPARING]));

        $bindingsBlob = collect($captured)
            ->filter(fn ($q) => str_contains(str_replace(['`', '"'], '', $q['sql']), 'order_datetime'))
            ->flatMap(fn ($q) => $q['bindings'])
            ->implode(' | ');

        // [MINUIT-STRADDLE 2026-07-04] La fenêtre non-advance est désormais GLISSANTE
        // (now-8h) au lieu du jour civil. Le pin TZ garde la même intention : la borne
        // basse (12:00 Paris - 8h = 04:00 PARIS-LOCAL) doit être bindée en Paris-local.
        $this->assertStringContainsString(
            '2026-01-15 04:00:00',
            $bindingsBlob,
            'KitchenDisplaySystemOrderService::list MUST bind the Paris-local sliding '
            . 'floor "2026-01-15 04:00:00" (now-8h). KDS-T-R5-01 / MINUIT-STRADDLE.'
        );

        // UTC-converted floor (winter: 12:00 Paris = 11:00 UTC → -8h = 03:00) MUST NOT
        // appear — a re-introduced `->setTimezone('UTC')` would shift the window by 1-2h.
        $this->assertStringNotContainsString(
            '2026-01-15 03:00:00',
            $bindingsBlob,
            'KitchenDisplaySystemOrderService::list MUST NOT re-introduce '
            . '`->setTimezone(\'UTC\')` on the sliding floor. KDS-T-R5-01.'
        );

        // La borne haute anti-futur (< demain 00:00 Paris) reste bindée Paris-local,
        // et sa variante UTC (23:00 la veille) reste interdite (bug Wave 2b/3b d origine).
        $this->assertStringContainsString(
            '2026-01-16 00:00:00',
            $bindingsBlob,
            'list MUST bind Paris-local tomorrow-start "2026-01-16 00:00:00". KDS-T-R5-01.'
        );
        $this->assertStringNotContainsString(
            '2026-01-15 23:00:00',
            $bindingsBlob,
            'list MUST NOT bind the UTC-shifted tomorrow bound. KDS-T-R5-01.'
        );
    }

    /**
     * Create or fetch an admin user (branch_id=0) so list() filtering by
     * branch_id query param is honored without staff-branch scope coupling.
     */
    private function createAdminUser(): User
    {
        // OrderFactory + User factory shapes vary across the codebase; use a
        // minimal raw insert to avoid coupling to seeders that may not exist
        // in the in-memory sqlite test DB.
        $existing = User::query()->where('branch_id', 0)->first();
        if ($existing) {
            return $existing;
        }
        return User::factory()->create([
            'branch_id' => 0,
        ]);
    }
}
