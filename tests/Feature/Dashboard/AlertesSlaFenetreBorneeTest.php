<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-07 T-2.1.1 2026-08-27] Les alertes cuisine ont une fenêtre bornée des deux côtés.
 *
 * `slaAlerts()` n'avait qu'une borne : « en préparation depuis plus de 15 minutes ».
 * Toute commande jamais sortie de cet état, depuis le premier jour, restait donc une
 * alerte. Mesuré à l'écran avant correctif : **331 alertes**, dont un ticket « en
 * attente depuis 77 j 22 h ».
 *
 * Le défaut n'est pas cosmétique. Une alerte qui se déclenche 331 fois ne se déclenche
 * plus : le cuisinier apprend à ne plus la regarder, et la seule vraie urgence se noie.
 * Un compteur d'alertes n'a de valeur que s'il peut retomber à zéro.
 */
class AlertesSlaFenetreBorneeTest extends TestCase
{
    use RefreshDatabase;

    private function commandeEnPreparation(Carbon $depuis): Order
    {
        $filiale = Branch::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Filiale de test', 'email' => 't@example.test',
                'phone' => '+33600000000', 'city' => 'Lille',
                'state' => 'Hauts-de-France', 'zip_code' => '59000',
                'address' => '1 rue de Test', 'status' => 5,
            ]
        );

        $commande = Order::factory()->create([
            'branch_id' => $filiale->id,
            'status'    => OrderStatus::PREPARING,
        ]);

        // `updated_at` est géré par Eloquent : on le force sans repasser par le modèle.
        Order::query()->whereKey($commande->id)->update(['updated_at' => $depuis]);

        return $commande->fresh();
    }

    private function alertes(): array
    {
        return app(DashboardService::class)->slaAlerts()->all();
    }

    public function test_une_commande_en_retard_depuis_trente_minutes_est_une_alerte(): void
    {
        $this->commandeEnPreparation(Carbon::now()->subMinutes(30));

        $this->assertCount(1, $this->alertes(), 'Le cas nominal doit rester une alerte.');
    }

    public function test_une_commande_de_cinq_minutes_n_est_pas_encore_une_alerte(): void
    {
        $this->commandeEnPreparation(Carbon::now()->subMinutes(5));

        $this->assertCount(0, $this->alertes(), 'La borne haute des 15 minutes doit tenir.');
    }

    public function test_une_commande_figee_depuis_des_mois_n_est_plus_une_alerte(): void
    {
        // C'est le cas trouvé à l'écran : « en attente depuis 77 j 22 h ».
        $this->commandeEnPreparation(Carbon::now()->subDays(77));

        $this->assertCount(
            0,
            $this->alertes(),
            "Une commande abandonnée depuis des mois n'est pas une alerte de service : "
            . "c'est du ménage. La compter noie les vraies urgences."
        );
    }

    public function test_la_fenetre_est_bornee_des_deux_cotes(): void
    {
        $this->commandeEnPreparation(Carbon::now()->subMinutes(5));    // trop récente
        $this->commandeEnPreparation(Carbon::now()->subMinutes(45));   // dans la fenêtre
        $this->commandeEnPreparation(Carbon::now()->subHours(30));     // trop ancienne
        $this->commandeEnPreparation(Carbon::now()->subDays(77));      // très ancienne

        $this->assertCount(
            1,
            $this->alertes(),
            'Une seule des quatre est une vraie alerte de service.'
        );
    }

    public function test_une_masse_de_donnees_mortes_ne_noie_plus_la_seule_vraie_alerte(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->commandeEnPreparation(Carbon::now()->subDays(10 + $i));
        }
        $this->commandeEnPreparation(Carbon::now()->subMinutes(20));

        $alertes = $this->alertes();

        $this->assertCount(1, $alertes, 'Vingt commandes mortes ne doivent produire aucune alerte.');
    }

    public function test_la_plus_recente_arrive_en_premier(): void
    {
        $this->commandeEnPreparation(Carbon::now()->subHours(3));
        $recente = $this->commandeEnPreparation(Carbon::now()->subMinutes(20));

        $alertes = $this->alertes();

        $this->assertSame(
            $recente->order_serial_no,
            $alertes[0]['order_serial_no'],
            "C'est la commande de tout à l'heure qu'un cuisinier doit voir en premier."
        );
    }

    /**
     * [ONB-10 2026-08-27] La borne se disait réglable sans l'être.
     *
     * Les tests ci-dessus passaient tous avec une fenêtre codée en dur, parce qu'ils
     * n'exerçaient que la valeur par défaut (24 h). Le service lisait en réalité
     * `dashboard.sla.fenetre_heures`, une clé que `config/dashboard.php` ne définit
     * pas — il définit `dashboard.sla_alerts_window_hours`. Régler la configuration
     * n'avait donc aucun effet, et rien ne le disait.
     *
     * Ces deux tests exercent la clé elle-même : ils déplacent la borne et vérifient
     * que la même commande change de camp. Un mauvais nom de clé les fait échouer.
     */
    public function test_la_fenetre_est_reellement_pilotee_par_la_configuration(): void
    {
        $this->commandeEnPreparation(Carbon::now()->subHours(30));

        // Fenêtre par défaut (24 h) : une commande de 30 h est hors champ.
        $this->assertCount(0, $this->alertes());

        // Fenêtre élargie à 48 h : la même commande redevient une alerte.
        config(['dashboard.sla_alerts_window_hours' => 48]);

        $this->assertCount(
            1,
            $this->alertes(),
            "Élargir `dashboard.sla_alerts_window_hours` doit faire rentrer la commande\n"
            . "dans la fenêtre. Si ce test échoue, le service lit une autre clé que celle\n"
            . "que la configuration expose — la borne n'est réglable qu'en apparence."
        );
    }

    public function test_le_seuil_de_declenchement_est_reellement_pilote_par_la_configuration(): void
    {
        $this->commandeEnPreparation(Carbon::now()->subMinutes(10));

        // Seuil par défaut (15 min) : une commande de 10 min n'alerte pas encore.
        $this->assertCount(0, $this->alertes());

        // Seuil abaissé à 5 min : elle bascule.
        config(['dashboard.sla_alerts_threshold_minutes' => 5]);

        $this->assertCount(
            1,
            $this->alertes(),
            "Abaisser `dashboard.sla_alerts_threshold_minutes` doit déclencher plus tôt.\n"
            . "La durée d'un service est une décision d'exploitation, pas une constante\n"
            . "technique — encore faut-il que le service lise la clé."
        );
    }
}
