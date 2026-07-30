<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [CAISSE-HEALTH 2026-07-30] Endpoint santé système du poste caisse (GET /api/admin/pos/system-health).
 * Surface l'état temps réel (socket + worker outbox) + chaîne fiscale pour que l'opérateur voie une
 * dégradation AVANT de perdre des commandes en silence. READ-ONLY, gate `permission:pos`.
 */
class PosSystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function cashier(): User
    {
        $u = User::factory()->create(['branch_id' => 1]);
        $u->assignRole('POS Operator'); // porte 'pos'

        return $u;
    }

    private function insertStaleOutboxEvents(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('domain_events')->insert([
                'event_type'     => 'TestStale',
                'aggregate_type' => 'Order',
                'aggregate_id'   => $i + 1,
                'branch_id'      => 1,
                'payload'        => json_encode([]),
                'occurred_at'    => now()->subMinutes(2),
                'dispatched_at'  => null,
                'attempts'       => 0,
                'last_error'     => null,
                'created_at'     => now()->subMinutes(2),
                'updated_at'     => now()->subMinutes(2),
            ]);
        }
    }

    /** @test La permission `pos` est requise (donnée d'exploitation). */
    public function test_requires_pos_permission(): void
    {
        $stranger = User::factory()->create(); // aucun rôle → pas de 'pos'
        Sanctum::actingAs($stranger, ['*']);

        $this->getJson('/api/admin/pos/system-health')->assertStatus(403);
    }

    /** @test Forme de la réponse + le caissier y a accès. */
    public function test_returns_health_summary_for_cashier(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200)->assertJsonStructure([
            'overall',
            'checks' => [
                'sync'   => ['status', 'message'],
                'fiscal' => ['status', 'message'],
                'stock'  => ['status', 'count', 'message'],
            ],
            'stale_events',
            'queue_pending',
            'timestamp',
        ]);
        $this->assertContains($res->json('overall'), ['ok', 'degraded', 'down']);
        $this->assertContains($res->json('checks.sync.status'), ['ok', 'warn', 'down']);
    }

    /** @test Système sain (socket ok, aucun backlog) → sync ok. */
    public function test_healthy_system_reports_ok_sync(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertSame('ok', $res->json('checks.sync.status'), 'Socket ok + 0 backlog = temps réel actif.');
        $this->assertSame(0, $res->json('stale_events'));
    }

    private function markUnavailable(int $itemId, string $reason, bool $available = false): void
    {
        DB::table('item_branch_availability')->insert([
            'item_id'            => $itemId,
            'branch_id'          => 1,
            'is_available'       => $available,
            'unavailable_reason' => $reason,
            'unavailable_since'  => now(),
            'daily_reset_at'     => now()->toDateString(),
            'daily_consumed_qty' => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** @test Aucune rupture → stock ok (compteur 0). */
    public function test_no_ruptures_reports_stock_ok(): void
    {
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertSame(0, $res->json('checks.stock.count'));
        $this->assertSame('ok', $res->json('checks.stock.status'));
    }

    /**
     * @test Les ruptures de stock sont comptées, EXACTEMENT comme le dashboard rupture
     * (is_available=false + unavailable_reason stock). Un 86 manuel ou un item disponible
     * ne comptent PAS → pas de dérive entre la pastille et le dashboard.
     */
    public function test_stock_ruptures_are_counted_like_the_dashboard(): void
    {
        $this->markUnavailable(101, 'stock_rupture');
        $this->markUnavailable(102, 'out_of_stock');
        $this->markUnavailable(103, 'manual');               // 86 manuel → PAS une rupture stock
        $this->markUnavailable(104, 'stock_rupture', true);  // is_available=true → pas indisponible
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertSame(2, $res->json('checks.stock.count'), 'Seules les vraies ruptures stock comptent (2).');
        $this->assertSame('info', $res->json('checks.stock.status'));
        $this->assertStringContainsString('2', $res->json('checks.stock.message'));
    }

    /** @test Les ruptures sont INFO : elles ne dégradent pas l'état global (système sain reste ok). */
    public function test_ruptures_do_not_degrade_overall(): void
    {
        $this->markUnavailable(201, 'stock_rupture');
        $this->markUnavailable(202, 'out_of_stock');
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertGreaterThan(0, $res->json('checks.stock.count'));
        // sync ok + fiscal ok (chaîne vide en test) + stock info → overall reste 'ok'.
        $this->assertSame('ok', $res->json('overall'), 'Une rupture stock ne doit pas mettre le système en dégradé.');
    }

    /**
     * @test Socket vivant MAIS worker en retard (>10 events outbox non dispatchés) → sync DÉGRADÉ.
     * C'est le mode de panne pernicieux « connecté-mais-périmé » (soketi UP, worker DOWN) que la
     * caisse doit voir explicitement.
     */
    public function test_lagging_worker_surfaces_degraded_sync(): void
    {
        $this->insertStaleOutboxEvents(11);
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertSame('warn', $res->json('checks.sync.status'), 'Worker en retard = temps réel dégradé (warn), pas ok.');
        $this->assertGreaterThan(10, $res->json('stale_events'));
        $this->assertContains($res->json('overall'), ['degraded', 'down']);
    }

    /**
     * @test Les violations de contrat terminales (jamais retentées) ne comptent PAS comme un worker
     * en panne (miroir HealthController::checkQueueWorker) — sinon fausse alerte permanente.
     */
    public function test_contract_violations_do_not_count_as_lagging(): void
    {
        for ($i = 0; $i < 15; $i++) {
            DB::table('domain_events')->insert([
                'event_type'     => 'TestPoison',
                'aggregate_type' => 'Order',
                'aggregate_id'   => $i + 1,
                'branch_id'      => 1,
                'payload'        => json_encode([]),
                'occurred_at'    => now()->subMinutes(2),
                'dispatched_at'  => null,
                'attempts'       => 0,
                'last_error'     => 'contract_violation: payload mismatch',
                'created_at'     => now()->subMinutes(2),
                'updated_at'     => now()->subMinutes(2),
            ]);
        }
        Sanctum::actingAs($this->cashier(), ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertSame('ok', $res->json('checks.sync.status'), 'Les poison-rows terminales ne doivent PAS déclencher une fausse alerte.');
        $this->assertSame(0, $res->json('stale_events'));
    }
}
