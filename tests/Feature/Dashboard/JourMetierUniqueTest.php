<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinelle — « aujourd'hui » a UN seul sens sur le tableau de bord.
 *
 * Le défaut, mesuré sur la base réelle au 28/05/2026 : la tuile « Ventes du jour »
 * affichait **1 494,00 €** et la tuile « Chiffre d'Affaires du Jour », quinze centimètres
 * plus bas sur le même écran, **1 598,90 €**. Deux repères de date différents —
 * `business_date` pour l'une, `order_datetime` pour l'autre — pour ce qu'un exploitant lit
 * comme le même fait.
 *
 * Deux défauts se cumulaient :
 *   1. `where('business_date', ...)` faisait DISPARAÎTRE les commandes dont la date métier
 *      est nulle (167 sur 3252 en base). Un chiffre d'affaires amputé ressemble à une
 *      journée creuse : c'est le chiffre qui déclenche une action.
 *   2. Rien ne forçait les deux tuiles à rester d'accord.
 *
 * Ce banc vérifie les deux, et il vérifie l'ACCORD plutôt qu'une implémentation : si la
 * définition du jour métier change, les deux tuiles devront changer ensemble.
 */
class JourMetierUniqueTest extends TestCase
{
    use RefreshDatabase;

    private function commandePayee(?string $dateMetier, string $horodatage, float $montant): Order
    {
        return Order::factory()->create([
            'business_date'  => $dateMetier,
            'order_datetime' => $horodatage,
            'total'          => $montant,
            'status'         => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'parent_order_id' => null,
        ]);
    }

    /** @test */
    public function une_commande_sans_date_metier_n_est_plus_perdue(): void
    {
        $aujourdhui = now()->toDateString();

        $this->commandePayee($aujourdhui, $aujourdhui . ' 12:00:00', 100.00);
        // Celle-ci n'a pas de date métier : avant le correctif elle s'évaporait.
        $this->commandePayee(null, $aujourdhui . ' 20:30:00', 35.50);

        $ventes = (float) app(DashboardService::class)->totalSales('today');

        $this->assertEqualsWithDelta(
            135.50,
            $ventes,
            0.001,
            "Une commande sans date métier doit retomber sur son horodatage, pas disparaître. "
            . "Obtenu {$ventes} € au lieu de 135,50 € : le repli manque, et le chiffre "
            . 'd\'affaires affiché est amputé sans que rien ne le signale.',
        );
    }

    /** @test */
    public function les_deux_tuiles_du_jour_annoncent_le_meme_chiffre(): void
    {
        $aujourdhui = now()->toDateString();

        $this->commandePayee($aujourdhui, $aujourdhui . ' 11:00:00', 60.00);
        $this->commandePayee(null, $aujourdhui . ' 23:45:00', 40.00);

        $service = app(DashboardService::class);

        $venteDuJour = (float) $service->totalSales('today');          // tuile « Ventes du jour »
        $tempsReel = $service->realtimeReport();                        // tuile « Chiffre d'Affaires du Jour »
        $caDuJour = (float) ($tempsReel['daily_sales'] ?? -1);

        $this->assertEqualsWithDelta(
            $venteDuJour,
            $caDuJour,
            0.001,
            "Les deux tuiles affichent le chiffre d'affaires du jour sur le même écran : "
            . "« Ventes du jour » dit {$venteDuJour} €, « Chiffre d'Affaires du Jour » dit "
            . "{$caDuJour} €. Un même fait doit donner un même nombre.",
        );
    }

    /** @test */
    public function une_commande_de_la_veille_ne_compte_pas_pour_aujourd_hui(): void
    {
        $aujourdhui = now()->toDateString();
        $hier = now()->subDay()->toDateString();

        $this->commandePayee($aujourdhui, $aujourdhui . ' 12:00:00', 50.00);
        // Service du soir de la veille, encaissé après minuit : la date métier fait foi.
        $this->commandePayee($hier, $aujourdhui . ' 00:20:00', 80.00);

        $ventes = (float) app(DashboardService::class)->totalSales('today');

        $this->assertEqualsWithDelta(
            50.00,
            $ventes,
            0.001,
            'Le service du soir va jusqu\'à 00h30 : une commande rattachée au jour métier '
            . 'précédent ne doit pas gonfler les ventes du jour. Le repli ne doit s\'appliquer '
            . 'que lorsque la date métier est ABSENTE, jamais l\'écraser.',
        );
    }
}
