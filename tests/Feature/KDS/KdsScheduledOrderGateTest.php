<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
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
use Tests\TestCase;

/**
 * [GOAL ULTRA-SYNC W4 2026-07-20] Gate cuisine des commandes PROGRAMMÉES (scheduled_at).
 *
 * Mandat owner : une commande pour DANS 1 h ne doit PAS s'afficher en cuisine ;
 * elle apparaît `kds.scheduled_lead_minutes` (défaut 20) minutes avant l'heure
 * cible. SSOT KitchenReleaseRule — applyScheduledBoardFilter (SQL, list()) et
 * orderIsWithinScheduledWindow (booléen, guard changeStatus) partagent UNE
 * définition : « visible == bumpable », jamais divergents (leçon du défaut
 * unreleased-order-bump). NULL = ASAP → 100 % de l'existant inchangé.
 *
 * Ce test verrouille :
 *  1. programmée T+60 min INVISIBLE sur list() (l'ASAP témoin reste visible) ;
 *  2. à T-lead (horloge injectée Carbon::setTestNow) elle DEVIENT visible
 *     (bord exact <= inclus) ;
 *  3. bump hors fenêtre → 422 + statut intact (jumeau du guard release) ;
 *  4. bump dans fenêtre + bump ASAP → 202 (pas de sur-blocage) ;
 *  5. isolation branch : la programmée en fenêtre d'une autre branche reste
 *     invisible pour le staff, visible pour l'admin (branch_id=0).
 */
class KdsScheduledOrderGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        // Pin le lead à 20 min — le contrat testé ne dépend pas d'un .env local.
        config(['kds.scheduled_lead_minutes' => 20]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Horloge de référence fixe (hiver CET — pas de piège DST). */
    private function freezeAt(string $parisTime): CarbonImmutable
    {
        $now = CarbonImmutable::parse($parisTime, 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $now;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }

    private function chef(Branch $branch): User
    {
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        return $chef;
    }

    /** Commande released board (PAID) en statut ACCEPT, ASAP ou programmée. */
    private function makeBoardOrder(Branch $branch, ?Carbon $scheduledAt = null, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => $scheduledAt,
        ], $overrides));
    }

    private function listIds(?int $branchId = null): array
    {
        $params = $branchId !== null ? ['branch_id' => $branchId] : [];

        return app(KitchenDisplaySystemOrderService::class)
            ->list(new Request($params))
            ->pluck('id')
            ->all();
    }

    private function bump(Order $order)
    {
        return $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status'          => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);
    }

    /** Ids du bandeau « ⏰ programmées à venir » (upcomingScheduled). */
    private function upcomingIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            app(KitchenDisplaySystemOrderService::class)->upcomingScheduled()
        );
    }

    /** @test */
    public function programmee_a_plus_60_min_invisible_sur_le_board_et_asap_visible(): void
    {
        $this->freezeAt('2026-03-10 12:00:00');

        $branch = Branch::factory()->create();
        $this->actingAs($this->admin());

        $scheduled = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'));
        $asap      = $this->makeBoardOrder($branch, null);

        $ids = $this->listIds($branch->id);

        $this->assertNotContains($scheduled->id, $ids,
            'KDS list : une programmée pour T+60 min (lead 20) NE DOIT PAS occuper le board.');
        $this->assertContains($asap->id, $ids,
            'KDS list : l\'ASAP (scheduled_at NULL) reste visible — existant intact.');
    }

    /** @test */
    public function programmee_devient_visible_a_T_moins_lead(): void
    {
        $this->freezeAt('2026-03-10 12:00:00');

        $branch = Branch::factory()->create();
        $this->actingAs($this->admin());

        // Cible 13:00 — lead 20 min → fenêtre ouverte à partir de 12:40 inclus.
        $scheduled = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'));

        $this->assertNotContains($scheduled->id, $this->listIds($branch->id),
            'À 12:00 (horizon 12:20 < 13:00) la programmée est encore hors fenêtre.');

        // Bord EXACT de la fenêtre : now + lead == scheduled_at → visible (<= inclus).
        $this->freezeAt('2026-03-10 12:40:00');
        $this->assertContains($scheduled->id, $this->listIds($branch->id),
            'À T-lead exact (12:40, horizon 12:40+20=13:00) la programmée DOIT apparaître sur le board.');

        // Franchement dans la fenêtre.
        $this->freezeAt('2026-03-10 12:55:00');
        $this->assertContains($scheduled->id, $this->listIds($branch->id),
            'À 12:55 la programmée reste sur le board.');
    }

    /** @test */
    public function bump_hors_fenetre_est_bloque_422_et_statut_intact(): void
    {
        $this->freezeAt('2026-03-10 12:00:00');

        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        $scheduled = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'));

        $response = $this->bump($scheduled);

        $this->assertEquals(422, $response->status(),
            'Bump d\'une programmée hors fenêtre : refusé 422 (jumeau du guard board-release — invisible == non bumpable).');
        $this->assertStringContainsString('hors fenêtre cuisine', (string) $response->json('message'),
            'Le message doit être clair pour le chef : commande programmée — hors fenêtre cuisine.');
        $this->assertEquals(OrderStatus::ACCEPT, (int) Order::find($scheduled->id)->status,
            'Le statut reste ACCEPT — aucune notification client « en préparation » des heures en avance.');
    }

    /** @test */
    public function bump_dans_fenetre_reste_possible(): void
    {
        $this->freezeAt('2026-03-10 12:00:00');

        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        // Cible 12:10 — dans l'horizon 12:20 → sur le board → bumpable.
        $scheduled = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 12:10:00', 'Europe/Paris'));

        $response = $this->bump($scheduled);

        $this->assertEquals(202, $response->status(),
            'Une programmée ENTRÉE dans sa fenêtre est bumpable — pas de sur-blocage.');
        $this->assertEquals(OrderStatus::PREPARING, (int) Order::find($scheduled->id)->status);
    }

    /** @test */
    public function bump_asap_null_comportement_historique_intact(): void
    {
        $this->freezeAt('2026-03-10 12:00:00');

        $branch = Branch::factory()->create();
        $this->actingAs($this->chef($branch), 'sanctum');

        $asap = $this->makeBoardOrder($branch, null);

        $response = $this->bump($asap);

        $this->assertEquals(202, $response->status(),
            'ASAP (scheduled_at NULL) : bump 202 — comportement historique inchangé.');
        $this->assertEquals(OrderStatus::PREPARING, (int) Order::find($asap->id)->status);
    }

    /** @test */
    public function isolation_branch_une_programmee_en_fenetre_d_une_autre_branche_reste_invisible(): void
    {
        $this->freezeAt('2026-03-10 12:00:00');

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        // Programmée EN fenêtre (T+10 min) dans la branche B.
        $scheduledB = $this->makeBoardOrder($branchB, Carbon::parse('2026-03-10 12:10:00', 'Europe/Paris'));

        // Staff branche A : ne voit PAS la commande de B (gate manuel + BranchScope).
        $this->actingAs($this->chef($branchA));
        $this->assertNotContains($scheduledB->id, $this->listIds(),
            'Isolation branch : la programmée en fenêtre de la branche B est invisible pour le staff de A.');

        // Admin (branch_id=0) : la voit — la commande est bien released + en fenêtre.
        $this->actingAs($this->admin());
        $this->assertContains($scheduledB->id, $this->listIds($branchB->id),
            'Sanity : l\'admin voit la programmée en fenêtre de B (le masquage côté staff est bien l\'isolation, pas le gate scheduled).');
    }

    /**
     * [FIX SCHEDULED-STALE 2026-07-20] Cas MANQUANT de la 1re vague : une
     * programmée CRÉÉE bien avant sa cible (10:00 → 20:00) a, à T-lead, un
     * order_datetime plus vieux que la fenêtre glissante 8h (staleFloor 11:40).
     * AND-composée avec le gate scheduled, la fenêtre legacy l'éjectait du
     * board (is_advance_order=NO) alors que le bandeau ne la portait plus
     * (complément exact) → trou noir « ni board ni bandeau », jamais cuisinée.
     *
     * @test
     */
    public function programmee_creee_a_10h_pour_20h_visible_a_T_moins_lead_malgre_la_fenetre_8h(): void
    {
        $this->freezeAt('2026-03-10 10:00:00');

        $branch = Branch::factory()->create();
        // order_datetime = now() = 10:00 — vieilli de 9h40 à T-lead.
        $scheduled = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 20:00:00', 'Europe/Paris'));

        $this->actingAs($this->admin());

        // Contrôle 19:00 (horizon 19:20 < 20:00) : encore dans le BANDEAU, pas sur le board.
        $this->freezeAt('2026-03-10 19:00:00');
        $this->assertNotContains($scheduled->id, $this->listIds($branch->id),
            'À 19:00 la programmée de 20:00 est encore hors fenêtre — pas sur le board.');
        $this->assertContains($scheduled->id, $this->upcomingIds(),
            'À 19:00 elle est dans upcomingScheduled() — le bandeau la porte (complément exact).');

        // T-lead 19:40 (horizon 20:00 >= 20:00) : elle DOIT basculer bandeau → board.
        $this->freezeAt('2026-03-10 19:40:00');
        $this->assertContains($scheduled->id, $this->listIds($branch->id),
            'À T-lead (19:40) la programmée créée à 10:00 DOIT être sur le board — la fenêtre glissante 8h (order_datetime) ne s\'applique pas aux programmées.');
        $this->assertNotContains($scheduled->id, $this->upcomingIds(),
            'À T-lead elle a quitté le bandeau — complément exact préservé (jamais dans les deux, jamais dans aucun).');
    }

    /**
     * [FIX SCHEDULED-STALE 2026-07-20] Variante J-1 : commandée la VEILLE pour
     * aujourd'hui (cas réel J-1..J-7). order_datetime date d'hier → doublement
     * hors fenêtre legacy. Verrouille list() ET sync() (parité delta — leçon
     * Wave 1 « sync doit refléter list »).
     *
     * @test
     */
    public function programmee_creee_la_veille_pour_aujourdhui_visible_a_T_moins_lead_board_et_sync(): void
    {
        $this->freezeAt('2026-03-09 18:00:00');

        $branch = Branch::factory()->create();
        $scheduled = $this->makeBoardOrder($branch, Carbon::parse('2026-03-10 20:00:00', 'Europe/Paris'));

        $this->actingAs($this->admin());

        $this->freezeAt('2026-03-10 19:40:00');

        $this->assertContains($scheduled->id, $this->listIds($branch->id),
            'Programmée créée J-1 pour aujourd\'hui : visible sur le board à T-lead (19:40).');

        Cache::flush();
        $syncIds = array_map(
            static fn ($o) => (int) ($o['id'] ?? 0),
            app(KdsSyncService::class)->sync($branch->id, new DateTimeImmutable('2000-01-01T00:00:00'), true)['orders']
        );
        $this->assertContains($scheduled->id, $syncIds,
            'Programmée créée J-1 : le delta sync la porte aussi à T-lead (parité list).');
    }
}
