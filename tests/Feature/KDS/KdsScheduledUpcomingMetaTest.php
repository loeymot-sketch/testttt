<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL ULTRA-SYNC W4 2026-07-20] Bandeau « ⏰ programmées à venir » —
 * meta.scheduled_upcoming du GET /api/admin/kds-order (piggyback sur le poll
 * board, zéro requête HTTP en plus).
 *
 * Contrat verrouillé :
 *  - une programmée HORS fenêtre (scheduled_at > now + lead) est ABSENTE du
 *    board (data) mais PRÉSENTE dans meta.scheduled_upcoming (complément
 *    exact — une commande vit dans exactement un des deux ensembles) ;
 *  - payload minimal : id, order_serial_no, scheduled_at, order_type,
 *    customer_name ;
 *  - tri scheduled_at ASC (la plus proche d'abord) ;
 *  - plafond 20 entrées ;
 *  - isolation branch : le staff ne voit que sa branche, l'admin tout.
 */
class KdsScheduledUpcomingMetaTest extends TestCase
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

    private function actingAsChef(Branch $branch): User
    {
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $this->actingAs($chef, 'sanctum');

        return $chef;
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function makeScheduledOrder(Branch $branch, string $parisTime): Order
    {
        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => Carbon::parse($parisTime, 'Europe/Paris'),
        ]);
    }

    private function getBoard()
    {
        return $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/kds-order');
    }

    /** @test */
    public function meta_contient_la_programmee_hors_fenetre_absente_du_board(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch);

        $upcoming = $this->makeScheduledOrder($branch, '2026-03-10 13:00:00'); // T+60 → hors fenêtre
        $inWindow = $this->makeScheduledOrder($branch, '2026-03-10 12:10:00'); // T+10 → en fenêtre

        $response = $this->getBoard();
        $response->assertStatus(200);

        $boardIds    = collect($response->json('data'))->pluck('id')->all();
        $upcomingRows = collect($response->json('meta.scheduled_upcoming'));
        $upcomingIds  = $upcomingRows->pluck('id')->all();

        // Complément exact : hors fenêtre → bandeau, pas board ; en fenêtre → board, pas bandeau.
        $this->assertNotContains($upcoming->id, $boardIds, 'La programmée T+60 ne doit pas être sur le board (data).');
        $this->assertContains($upcoming->id, $upcomingIds, 'La programmée T+60 DOIT être dans meta.scheduled_upcoming.');
        $this->assertContains($inWindow->id, $boardIds, 'La programmée T+10 (en fenêtre) est sur le board.');
        $this->assertNotContains($inWindow->id, $upcomingIds, 'La programmée en fenêtre ne doit PAS doublonner dans le bandeau.');

        // Payload minimal auto-suffisant pour le bandeau.
        $row = $upcomingRows->firstWhere('id', $upcoming->id);
        $this->assertNotNull($row);
        foreach (['id', 'order_serial_no', 'scheduled_at', 'order_type', 'customer_name'] as $key) {
            $this->assertArrayHasKey($key, $row, "meta.scheduled_upcoming doit exposer `$key`.");
        }
        $this->assertSame($upcoming->order_serial_no, $row['order_serial_no']);
        $this->assertNotNull($row['scheduled_at'], 'scheduled_at sérialisé (ISO8601) pour affichage HH:MM côté bandeau.');
        $this->assertStringContainsString('2026-03-10', (string) $row['scheduled_at']);
        $this->assertNotNull($row['customer_name'], 'Nom client exposé quand dispo (Order::user).');
    }

    /** @test */
    public function meta_est_triee_par_scheduled_at_asc(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch);

        // Créées dans le désordre exprès — le tri doit venir du serveur.
        $late  = $this->makeScheduledOrder($branch, '2026-03-10 14:00:00'); // T+120
        $early = $this->makeScheduledOrder($branch, '2026-03-10 13:10:00'); // T+70
        $mid   = $this->makeScheduledOrder($branch, '2026-03-10 13:30:00'); // T+90

        $response = $this->getBoard();
        $response->assertStatus(200);

        $upcomingIds = collect($response->json('meta.scheduled_upcoming'))->pluck('id')->all();

        $this->assertSame([$early->id, $mid->id, $late->id], $upcomingIds,
            'Le bandeau liste les programmées par heure cible croissante (la plus proche d\'abord).');
    }

    /** @test */
    public function meta_est_plafonnee_a_20_entrees_les_plus_proches(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsChef($branch);

        $orders = [];
        for ($i = 0; $i < 22; $i++) {
            // 13:00, 13:05, … 14:45 — toutes hors fenêtre (horizon 12:20).
            $orders[] = $this->makeScheduledOrder(
                $branch,
                Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris')->addMinutes(5 * $i)->format('Y-m-d H:i:s')
            );
        }

        $response = $this->getBoard();
        $response->assertStatus(200);

        $upcomingIds = collect($response->json('meta.scheduled_upcoming'))->pluck('id')->all();

        $this->assertCount(20, $upcomingIds, 'Le bandeau est plafonné à 20 entrées.');
        $this->assertSame($orders[0]->id, $upcomingIds[0], 'Le plafond garde les 20 PLUS PROCHES (tri asc avant limit).');
        $this->assertNotContains($orders[21]->id, $upcomingIds, 'La 22e (la plus lointaine) est coupée par le plafond.');
        $this->assertNotContains($orders[20]->id, $upcomingIds, 'La 21e aussi.');
    }

    /** @test */
    public function meta_ne_fuit_pas_cross_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $upcomingA = $this->makeScheduledOrder($branchA, '2026-03-10 13:00:00');
        $upcomingB = $this->makeScheduledOrder($branchB, '2026-03-10 13:05:00');

        // Staff branche A : uniquement sa branche.
        $this->actingAsChef($branchA);
        $idsForA = collect($this->getBoard()->json('meta.scheduled_upcoming'))->pluck('id')->all();
        $this->assertContains($upcomingA->id, $idsForA, 'Le chef de A voit la programmée de A.');
        $this->assertNotContains($upcomingB->id, $idsForA, 'Isolation branch : la programmée de B ne fuit PAS vers A.');

        // Admin (branch_id=0) : vue globale — miroir des gates de list().
        $this->actingAsAdmin();
        $idsForAdmin = collect($this->getBoard()->json('meta.scheduled_upcoming'))->pluck('id')->all();
        $this->assertContains($upcomingA->id, $idsForAdmin);
        $this->assertContains($upcomingB->id, $idsForAdmin);
    }
}
