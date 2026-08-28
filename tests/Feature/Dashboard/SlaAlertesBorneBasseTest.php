<?php

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.3]
 *
 * `DashboardService::slaAlerts()` filtrait `->where('updated_at', '<', now()->subMinutes(15))`
 * SANS BORNE BASSE. Une commande restée en préparation il y a des mois y figurait donc
 * indéfiniment.
 *
 * MESURE RÉELLE sur la base de développement, le 2026-08-25 :
 *   344 commandes en PREPARING depuis plus de 15 minutes — et **les 344** avaient plus de 24 h,
 *   la plus ancienne datant du 2026-06-10 (75 jours).
 *
 * Autrement dit : le panneau d'alertes SLA affichait 344 lignes dont AUCUNE n'était actionnable.
 * Une vraie commande en retard y aurait été noyée. Une alerte qui alerte toujours n'alerte plus —
 * c'est pire qu'un panneau vide, parce qu'elle inspire confiance.
 *
 * Comportement attendu : une alerte SLA concerne le service EN COURS. Au-delà de la fenêtre
 * (`dashboard.sla_alerts_window_hours`, défaut 24 h), ce n'est plus un retard : c'est de la
 * donnée morte, qui relève d'un nettoyage d'exploitation et non du tableau de bord.
 *
 * @group sentinel
 * @group dashboard
 */

namespace Tests\Feature\Dashboard;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlaAlertesBorneBasseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
    }

    /** Crée une commande en préparation, dont on force la date de dernière mise à jour. */
    private function commandeEnPreparationDepuis(Branch $branch, string $ilYA): Order
    {
        $order = Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::PREPARING,
            'payment_status'   => PaymentStatus::PAID,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => now()->sub($ilYA)->toDateTimeString(),
            'total'            => 10.0,
            'total_tax'        => 0,
            'is_advance_order' => Ask::NO,
            'source'           => Source::APP,
        ]);

        // Eloquent réécrit `updated_at` à chaque save : on la pose directement.
        DB::table('orders')->where('id', $order->id)
            ->update(['updated_at' => now()->sub($ilYA)->toDateTimeString()]);

        return $order->fresh();
    }

    private function alertes(): array
    {
        return app(DashboardService::class)->slaAlerts()->toArray();
    }

    public function test_une_commande_reellement_en_retard_est_signalee(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        $enRetard = $this->commandeEnPreparationDepuis($branch, '45 minutes');

        $serials = array_column($this->alertes(), 'order_serial_no');

        $this->assertContains(
            $enRetard->order_serial_no,
            $serials,
            'Une commande en préparation depuis 45 min doit rester une alerte SLA.',
        );
    }

    public function test_une_commande_recente_n_alerte_pas(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        $recente = $this->commandeEnPreparationDepuis($branch, '5 minutes');

        $serials = array_column($this->alertes(), 'order_serial_no');

        $this->assertNotContains($recente->order_serial_no, $serials);
    }

    public function test_une_commande_figee_depuis_des_mois_n_est_plus_une_alerte_sla(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        $fossile = $this->commandeEnPreparationDepuis($branch, '75 days');

        $serials = array_column($this->alertes(), 'order_serial_no');

        $this->assertNotContains(
            $fossile->order_serial_no,
            $serials,
            "Une commande figée depuis 75 jours n'est pas un retard de service : elle noie les vraies alertes.",
        );
    }

    public function test_la_fenetre_est_bornee_des_deux_cotes(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        $dedans  = $this->commandeEnPreparationDepuis($branch, '23 hours');
        $dehors  = $this->commandeEnPreparationDepuis($branch, '25 hours');

        $serials = array_column($this->alertes(), 'order_serial_no');

        $this->assertContains($dedans->order_serial_no, $serials, 'Borne haute : 23 h doit rester dans la fenêtre.');
        $this->assertNotContains($dehors->order_serial_no, $serials, 'Borne basse : 25 h doit sortir de la fenêtre.');
    }

    public function test_une_masse_de_donnees_mortes_ne_noie_plus_la_seule_vraie_alerte(): void
    {
        // Reproduction fidèle du terrain : beaucoup de fossiles, une seule alerte réelle.
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        for ($i = 0; $i < 20; $i++) {
            $this->commandeEnPreparationDepuis($branch, (30 + $i) . ' days');
        }
        $vraie = $this->commandeEnPreparationDepuis($branch, '40 minutes');

        $alertes = $this->alertes();

        $this->assertCount(1, $alertes, 'Seule la commande réellement en retard doit alerter.');
        $this->assertSame($vraie->order_serial_no, $alertes[0]['order_serial_no']);
    }
}
