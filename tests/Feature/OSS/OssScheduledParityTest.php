<?php

namespace Tests\Feature\OSS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusScreenOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL ULTRA-SYNC W4 2026-07-20] Parité SCHEDULED OSS↔KDS.
 *
 * Le board cuisine (KDS list/orderItems/sync) masque les commandes PROGRAMMÉES
 * hors fenêtre (scheduled_at > now + lead) via le SSOT
 * KitchenReleaseRule::applyScheduledBoardFilter. Le mur client OSS doit suivre
 * la MÊME règle (rationale identique au board-release parity 2026-07-04 : le
 * mur ne montre que ce que la cuisine voit) — sinon une programmée forcée en
 * PREPARING s'afficherait « En préparation » au client des heures avant que la
 * cuisine ne la reçoive. NULL = ASAP et programmées EN fenêtre : visibles
 * (anti sur-masquage). Couvre les DEUX chemins jumeaux : list() (dashboard
 * authentifié) + listForBranch() (mur public).
 */
class OssScheduledParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['kds.scheduled_lead_minutes' => 20]);

        $now = CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeWallOrder(Branch $branch, ?Carbon $scheduledAt, string $queue): Order
    {
        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::PREPARING,
            'payment_status'   => PaymentStatus::PAID,
            'order_type'       => OrderType::TAKEAWAY,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'queue_number'     => $queue,
            'scheduled_at'     => $scheduledAt,
        ]);
    }

    /** @test */
    public function le_mur_client_cache_les_programmees_hors_fenetre_sur_les_deux_chemins(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $hidden   = $this->makeWallOrder($branch, Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'), 'A101'); // T+60 → masquée
        $inWindow = $this->makeWallOrder($branch, Carbon::parse('2026-03-10 12:10:00', 'Europe/Paris'), 'A102'); // T+10 → visible
        $asap     = $this->makeWallOrder($branch, null, 'A103');                                                 // ASAP → visible

        $svc = app(OrderStatusScreenOrderService::class);

        // === 1. OSS list (dashboard authentifié) ===
        $listIds = $svc->list()->pluck('id')->all();
        $this->assertNotContains($hidden->id, $listIds,
            'OSS list : une programmée hors fenêtre NE DOIT PAS s\'afficher au mur — la cuisine ne la voit pas encore.');
        $this->assertContains($inWindow->id, $listIds,
            'OSS list : la programmée ENTRÉE dans sa fenêtre est visible (anti sur-masquage).');
        $this->assertContains($asap->id, $listIds,
            'OSS list : l\'ASAP (scheduled_at NULL) reste visible — existant intact.');

        // === 2. OSS listForBranch (mur public — corps jumeau byte-identique) ===
        $wallIds = $svc->listForBranch($branch->id)->pluck('id')->all();
        $this->assertNotContains($hidden->id, $wallIds,
            'OSS listForBranch : parité — programmée hors fenêtre masquée du mur public aussi.');
        $this->assertContains($inWindow->id, $wallIds,
            'OSS listForBranch : programmée en fenêtre visible.');
        $this->assertContains($asap->id, $wallIds,
            'OSS listForBranch : ASAP visible.');
    }

    /** @test */
    public function la_programmee_apparait_au_mur_quand_sa_fenetre_s_ouvre(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $scheduled = $this->makeWallOrder($branch, Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'), 'A104');

        $svc = app(OrderStatusScreenOrderService::class);
        $this->assertNotContains($scheduled->id, $svc->listForBranch($branch->id)->pluck('id')->all(),
            'À 12:00 (horizon 12:20 < 13:00) : masquée.');

        // Avance l'horloge à T-lead : la fenêtre s'ouvre — le mur suit le board.
        $later = CarbonImmutable::parse('2026-03-10 12:40:00', 'Europe/Paris');
        Carbon::setTestNow($later);
        CarbonImmutable::setTestNow($later);

        $this->assertContains($scheduled->id, $svc->listForBranch($branch->id)->pluck('id')->all(),
            'À 12:40 (T-lead) la programmée apparaît au mur client, en même temps que sur le board cuisine.');
    }

    /**
     * [FIX SCHEDULED-STALE 2026-07-20] Variante J-1 : commandée la VEILLE pour
     * aujourd'hui — son order_datetime est HORS de la fenêtre glissante 8h à
     * T-lead (et is_advance_order=NO → branche advance inapplicable). La
     * fenêtre datetime legacy ne doit PAS éjecter une programmée : son
     * admission temporelle = applyScheduledBoardFilter (scheduled_at <= now+lead).
     * Parité stricte avec le board cuisine sur les DEUX chemins jumeaux.
     *
     * @test
     */
    public function la_programmee_creee_la_veille_apparait_au_mur_a_T_moins_lead(): void
    {
        $j1 = CarbonImmutable::parse('2026-03-09 18:00:00', 'Europe/Paris');
        Carbon::setTestNow($j1);
        CarbonImmutable::setTestNow($j1);

        $branch = Branch::factory()->create();
        // order_datetime = now() = J-1 18:00 ; cible aujourd'hui 20:00.
        $scheduled = $this->makeWallOrder($branch, Carbon::parse('2026-03-10 20:00:00', 'Europe/Paris'), 'A105');

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        // T-lead : 19:40 (horizon 20:00 >= 20:00) — staleFloor 11:40 > order_datetime J-1.
        $tLead = CarbonImmutable::parse('2026-03-10 19:40:00', 'Europe/Paris');
        Carbon::setTestNow($tLead);
        CarbonImmutable::setTestNow($tLead);

        $svc = app(OrderStatusScreenOrderService::class);

        $this->assertContains($scheduled->id, $svc->list()->pluck('id')->all(),
            'OSS list : la programmée créée J-1 pour aujourd\'hui DOIT être au mur à T-lead — la fenêtre glissante 8h ne s\'applique pas aux programmées.');
        $this->assertContains($scheduled->id, $svc->listForBranch($branch->id)->pluck('id')->all(),
            'OSS listForBranch : parité — même admission à T-lead sur le mur public.');
    }
}
