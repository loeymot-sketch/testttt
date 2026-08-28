<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Http\Controllers\Admin\PosSystemHealthController;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
        Cache::flush();
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function cashier(int $branchId = 1): User
    {
        if ($branchId > 0 && ! Branch::withoutGlobalScopes()->find($branchId)) {
            Branch::factory()->create(['id' => $branchId]);
        }
        $u = User::factory()->create(['branch_id' => $branchId]);
        $u->assignRole('POS Operator'); // porte 'pos'

        return $u;
    }

    private function insertStaleOutboxEvents(int $n, int $branchId = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('domain_events')->insert([
                'event_type'     => 'TestStale',
                'aggregate_type' => 'Order',
                'aggregate_id'   => $i + 1,
                'branch_id'      => $branchId,
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
                'aging'  => ['status', 'count', 'message'],
            ],
            'stale_events',
            'queue_pending',
            'timestamp',
        ]);
        $this->assertContains($res->json('overall'), ['ok', 'degraded', 'down']);
        $this->assertContains($res->json('checks.sync.status'), ['ok', 'warn', 'unknown', 'down']);
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

    private function markUnavailable(int $itemId, string $reason, bool $available = false, int $branchId = 1): void
    {
        DB::table('item_branch_availability')->insert([
            'item_id'            => $itemId,
            'branch_id'          => $branchId,
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
     * @test Les commandes PAS ENCORE PRÊTES de plus de 15 min sont comptées ; les récentes, prêtes,
     * livrées et annulées ne le sont PAS. INFO : ne dégrade pas l'overall.
     */
    public function test_aging_orders_are_counted(): void
    {
        if (! Branch::withoutGlobalScopes()->find(1)) {
            Branch::factory()->create(['id' => 1]); // FK orders.branch_id → branches.id
        }
        $cashier = $this->cashier();
        $mk = function (int $status, int $ageMin) use ($cashier): void {
            $o = Order::factory()->create([
                'branch_id' => 1,
                'user_id'   => $cashier->id,
                'status'    => $status,
                'total'     => 10,
                'subtotal'  => 10,
                'discount'  => 0,
            ]);
            DB::table('orders')->where('id', $o->id)->update(['created_at' => now()->subMinutes($ageMin)]);
        };
        $mk(OrderStatus::PENDING, 20);    // vieille + pas prête → comptée
        $mk(OrderStatus::PREPARING, 30);  // vieille + pas prête → comptée
        $mk(OrderStatus::PENDING, 5);     // récente → PAS comptée
        $mk(OrderStatus::PREPARED, 40);   // prête (finie côté cuisine) → PAS comptée
        $mk(OrderStatus::DELIVERED, 40);  // livrée → PAS comptée
        // [REPLAN_8 2026-08-24] Traînardes au-delà de 24 h → PAS comptées. Sans borne basse, toute
        // commande jamais sortie de PENDING comptait à vie : mesuré en base, 248 « en retard »
        // dont ZÉRO des dernières 24 h. La pastille criait un chiffre à trois chiffres en
        // permanence — un compteur qui hurle sans arrêt se fait ignorer comme un faux vert.
        $mk(OrderStatus::PENDING, 60 * 25);      // 25 h → hors fenêtre
        $mk(OrderStatus::PREPARING, 60 * 24 * 7); // 7 jours → hors fenêtre

        Sanctum::actingAs($cashier, ['*']);
        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200);
        $this->assertSame(
            2,
            $res->json('checks.aging.count'),
            'Seules les commandes pas-encore-prêtes de plus de 15 min ET de moins de 24 h comptent.'
        );
        $this->assertSame('info', $res->json('checks.aging.status'));
        $this->assertSame('ok', $res->json('overall'), 'Des commandes en retard ne dégradent pas l\'état système (info).');
    }

    /**
     * @test Socket vivant MAIS worker en retard (>10 events outbox non dispatchés) → sync DÉGRADÉ.
     * C'est le mode de panne pernicieux « connecté-mais-périmé » (soketi UP, worker DOWN) que la
     * caisse doit voir explicitement.
     */
    /**
     * @test Frontière EXACTE du seuil outbox : à 10 events pile, la caisse ne dit pas « tout va bien ».
     *
     * [REPLAN_8 2026-08-24] Le seuil est `> 10`. Le test existant n'utilisait que 11 : à 10 pile —
     * worker mort, dix commandes bloquées — la réponse restait `sync: ok` + « Les commandes
     * arrivent en direct. » Zone morte verte que rien n'épinglait. On fixe les deux côtés.
     */
    public function test_outbox_threshold_boundary_is_pinned_on_both_sides(): void
    {
        $this->insertStaleOutboxEvents(10);
        Sanctum::actingAs($this->cashier(), ['*']);
        $res = $this->getJson('/api/admin/pos/system-health');
        $res->assertStatus(200);
        $this->assertSame(10, $res->json('stale_events'));
        $this->assertSame(
            'ok',
            $res->json('checks.sync.status'),
            'À 10 pile le contrat actuel reste ok : ce test FIGE la frontière, il ne la déplace pas.'
        );

        $this->insertStaleOutboxEvents(1); // 11 au total → bascule
        $res2 = $this->getJson('/api/admin/pos/system-health');
        $this->assertSame(11, $res2->json('stale_events'));
        $this->assertSame('warn', $res2->json('checks.sync.status'), 'À 11, le retard doit être dit.');
    }

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

    /**
     * @test Sans branche exploitable, la réponse reste AFFICHABLE et n'invente aucune donnée.
     *
     * [REPLAN_8 2026-08-24] Ce test exigeait auparavant un HTTP 422. C'était une régression
     * silencieuse : l'appel axios de la pastille part en `catch` sur tout non-2xx, donc les
     * 16 comptes Admin actifs en `branch_id = 0` voyaient « Contrôle indisponible » ambre à vie
     * avec un bouton « Réessayer » incapable de réussir — et le corps 422 n'était rendu par
     * personne. On exige désormais un 200 affichable, quatre sondes `unknown`, un message
     * actionnable, et ZÉRO donnée d'une autre branche.
     */
    public function test_pos_health_without_branch_stays_renderable_and_leaks_nothing(): void
    {
        $cashier = $this->cashier(0);
        Sanctum::actingAs($cashier, ['*']);

        $res = $this->getJson('/api/admin/pos/system-health');

        $res->assertStatus(200)
            ->assertJsonPath('overall', 'degraded')
            ->assertJsonPath('branch_required', true)
            ->assertJsonPath('checks.fiscal.status', 'unknown')
            ->assertJsonPath('checks.sync.status', 'unknown')
            ->assertJsonPath('checks.stock.status', 'unknown')
            ->assertJsonPath('checks.aging.status', 'unknown');

        // Aucune sonde n'a tourné : aucun compteur d'une autre branche ne peut fuir.
        $this->assertNull($res->json('stale_events'));
        $this->assertNull($res->json('queue_pending'));
        $this->assertNull($res->json('checks.stock.count'));
        $this->assertNull($res->json('checks.aging.count'));

        // Chaque message doit dire QUOI FAIRE, pas seulement constater la panne.
        foreach (['sync', 'fiscal', 'stock', 'aging'] as $sonde) {
            $this->assertMatchesRegularExpression(
                '/sélectionne une succursale/i',
                (string) $res->json("checks.$sonde.message"),
                "Le message de la sonde $sonde doit être actionnable."
            );
        }
    }

    /** @test Cache et vérification fiscale sont strictement séparés par branche. */
    public function test_fiscal_health_uses_the_authenticated_branch_and_distinct_cache_keys(): void
    {
        $service = $this->mock(AuditLogService::class);
        $service->shouldReceive('verifyChain')->once()->with(1)->andReturn(null);
        $service->shouldReceive('verifyChain')->once()->with(2)->andReturn(987);

        Sanctum::actingAs($this->cashier(1), ['*']);
        $this->getJson('/api/admin/pos/system-health')
            ->assertOk()
            ->assertJsonPath('checks.fiscal.status', 'ok');
        // Même branche : doit relire son cache sans relancer verifyChain().
        $this->getJson('/api/admin/pos/system-health')
            ->assertOk()
            ->assertJsonPath('checks.fiscal.status', 'ok');

        Sanctum::actingAs($this->cashier(2), ['*']);
        $this->getJson('/api/admin/pos/system-health')
            ->assertOk()
            ->assertJsonPath('checks.fiscal.status', 'alert');

        $this->assertNotNull(Cache::get('pos_system_health_fiscal:1'));
        $this->assertNotNull(Cache::get('pos_system_health_fiscal:2'));
        $this->assertNull(Cache::get('pos_system_health_fiscal'));
    }

    /** @test Stock, vieillissement et backlog outbox ne traversent jamais les branches. */
    public function test_operational_health_probes_are_exactly_branch_scoped(): void
    {
        $branchOneCashier = $this->cashier(1);
        $branchTwoCashier = $this->cashier(2);

        $this->markUnavailable(701, 'stock_rupture', false, 1);
        $this->markUnavailable(702, 'stock_rupture', false, 1);
        $this->markUnavailable(703, 'stock_rupture', false, 2);

        foreach ([[1, $branchOneCashier], [2, $branchTwoCashier], [2, $branchTwoCashier]] as [$branchId, $cashier]) {
            $order = Order::factory()->create([
                'branch_id' => $branchId,
                'user_id' => $cashier->id,
                'status' => OrderStatus::PREPARING,
                'total' => 10,
                'subtotal' => 10,
                'discount' => 0,
            ]);
            DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes(30)]);
        }
        $this->insertStaleOutboxEvents(11, 1);

        Sanctum::actingAs($branchOneCashier, ['*']);
        $branchOne = $this->getJson('/api/admin/pos/system-health')->assertOk();
        $branchOne->assertJsonPath('checks.stock.count', 2)
            ->assertJsonPath('checks.aging.count', 1)
            ->assertJsonPath('stale_events', 11)
            ->assertJsonPath('checks.sync.status', 'warn');

        Sanctum::actingAs($branchTwoCashier, ['*']);
        $branchTwo = $this->getJson('/api/admin/pos/system-health')->assertOk();
        $branchTwo->assertJsonPath('checks.stock.count', 1)
            ->assertJsonPath('checks.aging.count', 2)
            ->assertJsonPath('stale_events', 0)
            ->assertJsonPath('checks.sync.status', 'ok');
    }

    /**
     * @test Un socket EN ÉCHEC certain n'est pas effacé par l'incertitude d'une sonde voisine.
     *
     * [REPLAN_8 2026-08-24] L'ordre précédent testait `queue null` AVANT `socket fail` : une panne
     * de queue — qui accompagne typiquement une panne de socket — rétrogradait un « temps réel
     * coupé » CERTAIN (rang 2, rouge) en « indisponible » (rang 1, ambre). On effaçait la mauvaise
     * nouvelle avec l'incertitude d'à côté. Un fait dur prime toujours sur une inconnue.
     */
    public function test_hard_socket_failure_is_not_downgraded_by_an_unknown_neighbour(): void
    {
        $controller = new class extends PosSystemHealthController
        {
            protected function websocketStatus(): string
            {
                return 'fail'; // fait dur : le socket a répondu, il est mort
            }

            protected function queuePendingCount(): ?int
            {
                return null; // incertitude simultanée
            }

            protected function staleOutboxCount(int $branchId): ?int
            {
                return null; // incertitude simultanée
            }
        };
        $this->app->instance(PosSystemHealthController::class, $controller);

        Sanctum::actingAs($this->cashier(), ['*']);
        $res = $this->getJson('/api/admin/pos/system-health')->assertOk();

        $this->assertSame(
            'down',
            $res->json('checks.sync.status'),
            'Un socket testé en échec doit rester « coupé », pas devenir « indisponible ».'
        );
        $this->assertSame('down', $res->json('overall'), 'La sévérité ne doit jamais redescendre.');
        $this->assertNull($res->json('queue_pending'), 'La panne de file reste dite, sans faux zéro.');
    }

    /** @test Une panne de sonde reste visible et ne devient jamais un zéro ou un faux OK. */
    public function test_probe_failures_surface_unknown_and_degraded_instead_of_false_green(): void
    {
        $controller = new class extends PosSystemHealthController
        {
            protected function websocketStatus(): string
            {
                return 'unknown';
            }

            protected function queuePendingCount(): ?int
            {
                return null;
            }

            protected function staleOutboxCount(int $branchId): ?int
            {
                return null;
            }

            protected function stockRuptureCount(int $branchId): ?int
            {
                return null;
            }

            protected function agingOrdersCount(int $branchId): ?int
            {
                return null;
            }

            protected function fiscalStatus(int $branchId): array
            {
                return ['status' => 'unknown', 'message' => 'Contrôle fiscal indisponible.'];
            }
        };
        $this->app->instance(PosSystemHealthController::class, $controller);

        Sanctum::actingAs($this->cashier(2), ['*']);
        $res = $this->getJson('/api/admin/pos/system-health')->assertOk();

        $res->assertJsonPath('overall', 'degraded')
            ->assertJsonPath('checks.sync.status', 'unknown')
            ->assertJsonPath('checks.fiscal.status', 'unknown')
            ->assertJsonPath('checks.stock.status', 'unknown')
            ->assertJsonPath('checks.stock.count', null)
            ->assertJsonPath('checks.aging.status', 'unknown')
            ->assertJsonPath('checks.aging.count', null)
            ->assertJsonPath('stale_events', null)
            ->assertJsonPath('queue_pending', null);

        $this->assertNull(Cache::get('pos_system_health_fiscal:2'), 'Un état unknown ne doit pas être caché cinq minutes.');
    }

    /** @test Un socket coupé annonce le repli sans promettre qu'aucune commande ne peut être perdue. */
    public function test_websocket_failure_message_is_actionable_without_false_zero_loss_promise(): void
    {
        $controller = new class extends PosSystemHealthController
        {
            protected function websocketStatus(): string
            {
                return 'fail';
            }

            protected function staleOutboxCount(int $branchId): ?int
            {
                return 0;
            }

            protected function fiscalStatus(int $branchId): array
            {
                return ['status' => 'ok', 'message' => 'Chaîne fiscale intègre.'];
            }
        };
        $this->app->instance(PosSystemHealthController::class, $controller);

        Sanctum::actingAs($this->cashier(1), ['*']);
        $response = $this->getJson('/api/admin/pos/system-health')->assertOk();
        $message = (string) $response->json('checks.sync.message');

        $response->assertJsonPath('overall', 'down')
            ->assertJsonPath('checks.sync.status', 'down');
        $this->assertStringContainsString('rafraîchissement automatique', $message);
        $this->assertStringContainsString('Vérifie les nouvelles commandes', $message);
        $this->assertStringNotContainsString('Aucune commande n\'est perdue', $message);
    }

    /** @test Une panne du driver de queue dégrade explicitement le sync et ne devient jamais un faux vert. */
    public function test_queue_driver_failure_surfaces_null_instead_of_false_zero(): void
    {
        Queue::shouldReceive('size')->once()->with('default')->andThrow(new \RuntimeException('queue unavailable'));

        Sanctum::actingAs($this->cashier(1), ['*']);

        $response = $this->getJson('/api/admin/pos/system-health')
            ->assertOk()
            ->assertJsonPath('queue_pending', null)
            ->assertJsonPath('checks.sync.status', 'unknown')
            ->assertJsonPath('overall', 'degraded');

        $this->assertStringContainsString('file de traitement', (string) $response->json('checks.sync.message'));
        $this->assertStringContainsString('support', (string) $response->json('checks.sync.message'));
    }

    /** @test Une panne de lecture cache retombe sur la vérification fiscale live sans HTTP 500. */
    public function test_fiscal_cache_read_failure_uses_live_probe(): void
    {
        Cache::shouldReceive('get')
            ->once()
            ->with('pos_system_health_fiscal:1')
            ->andThrow(new \RuntimeException('cache read unavailable'));
        Cache::shouldReceive('put')->once()->andReturnTrue();
        $this->mock(AuditLogService::class)
            ->shouldReceive('verifyChain')
            ->once()
            ->with(1)
            ->andReturn(null);

        Sanctum::actingAs($this->cashier(1), ['*']);

        $this->getJson('/api/admin/pos/system-health')
            ->assertOk()
            ->assertJsonPath('checks.fiscal.status', 'ok');
    }

    /** @test Une panne d'écriture cache ne dégrade pas une sonde fiscale live saine. */
    public function test_fiscal_cache_write_failure_preserves_live_probe_result(): void
    {
        Cache::shouldReceive('get')
            ->once()
            ->with('pos_system_health_fiscal:1')
            ->andReturnNull();
        Cache::shouldReceive('put')
            ->once()
            ->with('pos_system_health_fiscal:1', ['status' => 'ok', 'message' => 'Chaîne fiscale intègre.'], 300)
            ->andThrow(new \RuntimeException('cache write unavailable'));
        $this->mock(AuditLogService::class)
            ->shouldReceive('verifyChain')
            ->once()
            ->with(1)
            ->andReturn(null);

        Sanctum::actingAs($this->cashier(1), ['*']);

        $this->getJson('/api/admin/pos/system-health')
            ->assertOk()
            ->assertJsonPath('checks.fiscal.status', 'ok');
    }
}
