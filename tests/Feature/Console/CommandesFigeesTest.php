<?php

namespace Tests\Feature\Console;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — contrepartie de T-5.3.3]
 *
 * Borner la fenêtre des alertes SLA a supprimé 344 fausses alertes — mais a aussi rendu ces
 * 344 commandes figées **invisibles** : aucune autre surface ne les comptait. Supprimer du bruit
 * ne doit pas créer un angle mort.
 *
 * `foodking:commandes-figees` regarde exactement ce que le tableau de bord ne regarde plus.
 * Ces tests garantissent qu'elle reste **strictement en lecture** : une commande figée peut
 * porter une trace fiscale à conserver 6 ans (NF525), et aucune automatisation ne doit y toucher.
 *
 * @group console
 */
class CommandesFigeesTest extends TestCase
{
    use RefreshDatabase;

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

        DB::table('orders')->where('id', $order->id)
            ->update(['updated_at' => now()->sub($ilYA)->toDateTimeString()]);

        return $order->fresh();
    }

    public function test_signale_les_commandes_figees_au_dela_du_seuil(): void
    {
        $branch = Branch::factory()->create();
        $fossile = $this->commandeEnPreparationDepuis($branch, '75 days');

        $this->artisan('foodking:commandes-figees', ['--jours' => 1])
            ->expectsOutputToContain($fossile->order_serial_no)
            ->assertSuccessful();
    }

    public function test_ignore_une_commande_recente(): void
    {
        $branch = Branch::factory()->create();
        $this->commandeEnPreparationDepuis($branch, '2 hours');

        $this->artisan('foodking:commandes-figees', ['--jours' => 1])
            ->expectsOutputToContain('Aucune commande figée')
            ->assertSuccessful();
    }

    public function test_ne_modifie_strictement_rien(): void
    {
        // Le point le plus important : une commande figée peut porter une trace fiscale.
        $branch = Branch::factory()->create();
        $fossile = $this->commandeEnPreparationDepuis($branch, '75 days');

        $avant = DB::table('orders')->where('id', $fossile->id)->first();
        $nombreAvant = DB::table('orders')->count();

        $this->artisan('foodking:commandes-figees', ['--jours' => 1])->assertSuccessful();

        $apres = DB::table('orders')->where('id', $fossile->id)->first();

        $this->assertEquals($avant, $apres, 'La commande ne doit être modifiée en aucune façon.');
        $this->assertSame($nombreAvant, DB::table('orders')->count(), 'Aucune ligne ne doit disparaître.');
        $this->assertSame(OrderStatus::PREPARING, (int) $apres->status, 'Le statut ne doit pas être « corrigé » automatiquement.');
    }

    public function test_la_sortie_json_est_exploitable(): void
    {
        $branch = Branch::factory()->create();
        $this->commandeEnPreparationDepuis($branch, '40 days');

        $this->artisan('foodking:commandes-figees', ['--jours' => 1, '--json' => true])
            ->expectsOutputToContain('"total":1')
            ->assertSuccessful();
    }

    public function test_le_seuil_est_reglable(): void
    {
        $branch = Branch::factory()->create();
        $this->commandeEnPreparationDepuis($branch, '10 days');

        // Sous un seuil de 30 jours, elle ne doit pas remonter.
        $this->artisan('foodking:commandes-figees', ['--jours' => 30])
            ->expectsOutputToContain('Aucune commande figée')
            ->assertSuccessful();

        // Sous un seuil de 5 jours, elle doit remonter.
        $this->artisan('foodking:commandes-figees', ['--jours' => 5])
            ->doesntExpectOutputToContain('Aucune commande figée')
            ->assertSuccessful();
    }
}
