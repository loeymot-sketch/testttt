<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.1 · Codex P1-E/P1-G]
 *
 * Quatre points d'entrée datés — `order-statistics`, `order-summary`, `sales-summary`,
 * `customer-states` — décidaient chacun de leur fenêtre de dates, avec trois contrats
 * différents :
 *
 *  - `orderStatistics` passait par `resolveDayBoundaryParis()`, SANS aucun garde : une
 *    période inversée ou de dix ans y était acceptée, alors que les trois autres la
 *    refusaient en 422. Le même écran renvoyait donc deux réponses contradictoires pour
 *    les mêmes paramètres.
 *  - Les quatre repliaient EN SILENCE sur la période par défaut quand une seule des deux
 *    bornes était fournie : l'opérateur croyait lire mars, l'écran affichait le mois
 *    courant, et rien ne le disait.
 *  - `Carbon::parse('2026-02-31')` ne lève pas : il roule au 3 mars. Une date impossible
 *    donnait donc un résultat, silencieusement décalé.
 *
 * Ce banc fixe UN seul contrat pour les quatre : borne isolée, date impossible, période
 * inversée et période > 366 jours → 422 avec un message français ; nominal → 200.
 */
class DashboardDateContractMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Les quatre points d'entrée qui acceptent `first_date` / `last_date`. */
    private const POINTS = [
        'order-statistics',
        'order-summary',
        'sales-summary',
        'customer-states',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function lire(string $point, array $params = [])
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/dashboard/'.$point.($params ? '?'.http_build_query($params) : ''));
    }

    public function test_periode_nominale_repond_200_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-03-01', 'last_date' => '2026-03-31'])
                ->assertOk();
        }
    }

    public function test_periode_inversee_est_refusee_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $r = $this->lire($point, ['first_date' => '2026-03-31', 'last_date' => '2026-03-01']);
            $r->assertStatus(422);
            $this->assertStringContainsString(
                'date de fin',
                json_encode($r->json(), JSON_UNESCAPED_UNICODE),
                "{$point} : le refus doit être explicite et en français"
            );
        }
    }

    public function test_periode_de_plus_de_366_jours_est_refusee_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2020-01-01', 'last_date' => '2026-01-01'])
                ->assertStatus(422);
        }
    }

    /**
     * Le repli silencieux : une seule borne fournie renvoyait la période PAR DÉFAUT sans
     * le dire. L'opérateur lisait « mars » sur son écran et le mois courant dans les
     * chiffres. Une borne isolée est une demande incomplète, pas une demande par défaut.
     */
    public function test_une_borne_isolee_est_refusee_et_non_repliee_en_silence(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-03-01'])
                ->assertStatus(422);
            $this->lire($point, ['last_date' => '2026-03-31'])
                ->assertStatus(422);
        }
    }

    /**
     * `Carbon::parse('2026-02-31')` rend le 3 mars sans lever. Une date qui n'existe pas
     * doit être refusée, pas silencieusement déplacée de trois jours.
     */
    public function test_une_date_impossible_est_refusee_sur_les_quatre_points(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point, ['first_date' => '2026-02-31', 'last_date' => '2026-03-05'])
                ->assertStatus(422);
            $this->lire($point, ['first_date' => '2026-03-01', 'last_date' => 'hier'])
                ->assertStatus(422);
        }
    }

    /** Aucune date : chaque point garde sa période par défaut et répond 200. */
    public function test_sans_aucune_date_les_quatre_points_repondent_200(): void
    {
        foreach (self::POINTS as $point) {
            $this->lire($point)->assertOk();
        }
    }
}
