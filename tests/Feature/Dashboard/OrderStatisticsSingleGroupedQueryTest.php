<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [TERRAIN-HEAL 2026-07-16 · PERF-DASHBOARD-STATUS-COUNTS] `orderStatistics` faisait 10 requêtes COUNT
 * séquentielles (total + 1 par statut) re-scannant la même fenêtre → collapsé en 1 seul agrégat GROUP BY.
 * Ce test verrouille les DEUX propriétés : (1) sémantique identique (counts par statut + total = somme),
 * (2) le gain perf réel — une SEULE requête COUNT sur `orders` (pas 10).
 *
 * @group dashboard
 * @group perf
 */
class OrderStatisticsSingleGroupedQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_statistics_correct_counts_via_single_grouped_query(): void
    {
        // Admin (branch_id=0) bypasse BranchScope → voit toutes les commandes.
        $admin = User::factory()->create(['branch_id' => 0]);
        $this->actingAs($admin, 'sanctum');

        $today = now();
        $mk = fn (int $status, int $n) => collect(range(1, $n))->each(fn () => Order::factory()->create([
            'status'         => $status,
            'order_datetime' => $today->copy()->setTime(12, 0),
            'parent_order_id' => null,
        ]));

        $mk(OrderStatus::PENDING, 3);
        $mk(OrderStatus::DELIVERED, 2);
        $mk(OrderStatus::CANCELED, 1);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $stats = app(DashboardService::class)->orderStatistics(new Request());

        // (1) Sémantique : chaque statut compté juste, total = somme de tous.
        $this->assertSame(3, (int) $stats['pending_order']);
        $this->assertSame(2, (int) $stats['delivered_order']);
        $this->assertSame(1, (int) $stats['canceled_order']);
        $this->assertSame(0, (int) $stats['preparing_order']);
        $this->assertSame(6, (int) $stats['total_order'], 'total_order = somme de tous les statuts non-miroir');

        // (2) Perf : une SEULE requête COUNT(*) sur orders pour toute la ventilation (avant : 10).
        $countQueries = collect(DB::getQueryLog())
            ->filter(function ($q) {
                $sql = strtolower($q['query']);
                // Quote-agnostic (SQLite "orders" vs MySQL `orders`) : compte les COUNT(*) sur la table orders.
                return str_contains($sql, 'count(*)') && preg_match('/\borders\b/', $sql) === 1;
            })
            ->count();

        $this->assertSame(1, $countQueries, "La ventilation par statut doit tenir en 1 requête GROUP BY, pas 10 COUNT séparés (mesuré : {$countQueries}).");
    }
}
