<?php

namespace Tests\Feature\Kds;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [PERF-KDS-BOARD 2026-07-07] Sentinelle du plan de requête du board KDS.
 *
 * Le board `KitchenDisplaySystemOrderService::list()` triait par `ORDER BY id
 * DESC LIMIT 51` sur une clause WHERE très sélective (statuts actifs + release
 * paiement + fenêtre glissante). MySQL choisissait alors un scan INVERSE de la
 * clé PRIMARY en espérant s'arrêter à 51 lignes — mais comme seules quelques
 * dizaines de lignes matchent, il parcourait la table ENTIÈRE
 * (Handler_read_prev == taille table, EXPLAIN « Index scan on orders using
 * PRIMARY (reverse) »). Mesuré prod-shape : 3128 lignes scannées, croissant
 * avec l'historique.
 *
 * FIX : FORCE INDEX MySQL-gated sur l'index de l'ensemble actif
 * (idx_orders_branch_status pour un staff de branche, idx_orders_status pour
 * l'admin) => range scan borné à l'ensemble actif (534 lignes mesurées) +
 * filesort peu coûteux. Optim de PLAN uniquement : jeu de résultats + ordre
 * IDENTIQUES.
 *
 * Ce test épingle DEUX contrats :
 *  (a) result-set — le board retourne EXACTEMENT les commandes attendues
 *      (release + fenêtre glissante + advance-overdue + isolation branche +
 *      filtre statut). Tourne sur SQLite (driver CI) ET MySQL. Garde-fou anti
 *      régression du jeu de résultats.
 *  (b) plan — sur MySQL, la requête n'est plus un full scan (type=range,
 *      key=idx_orders_*), et le hint FORCE INDEX est ABSENT sur SQLite (le gate
 *      driver ne casse pas le driver de test).
 */
class KdsBoardQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function chef(Branch $branch): User
    {
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        return $chef;
    }

    /**
     * Seed a rich mix that exercises every board predicate, and return the ids
     * that MUST appear on the board for `$branch`'s chef.
     *
     * @return array{expected:int[], branch:Branch, otherBranch:Branch}
     */
    private function seedBoardMix(): array
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();

        $mk = function (array $attrs) use ($branch): Order {
            return Order::factory()->create(array_merge([
                'branch_id' => $branch->id,
                'source' => Source::POS,
                'is_advance_order' => Ask::NO,
            ], $attrs));
        };

        // ON BOARD ------------------------------------------------------------
        // 1. active PAID, now
        $onA = $mk([
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now(),
        ]);
        // 2. active PENDING_COUNTER (Plan B), now
        $onB = $mk([
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => now(),
        ]);
        // 3. PREPARED, POS-cash UNPAID (cash release), now
        $onC = $mk([
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::UNPAID,
            'order_type' => OrderType::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_datetime' => now(),
        ]);
        // 4. midnight-straddle: 2h ago, still inside the 8h sliding window
        $onD = $mk([
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now()->subHours(2),
        ]);
        // 5. advance-overdue: scheduled 2 days ago, still active
        $onE = $mk([
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now()->subDays(2),
            'is_advance_order' => Ask::YES,
        ]);

        // OFF BOARD -----------------------------------------------------------
        // 6. too old (20h ago), non-advance => outside sliding window
        $mk([
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now()->subHours(20),
        ]);
        // 7. unreleased: UNPAID non-cash delivery => board-release filter drops it
        $mk([
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'order_type' => OrderType::DELIVERY,
            'payment_method' => 1,
            'order_datetime' => now(),
        ]);
        // 8. inactive status: DELIVERED => not a visible status
        $mk([
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now(),
        ]);
        // 9. other branch, otherwise on-board => branch isolation drops it
        Order::factory()->create([
            'branch_id' => $otherBranch->id,
            'source' => Source::POS,
            'is_advance_order' => Ask::NO,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now(),
        ]);

        $expected = collect([$onA, $onB, $onC, $onD, $onE])
            ->map(fn (Order $o) => (int) $o->id)
            ->sort()
            ->values()
            ->all();

        return ['expected' => $expected, 'branch' => $branch, 'otherBranch' => $otherBranch];
    }

    /**
     * Contract (a): the optimisation preserves the EXACT result set. Runs on the
     * SQLite CI driver (where the FORCE INDEX hint is a no-op) AND on MySQL.
     */
    public function test_board_returns_exact_expected_active_set(): void
    {
        $mix = $this->seedBoardMix();
        $this->actingAs($this->chef($mix['branch']), 'sanctum');

        $service = app(KitchenDisplaySystemOrderService::class);
        $result = $service->list(Request::create('/api/admin/kds-order', 'GET'));

        $got = $result->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame(
            $mix['expected'],
            $got,
            "Le fix d'index NE DOIT PAS changer le jeu de résultats du board "
            . '(release + fenêtre glissante + advance-overdue + isolation branche '
            . '+ filtre statut). Attendu ' . json_encode($mix['expected'])
            . ' obtenu ' . json_encode($got) . '.'
        );
    }

    /**
     * Contract (a) — ordering: le tri par défaut du board (`ORDER BY id ASC`,
     * cf. list() `order_by ?? 'asc'`) doit rester intact malgré le FORCE INDEX
     * (le tri devient un filesort mais l'ordre est strictement le même).
     */
    public function test_board_preserves_default_id_ordering(): void
    {
        $mix = $this->seedBoardMix();
        $this->actingAs($this->chef($mix['branch']), 'sanctum');

        $service = app(KitchenDisplaySystemOrderService::class);
        $result = $service->list(Request::create('/api/admin/kds-order', 'GET'));

        $ids = $result->pluck('id')->map(fn ($id) => (int) $id)->all();
        $sortedAsc = $ids;
        sort($sortedAsc);

        $this->assertSame($sortedAsc, $ids, 'Le board doit rester trié par id ASC (défaut).');
    }

    /**
     * Contract (b) — SQLite: le gate driver saute le FORCE INDEX (sinon syntax
     * error sur SQLite). On capture le SQL compilé et on prouve l'absence du
     * hint sur SQLite (et sa présence sur MySQL).
     */
    public function test_force_index_hint_matches_driver(): void
    {
        $mix = $this->seedBoardMix();
        $this->actingAs($this->chef($mix['branch']), 'sanctum');

        $captured = [];
        DB::listen(function ($q) use (&$captured) {
            $captured[] = strtolower($q->sql);
        });

        $service = app(KitchenDisplaySystemOrderService::class);
        $service->list(Request::create('/api/admin/kds-order', 'GET'));

        $boardSql = collect($captured)->first(fn (string $sql) => str_contains($sql, 'from `orders`')
            || str_contains($sql, 'from "orders"'));
        $this->assertNotNull($boardSql, 'La requête board sur `orders` doit être capturée.');

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            $this->assertStringContainsString('force index', $boardSql,
                'Sur MySQL le board DOIT porter le hint FORCE INDEX (anti full-scan).');
        } else {
            $this->assertStringNotContainsString('force index', $boardSql,
                'Sur SQLite (driver CI) le hint FORCE INDEX DOIT être absent — '
                . 'sinon syntax error, le gate driver protège les tests.');
        }
    }

    /**
     * Contract (b) — MySQL: la requête board n'est plus un full scan. type=range
     * sur un index de l'ensemble actif (idx_orders_branch_status pour un staff).
     * Skippé sur SQLite (pas d'EXPLAIN exploitable / pas de piège optimiseur).
     */
    public function test_board_query_is_a_range_scan_not_full_scan_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Assertion de plan MySQL-only (driver de test = SQLite).');
        }

        $mix = $this->seedBoardMix();
        $this->actingAs($this->chef($mix['branch']), 'sanctum');

        $captured = [];
        DB::listen(function ($q) use (&$captured) {
            if (str_contains(strtolower($q->sql), 'force index')) {
                $captured[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
            }
        });

        $service = app(KitchenDisplaySystemOrderService::class);
        $service->list(Request::create('/api/admin/kds-order', 'GET'));

        $this->assertNotEmpty($captured, 'La requête board FORCE INDEX doit être capturée.');
        $board = $captured[0];

        $rows = DB::select('EXPLAIN FORMAT=TRADITIONAL ' . $board['sql'], $board['bindings']);
        $plan = (array) $rows[0];

        $this->assertNotSame('ALL', $plan['type'] ?? null,
            'Le board NE DOIT PAS être un full table scan (type=ALL).');
        $this->assertNotSame('index', $plan['type'] ?? null,
            'Le board NE DOIT PAS être un full index scan (type=index / PRIMARY reverse).');
        $this->assertSame('range', $plan['type'] ?? null,
            'Le board DOIT être un index range scan sur l\'ensemble actif.');
        $this->assertStringStartsWith('idx_orders_', (string) ($plan['key'] ?? ''),
            'Le board DOIT utiliser un index de l\'ensemble actif (idx_orders_*), '
            . 'pas la clé PRIMARY.');
    }
}
